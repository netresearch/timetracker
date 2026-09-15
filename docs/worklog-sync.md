# Jira Worklog Sync

TimeTracker keeps entries and Jira worklogs **bidirectionally in sync**: local edits are pushed to Jira with a lease check (saving an entry no longer silently overwrites a Jira-side edit), and worklogs added, edited, deleted, or moved directly in Jira flow back into TimeTracker. Sync is **opt-in and per-user**: every Jira operation runs under an accountable person's own token. See [ADR-023](adr/ADR-023-jira-worklog-bidirectional-sync.md) (and its 2026-07-10 amendment) for the design.

## Who gets synced, under whose token

There is no central "sync user" and no anonymous background account. For each worklog, the token that reads and writes it is chosen by three rules, in order:

1. **Author opted in** — the author enabled *Sync my Jira worklogs* for this ticket system (`users_ticket_systems.sync_enabled` on their own row) → synced under the **author's** token; the author is responsible.
2. **A PO opted into sync-all** — a project owner (ROLE_PL or ROLE_ADMIN) enabled *Sync all worklogs I can access* (`sync_all` on the PO's row) and their Jira token can see the worklog → synced under the **PO's** token; the PO is responsible.
3. **Otherwise** → not synced.

Writes are full bidirectional and **Jira-permission-gated**: what a token may do in Jira, TimeTracker lets it do — there is no extra permission gate beyond the opt-in. A lease failure still parks a conflict; a Jira-permission denial surfaces as an `error` item.

## What the cron does

`tt:sync-worklogs <ticket-system-id>` runs both opt-in passes for one ticket system:

1. **Self-sync** — for every user who opted their own worklogs in (`sync_enabled`, connected, non-empty token), sync that user's worklogs under their own token.
2. **PO sync-all** — for every project owner who opted into sync-all, read the PO-visible worklogs for the window, skip authors already covered by pass 1, map each remaining author to a TimeTracker or shadow user, and sync each under the **PO's** token.

Each synced target user becomes one `sync_run` (type `sync`, triggered by the token owner). For each target the ADR-023 reconciliation matrix runs over the window:

- entry changed, Jira unchanged → push (lease-checked),
- entry unchanged, Jira changed → pull the changed fields into the entry,
- both changed, different fields → merge (pull remote fields, push the result),
- both changed, same field → parked as **conflict**, no writes,
- deleted in Jira → the worklog is first read once from the issue its entry names, which the windowed search does not cover: a worklog re-dated outside the window still exists, and its new date is pulled into the entry instead of the entry being deleted. Only when Jira no longer has it there does it count as gone — then move detection first (a matching new worklog re-links the entry); otherwise a clean entry is deleted, a locally modified entry is parked as **orphaned**. A deletion or move is only concluded where the read could see the worklog: when an issue's worklogs could not be fetched or the issue search was capped (reported as `error`/`truncated` items), a missing worklog may sit on any unread issue — whatever issue key its entry still stores — so no entry of that run is deleted, parked or relinked; a worklog Jira returned but that could not be read blocks only the entry that holds it, and blocks the whole run where no local entry holds it at all — such a worklog is of unknown issue, start and duration, so it may be the one Jira created when a missing worklog was moved. Such entries count as `absence_unverified` and stay linked until a run reads them without that gap (a gap that persists, such as a worklog stored without a start, keeps its entry unverified). When the gap spans the run (a failed issue fetch or a capped search), a remote worklog that may be their move (same start and duration) is held back from the import rather than imported as a duplicate, and reported as a `remote_only` item naming every such entry, with the counter `lookalike_held`. A lookalike of a worklog that merely could not be read is imported as usual: that worklog still exists, so it has not moved,
- worklog with no matching entry → auto-imported when the ticket system has a default import activity, otherwise reported as `remote_only`; a worklog that already belongs to an entry outside this run counts as `already_linked` and is left alone.

Agent walltime entries (ADR-025 §7) are never pushed or reconciled — a Jira worklog is the human labour line. A worklog still linked to an agent entry (booked before that rule) counts as `agent_worklogs` (and in `errors`) and is reported as an `error` item naming the entry, so it can be removed in Jira. Re-attributing a synced entry to the agent removes its worklog on save and drops the entry's sync state. Resolving a parked state of an agent entry, with either winner, only drops the state (action `dropped_agent_state`): nothing is pushed and the entry is not deleted.

There is **no cursor**. Each run rescans a bounded date window; identity matching by worklog id makes re-runs idempotent, so overlapping windows are free and a failed run simply re-reads the same window.

## Opt in — via Settings

Activation is self-service; no admin has to configure a sync user.

- **Each user** opts in under **Settings → Jira worklog sync**: a *Sync my Jira worklogs* toggle per connected Jira ticket system. Their own worklogs are then read and written under their own Jira OAuth token ([ADR-017](adr/ADR-017-jira-cloud-oauth2.md)) on the next cron run.
- **A project owner** (ROLE_PL / ROLE_ADMIN) additionally sees *Sync all worklogs I can access in Jira*. Enabling it covers colleagues who don't use TimeTracker or haven't opted in — under the PO's token, limited to what the PO's Jira permissions expose. Shadow users are created for authors with no TimeTracker account so their imported time is booked to the right person.

A user must have a **connected Jira token** for a ticket system before the toggle does anything; a disconnected or token-less connection is skipped.

## Configure the ticket system

The only per-`ticket_systems` setting left is:

- **Default import activity** (`sync_default_activity_id`) — the activity assigned to auto-imported Jira-born worklogs. Optional: without it, unmatched worklogs surface as `remote_only` items and are never imported unattended.

Set it under **Administration → Ticket systems** (the admin save endpoint accepts `syncDefaultActivityId`; `null` clears it), or directly:

```sql
UPDATE ticket_systems SET sync_default_activity_id = 7 WHERE id = 1;
```

The former `sync_user_id` and `worklog_sync_cursor` columns are dropped — the sync-user admin field and the `--since` cursor are gone.

### Internal Jira mirror

A project can mirror its tickets into a second, internal Jira (`internal_jira_project_key` plus `internal_jira_ticket_system` on the project). Saving an entry on such a project rewrites it to the mirror issue, keeps the external key in `internal_jira_ticket_original_key`, and books the worklog in the **internal** Jira — while the project itself still points at the external ticket system.

Sync knows about those entries only in one direction. They are excluded from sync candidates, so nothing is ever pushed or reconciled against the wrong Jira. But the lookup that recognises a remote worklog as already belonging to an entry is scoped by the project's ticket system as well, so a run against the **internal** ticket system does not find their entries: their worklogs are reported as `remote_only` import candidates, and a configured default import activity would import them as new entries — duplicating time that TimeTracker already holds.

Until a worklog records which ticket system it belongs to (a schema change, see [ADR-023 §7](adr/ADR-023-jira-worklog-bidirectional-sync.md)), the rule is: **leave `sync_default_activity_id` unset on a ticket system that serves as an internal mirror**, and treat `remote_only` items from such a run as already-booked time rather than as imports.

## Running it

Preview a window with `--dry-run` first — counters and parked items are reported, nothing is written:

```bash
docker compose exec app php bin/console tt:sync-worklogs 1 --from=2026-07-01 --to=2026-07-31 --dry-run
```

Without `--from`/`--to` the window is a **rolling 30 days** (`--from` = 30 days ago, `--to` = today). When the preview looks right, run it without `--dry-run`:

```bash
docker compose exec app php bin/console tt:sync-worklogs 1
```

Options: `--from=Y-m-d`, `--to=Y-m-d`, `--dry-run`. There is no `--since` and no `--user` — the cron always runs the opt-in users plus PO coverage. The command exits non-zero when any run FAILED, and prints a note (and exits 0) when nobody opted in and no PO opted into sync-all for this ticket system.

## Keep it fresh with cron

There is **no in-app polling** — schedule the CLI command per ticket system.

**Host crontab** (runs the command inside the running `app` container every 15 minutes for ticket system 1):

```cron
*/15 * * * * cd /srv/timetracker && docker compose exec -T app php bin/console tt:sync-worklogs 1 >> /var/log/tt-worklog-sync.log 2>&1
```

**systemd timer** (alternative): a `tt-worklog-sync.service` running the same `docker compose exec -T app php bin/console tt:sync-worklogs 1`, plus a `tt-worklog-sync.timer` with `OnCalendar=*:0/15`.

Each target's issue-key search reads pages of up to 500 issues and stops after 100 pages (at most 50,000 issues, `JiraOAuthApiService::MAX_SEARCH_PAGES`); when more match, a `truncated` item is reported and the next run's rescanned window picks up the remainder (idempotency makes that free), so short cadences are safe.

## What gets parked, and where to see it

True conflicts are never auto-resolved — they are parked for a human. Every run is persisted as a `sync_run` row with counters, and noteworthy outcomes become `sync_run_item` rows; the command prints both at the end of each run. Parked kinds:

| Item kind | Meaning |
|---|---|
| `conflict` | Same field edited on both sides. No writes; the remote version is snapshotted on the entry's `worklog_sync_state` row (status `conflict`). |
| `local_only` | Remote worklog deleted: a clean entry was removed, a locally modified entry was parked (sync state status `orphaned`). |
| `remote_only` | Jira-born worklog not imported because no default import activity is configured — or held back because it may be the move of an entry whose own worklog could not be verified in this run (counter `lookalike_held`; `payload.entries` names the entries; look for the `error`/`truncated` item of the same run). |
| `diverged` | Linked pair differs but has no sync base to diff against; resolve it via the UI or the conflicts API. |
| `unresolved_project` / `probable_duplicate` | Import parked the worklog for a human (see ADR-023 §2). |
| `shadow_user_created` | A PO sync-all run created a placeholder user for a Jira author with no TimeTracker account. |
| `error` | Item-level failure (e.g. unresolvable issue id, or a Jira-permission denial on a write), or a Jira worklog still booked for an agent walltime entry; the run continues. |
| `truncated` | Issue-search cap hit; remaining changes come with the next run. |

Inspect parked items via the command output, the [UI](#ui), the [API & MCP surfaces](#api--mcp) below, or the `sync_run` / `sync_run_item` and `worklog_sync_state` tables.

## UI

The same engine is driven from the SolidJS UI:

- **Settings → Jira worklog sync** (self-service): the opt-in toggles above — *Sync my Jira worklogs* per connected ticket system, plus *Sync all worklogs I can access* for project owners.
- **Settings → Jira-Zeiten importieren** (self-service): any user imports their own Jira worklogs for a date range with an optional default activity — a dry-run **Preview** first, then **Execute**. Scoped to the signed-in user.
- **Administration → Worklog sync** (`/ui/admin/worklog-sync`, ROLE_ADMIN): trigger `verify` / `import` / `sync` runs (for a single named target on `sync`), browse the run history with per-run counters and items, and resolve parked conflicts side by side (keep local or keep remote).
- **Administration → Ticket systems**: set the per-ticket-system **default import activity** that auto-import uses.

## API & MCP

The engine is also exposed over the v2 REST API and as MCP tools (ADR-023 §6). PAT tokens ([ADR-021](adr/ADR-021-api-token-authentication.md)) need the `sync:read` / `sync:write` scopes; session-authenticated users bypass scopes but not the role/ownership checks. Runs execute inline — the response carries the finished run with its counters and noteworthy items.

### v2 REST endpoints

| Endpoint | Scope | Purpose |
|---|---|---|
| `POST /api/v2/worklog-sync/runs` | `sync:write` | Start a run. Body: `{"type": "verify"\|"import"\|"sync", "ticket_system_id": N, "from"?, "to"?, "users"?: [...], "default_activity_id"?, "dry_run"?}`. Date range defaults to the current month. `verify` compares only; `import` needs `default_activity_id`; `sync` syncs a **single target** — yourself by default, or the user named in `users[0]` (a PL/admin caller only, acting under their own token). Non-admins may `verify` themselves, `import` only their own username, and `sync` only themselves. Answers `201` with the run body. |
| `GET /api/v2/worklog-sync/runs/{id}` | `sync:read` | One run with status, counters, and per-worklog findings. Non-admins see only runs they triggered. |
| `GET /api/v2/worklog-sync/preferences` | `sync:read` | The caller's own opt-in flags per connected Jira ticket system: `{"preferences": [{"ticket_system_id": N, "ticket_system_name": "...", "sync_enabled": bool, "sync_all": bool}], "can_sync_all": bool}`. `can_sync_all` tells the UI whether to offer the PO sync-all toggle. |
| `PUT /api/v2/worklog-sync/preferences` | `sync:write` | Set the caller's **own** flags for one ticket system. Body: `{"ticket_system_id": N, "sync_enabled": bool, "sync_all"?: bool}`. `sync_all` is accepted only from a PL/admin caller (else `403`); self only. Answers the updated preference. |
| `GET /api/v2/worklog-sync/conflicts` | `sync:read` | Parked conflicts/orphans awaiting resolution: `{"conflicts": [...], "count": N}`. Non-admins see only their own entries; admins see all and may filter with `?user=<username>`. |
| `POST /api/v2/worklog-sync/conflicts/{id}/resolve` | `sync:write` | Body `{"winner": "local"\|"remote"}` — local force-pushes the entry to Jira (recreating the worklog for orphans), remote pulls the live Jira worklog (or, for orphans, accepts the deletion by removing the entry). For an agent walltime entry either winner only drops the parked state (`dropped_agent_state`). Owner or admin only; answers `{"resolved": true, "action": ..., "conflict_id": N}`, or `422` with the reason when the resolution could not be applied. |

### MCP tools

The same operations for agents, guarded by the same scopes and authorization matrix:

| Tool | Scope | Purpose |
|---|---|---|
| `sync_jira_worklogs(type, ticketSystemId, from?, to?, users?, defaultActivityId?, dryRun?)` | `sync:write` | Trigger a verify / import / sync run; returns the finished run. `sync` targets a single user — yourself by default, or `users[0]` (PL/admin only). |
| `get_sync_run(runId)` | `sync:read` | Inspect a run's counters and findings. |
| `list_sync_conflicts(user?)` | `sync:read` | List parked conflicts/orphans (`user` filter is admin-only). |
| `resolve_sync_conflict(conflictId, winner)` | `sync:write` | Resolve one parked conflict; failures raise a tool error with the reason. |

### Examples

Opt yourself into the nightly sync for ticket system 1:

```bash
curl -s -X PUT https://timetracker.example.org/api/v2/worklog-sync/preferences \
  -H "Authorization: Bearer tt_pat_..." \
  -H "Content-Type: application/json" \
  -d '{"ticket_system_id": 1, "sync_enabled": true}'
```

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

The sync is dormant until someone opts in, so it changes nothing until activated. To stop it:

- **A single user** turns off *Sync my Jira worklogs* (and a PO turns off *Sync all worklogs I can access*) in Settings — the next cron run skips them.
- **Everything** stops when you remove the cron entry / timer; the last run leaves the data as-is.

This only affects the cron pull/reconcile; lease-checked pushes on the normal entry save path are independent of it.

## Troubleshooting

| Symptom | Cause |
|---|---|
| `Nothing to sync: no user opted in …` | Nobody enabled *Sync my Jira worklogs* and no PO enabled *Sync all worklogs I can access* for this ticket system. |
| A user's worklogs are not synced | They haven't opted in, and no sync-all PO can see them; or their Jira connection has no token / is disconnected. |
| Worklogs added in Jira show up as `remote_only` instead of entries | No `sync_default_activity_id` configured on the ticket system — or, with counter `lookalike_held`, the worklog was held back because an `error`/`truncated` item in the same run left an entry's own worklog unverified (`payload.entries` names it); it is imported by the first run without that gap. |
| Entries whose worklog was deleted in Jira are neither removed nor parked (counter `absence_unverified`) | The run has an `error` or `truncated` item, so the deletion could not be verified; it clears on the first run without that gap. A per-worklog `error` (e.g. a worklog stored without a start) keeps that one entry unverified until the worklog is fixed in Jira — and keeps the whole run unverified while no local entry is linked to that worklog id, because it could then be a moved worklog. |
| A run against an internal mirror Jira reports known time as `remote_only` (or imports it twice) | The entries behind those worklogs belong to projects pointing at the external ticket system, so the run cannot match them — see [Internal Jira mirror](#internal-jira-mirror). Clear `sync_default_activity_id` on that ticket system and delete the duplicate entries. |
| An entry disappeared after its worklog was moved to another month in Jira | Fixed: the run now reads such a worklog from its own issue and pulls the new date. On a version before that fix the entry was deleted as if the worklog were gone — restore it from the `sync_run_item` payload of the run that reports `deleted_local`. |
| A PO's *Sync all* toggle is missing | The account lacks ROLE_PL / ROLE_ADMIN (`can_sync_all` is false). |
| `truncated` items keep appearing | More than 100 search pages (up to 50,000 matching issues) in the window — the next run picks up the remainder; shorten the window or the cadence. |
| A run fails with a token error | The responsible user's OAuth token expired — they re-authorize via the OAuth flow. |
