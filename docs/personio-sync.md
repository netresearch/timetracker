# Personio Sync — Operator Guide

TimeTracker exports each opted-in user's worklogs to Personio as daily **work attendances** (ADR-024 P1). Absence import (Personio → TT) is a later phase.

## How it works

- A worklog day is projected into **work periods**: the day's entries are merged into overlap-free intervals (one Personio `WORK` period per worked segment). The break Personio shows is the gap between those periods — TimeTracker never double-counts overlaps.
- TimeTracker only ever creates, updates, or deletes the attendance periods **it created itself** (tracked per user and day). Periods entered manually in Personio are never touched.
- A period already **confirmed** (approved) in Personio cannot be changed; if the TimeTracker data diverges afterwards, the day is parked as a conflict in the run report and left untouched — resolve it manually in Personio.
- Every Jira operation is under the company Personio API credential, but participation is a per-user decision: nobody is exported without opting in.

## Setup

1. **Personio API credential.** In Personio, create an API client (client id + secret) with permission to read/write attendances. Note the base URL (usually `https://api.personio.de`).
2. **Configure TimeTracker.** As an admin, open **Administration → Personio** and create the config: name, base URL, client id, client secret (stored encrypted; leave the secret field blank on later edits to keep the stored value), and the **absence project** (used by the later absence-import phase). Mark it active.
3. **Map employees.** Set each participating user's Personio employee id (`users.personio_employee_id`). For P1 this is manual; automatic matching via the Persons API arrives in P3.
4. **Users opt in.** Each user enables **Settings → "Meine Arbeitszeiten an Personio übertragen"**. Only opted-in, mapped users are exported.

## Running the export

```bash
# preview (no writes to Personio)
docker exec timetracker php bin/console tt:export-personio-attendances --dry-run

# a single user
docker exec timetracker php bin/console tt:export-personio-attendances --user=<username>

# a specific window (default: rolling last 14 days)
docker exec timetracker php bin/console tt:export-personio-attendances --from=2026-07-01 --to=2026-07-14
```

Schedule the default form on a cron (like `tt:sync-subtickets`); the 14-day rolling window plus the TT-owned-record bookkeeping makes re-runs idempotent — a day whose worklogs are unchanged is skipped, a changed day is patched, an emptied day's TT periods are removed.

Each run is recorded as a `SyncRun` (type `personio_export`, one per user) with per-day counters (`created`, `updated`, `in_sync`, `deleted`, `errors`) and items for anything parked (approved-attendance conflicts, errors). The `--dry-run` form reports `would_create` / `would_update` / `would_delete` without writing.

## Deactivating

Clear a user's opt-in in Settings, or set the Personio config inactive. Deactivating stops future exports; it does not remove attendances already sent to Personio.
