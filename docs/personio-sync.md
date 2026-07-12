# Personio Sync — Operator Guide

TimeTracker exports each opted-in user's worklogs to Personio as daily **work attendances** (ADR-024 P1), and imports their Personio **absences** (vacation/sick) back as TimeTracker day entries (ADR-024 P2). The same per-user opt-in covers both directions.

## How it works

- A worklog day is projected into **work periods**: the day's entries are merged into overlap-free intervals (one Personio `WORK` period per worked segment). The break Personio shows is the gap between those periods — TimeTracker never double-counts overlaps.
- TimeTracker only ever creates, updates, or deletes the attendance periods **it created itself** (tracked per user and day). Periods entered manually in Personio are never touched.
- A period already **confirmed** (approved) in Personio cannot be changed; if the TimeTracker data diverges afterwards, the day is parked as a conflict in the run report and left untouched — resolve it manually in Personio.
- Every Jira operation is under the company Personio API credential, but participation is a per-user decision: nobody is exported without opting in.

## Setup

1. **Personio API credential.** In Personio, create an API client (client id + secret) with permission to read/write attendances. Note the base URL (usually `https://api.personio.de`).
2. **Configure TimeTracker.** As an admin, open **Administration → Personio** and create the config: name, base URL, client id, client secret (stored encrypted; leave the secret field blank on later edits to keep the stored value), and the **absence project** (used by the later absence-import phase). Mark it active.
3. **Map employees.** Each participating user needs their Personio employee id (`users.personio_employee_id`). Auto-match it from the Persons API:

   ```bash
   # preview the proposed matches
   docker exec timetracker php bin/console tt:match-personio-employees

   # write them
   docker exec timetracker php bin/console tt:match-personio-employees --apply
   ```

   Matching is by e-mail localpart or `firstname.lastname`; a username that matches zero or several Personio persons is skipped (never guessed) and stays for manual mapping. Re-runnable — it only ever looks at users without an id yet.
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

## Running the absence import

```bash
# a single user
docker exec timetracker php bin/console tt:import-personio-absences --user=<username>

# a specific window (default: 30 days back, 90 days ahead)
docker exec timetracker php bin/console tt:import-personio-absences --from=2026-07-01 --to=2026-09-30
```

Per opted-in, mapped user the import reads Personio's absence periods in the window and creates **one TimeTracker entry per working day**: start 08:00, duration = the user's contract hours for that weekday (a half-day boundary halves it; a non-working weekday gets no entry), on the configured **absence project**. The activity comes from the Personio absence type name — `urlaub` → Urlaub, `krank` → Krank; a type that matches neither, or an hourly (non-day) type, is **parked** as an `unresolved_absence_type` item rather than guessed. No Jira echo is dispatched for imported absences.

Re-runs are idempotent, tracked per Personio absence id: an unchanged absence is skipped, a changed one is rebuilt, and an absence cancelled in Personio has its entries removed — the rebuild and the removal happen **only while the TimeTracker entries are still the untouched imports**; a locally edited entry parks the case as a conflict instead. Each run is a `SyncRun` (type `personio_import`) with counters (`imported`, `updated`, `in_sync`, `cancelled`, `conflicts`, `unresolved_type`, `no_contract`).

Schedule the default form on the same cron as the export; the window is one-directional (vacations lie in the future), so it is wider ahead than behind.

## On-demand triggers (API / MCP)

Besides the console commands, a run can be started under a personal access token
(scope `sync:write`):

- **v2 API:** `POST /api/v2/personio/runs`. Body: `{"direction":"export"|"import","from":"YYYY-MM-DD","to":"YYYY-MM-DD","dry_run":true,"all_users":true}` (only `direction` is required). `export` pushes attendances, `import` pulls absences. By default the run covers the token owner; `all_users` runs for everyone opted-in and needs an admin token.
- **MCP tool:** `run_personio_sync` with the same `direction` and self/`allUsers` semantics.

The response carries the finished run(s) with counters and parked items.

## Deactivating

Clear a user's opt-in in Settings, or set the Personio config inactive. Deactivating stops future exports and imports; it does not remove attendances already sent to Personio, nor absence entries already imported into TimeTracker.
