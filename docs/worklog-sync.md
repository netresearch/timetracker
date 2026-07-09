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

The admin-form pickers for these fields arrive with the Phase 4 UI; until then set the columns directly, e.g.:

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

Until the Phase 4 conflict UI/API lands, inspect parked items via the command output or the `sync_run` / `sync_run_item` and `worklog_sync_state` tables.

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
