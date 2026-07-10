# Jira Worklog Sync

TimeTracker keeps entries and Jira worklogs **bidirectionally in sync**: local edits are pushed to Jira with a lease check (saving an entry no longer silently overwrites a Jira-side edit), and worklogs added, edited, deleted, or moved directly in Jira flow back into TimeTracker. See [ADR-023](adr/ADR-023-jira-worklog-bidirectional-sync.md) for the design.

## What it does

`tt:sync-worklogs` reads Jira's `worklog/updated` and `worklog/deleted` feeds from the stored per-ticket-system cursor and runs the ADR-023 reconciliation matrix for each changed worklog:

- entry changed, Jira unchanged → push (lease-checked),
- entry unchanged, Jira changed → pull the changed fields into the entry,
- both changed, different fields → merge (pull remote fields, push the result),
- both changed, same field → parked as **conflict**, no writes,
- deleted in Jira → move detection first (a matching new worklog re-links the entry); otherwise a clean entry is deleted, a locally modified entry is parked as **orphaned**,
- worklog with no matching entry → auto-imported when a default import activity is configured, otherwise reported as `remote_only`.

The cursor advances only after a completed non-dry run, so a failed run re-reads the same window; identity matching by worklog id makes re-runs idempotent.

## Configure a ticket system

Ongoing cron sync needs two settings on the `ticket_systems` row:

1. **Sync user** (`sync_user_id`) — a TT user with a connected Jira OAuth token ([ADR-017](adr/ADR-017-jira-cloud-oauth2.md)) and broad Jira browse permission (plus "Edit All Worklogs" for fallback writes). The feeds are read with this user's token; writes prefer the entry owner's token and fall back to the sync user.
2. **Default import activity** (`sync_default_activity_id`) — the activity assigned to auto-imported Jira-born worklogs. Optional: without it, unmatched worklogs surface as `remote_only` items and are never imported unattended.

The ticket-system admin save endpoint accepts both fields (`syncUserId`, `syncDefaultActivityId`; `null` clears them); the admin-form pickers arrive with the Phase 4b UI. Alternatively set the columns directly, e.g.:

```sql
UPDATE ticket_systems SET sync_user_id = 42, sync_default_activity_id = 7 WHERE id = 1;
```

## First run

The cursor (`worklog_sync_cursor`, epoch milliseconds) starts empty, so the first run must be given a starting point with `--since` (accepts `Y-m-d` or raw epoch milliseconds). Preview it with `--dry-run` first — counters and parked items are reported, nothing is written, and the cursor is not advanced:

```bash
docker compose exec app php bin/console tt:sync-worklogs 1 --since=2026-07-01 --dry-run
```

When the preview looks right, run it without `--dry-run`. Every later run continues from the stored cursor automatically (with a small overlap window, which idempotency makes free).

## Keep it fresh with cron

There is **no in-app polling** — schedule the CLI command per ticket system.

**Host crontab** (runs the command inside the running `app` container every 15 minutes for ticket system 1):

```cron
*/15 * * * * cd /srv/timetracker && docker compose exec -T app php bin/console tt:sync-worklogs 1 >> /var/log/tt-worklog-sync.log 2>&1
```

**systemd timer** (alternative): a `tt-worklog-sync.service` running the same `docker compose exec -T app php bin/console tt:sync-worklogs 1`, plus a `tt-worklog-sync.timer` with `OnCalendar=*:0/15`.

A run reads at most 20 feed pages; when the cap is hit a `truncated` item is reported and the remainder is picked up by the next run, so short cadences are safe. The command exits non-zero when the run did not complete.

## What gets parked, and where to see it

True conflicts are never auto-resolved — they are parked for a human. Every run is persisted as a `sync_run` row with counters, and noteworthy outcomes become `sync_run_item` rows; the command prints both at the end of each run. Parked kinds:

| Item kind | Meaning |
|---|---|
| `conflict` | Same field edited on both sides. No writes; the remote version is snapshotted on the entry's `worklog_sync_state` row (status `conflict`). |
| `local_only` | Remote worklog deleted: a clean entry was removed, a locally modified entry was parked (sync state status `orphaned`). |
| `remote_only` | Jira-born worklog not imported because no default import activity is configured. |
| `diverged` | Linked pair differs but has no sync base to diff against; resolution is a Phase 4 surface. |
| `unresolved_project` / `probable_duplicate` | Import parked the worklog for a human (see ADR-023 §2). |
| `error` | Item-level failure (e.g. unresolvable issue id); the run continues. |
| `truncated` | Feed page cap hit; remaining changes come with the next run. |

Inspect parked items via the command output, the [UI](#ui), the [API & MCP surfaces](#api--mcp) below, or the `sync_run` / `sync_run_item` and `worklog_sync_state` tables.

## UI

The same engine is driven from the SolidJS UI:

- **Settings → Jira-Zeiten importieren** (self-service): any user imports their own Jira worklogs for a date range with an optional default activity — a dry-run **Preview** first, then **Execute**. Scoped to the signed-in user.
- **Administration → Worklog sync** (`/ui/admin/worklog-sync`, ROLE_ADMIN): trigger `verify` / `import` / `sync` runs (optionally for named users), browse the run history with per-run counters and items, and resolve parked conflicts side by side (keep local or keep remote).
- **Administration → Ticket systems**: set the per-ticket-system **sync user** and **default import activity** that the cron pull uses.

## API & MCP

The engine is also exposed over the v2 REST API and as MCP tools (ADR-023 §6). PAT tokens ([ADR-021](adr/ADR-021-api-token-authentication.md)) need the `sync:read` / `sync:write` scopes; session-authenticated users bypass scopes but not the role/ownership checks. Runs execute inline — the response carries the finished run with its counters and noteworthy items.

### v2 REST endpoints

| Endpoint | Scope | Purpose |
|---|---|---|
| `POST /api/v2/worklog-sync/runs` | `sync:write` | Start a run. Body: `{"type": "verify"\|"import"\|"sync", "ticket_system_id": N, "from"?, "to"?, "users"?: [...], "default_activity_id"?, "dry_run"?, "since"?}`. Date range defaults to the current month; `since` (sync only) overrides the cursor. Non-admins may `verify` for themselves and `import` only with `users` set to exactly their own username; `sync` requires an admin. Answers `201` with the run body. |
| `GET /api/v2/worklog-sync/runs/{id}` | `sync:read` | One run with status, counters, and per-worklog findings (the import report). Non-admins see only runs they triggered. |
| `GET /api/v2/worklog-sync/conflicts` | `sync:read` | Parked conflicts/orphans awaiting resolution: `{"conflicts": [...], "count": N}`. Non-admins see only their own entries; admins see all and may filter with `?user=<username>`. |
| `POST /api/v2/worklog-sync/conflicts/{id}/resolve` | `sync:write` | Body `{"winner": "local"\|"remote"}` — local force-pushes the entry to Jira (recreating the worklog for orphans), remote pulls the live Jira worklog (or, for orphans, accepts the deletion by removing the entry). Owner or admin only; answers `{"resolved": true, "action": ..., "conflict_id": N}`, or `422` with the reason when the resolution could not be applied. |

### MCP tools

The same operations for agents, guarded by the same scopes and authorization matrix:

| Tool | Scope | Purpose |
|---|---|---|
| `sync_jira_worklogs(type, ticketSystemId, from?, to?, users?, defaultActivityId?, dryRun?, since?)` | `sync:write` | Trigger a verify / import / sync run; returns the finished run. |
| `get_sync_run(runId)` | `sync:read` | Inspect a run's counters and findings. |
| `list_sync_conflicts(user?)` | `sync:read` | List parked conflicts/orphans (`user` filter is admin-only). |
| `resolve_sync_conflict(conflictId, winner)` | `sync:write` | Resolve one parked conflict; failures raise a tool error with the reason. |

### Examples

Start a verify run for July (read-only comparison, nothing is written):

```bash
curl -s -X POST https://timetracker.example.org/api/v2/worklog-sync/runs \
  -H "Authorization: Bearer tt_pat_..." \
  -H "Content-Type: application/json" \
  -d '{"type": "verify", "ticket_system_id": 1, "from": "2026-07-01", "to": "2026-07-31"}'
```

List parked conflicts, then resolve one in favor of the Jira side:

```bash
curl -s https://timetracker.example.org/api/v2/worklog-sync/conflicts \
  -H "Authorization: Bearer tt_pat_..."

curl -s -X POST https://timetracker.example.org/api/v2/worklog-sync/conflicts/42/resolve \
  -H "Authorization: Bearer tt_pat_..." \
  -H "Content-Type: application/json" \
  -d '{"winner": "remote"}'
```

## Rollback / disable

Clear the ticket system's sync user (`UPDATE ticket_systems SET sync_user_id = NULL WHERE id = 1;`) — the next cron run fails cleanly before doing any work. This only stops the cron pull/reconcile; lease-checked pushes on the normal entry save path are independent of it.

## Troubleshooting

| Symptom | Cause |
|---|---|
| `No sync user configured for ticket system N` | Set `sync_user_id` on the ticket system. |
| `No cursor yet; pass --since for the first run` | First run for this ticket system — provide `--since`. |
| Worklogs added in Jira show up as `remote_only` instead of entries | No `sync_default_activity_id` configured. |
| Nothing pulled although Jira changed | Cursor already past the edit (check `worklog_sync_cursor`) — override once with `--since`. |
| Run fails with a token error | The sync user's OAuth token expired — re-authorize via the OAuth flow. |
