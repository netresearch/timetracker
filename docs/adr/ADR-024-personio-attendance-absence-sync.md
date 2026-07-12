# ADR-024: Personio Attendance Export and Absence Import

**Status:** Accepted — P1 (attendance export) implemented 2026-07-11; P2 (absence import) implemented 2026-07-12; P3 employee auto-match (CLI) + API/MCP run triggers implemented 2026-07-12; P3 remainder (frontend employee-match UI) pending.
**Date:** 2026-07-10
**Relates to:** [ADR-023](ADR-023-jira-worklog-bidirectional-sync.md) (the sync/run/audit infrastructure and the opt-in accountability model this ADR reuses), [ADR-021](ADR-021-api-token-authentication.md) (PAT scopes for any later API surface), [ADR-011](ADR-011-security-architecture.md) (encryption-at-rest via `TokenEncryptionService`).

## Context

TimeTracker is the company's central worklog hub (ADR-023). Personio is the HR system; German working-time law requires attendance records (start/end/breaks) there, and Personio owns the authoritative absence calendar (vacation, sick leave). Today both are disconnected from TT: attendances would have to be typed twice, and absences are invisible to TT's calendar and reporting.

Two flows close this:

1. **Attendance export (TT → Personio):** TT's granular worklogs already prove when someone worked; projecting them into Personio attendances eliminates duplicate time recording.
2. **Absence import (Personio → TT):** vacation/sick days from Personio become TT entries (the `Activity::SICK`/`Activity::HOLIDAY` activities already exist), completing TT's picture of everyone's time.

**API reality (verified 2026-07-10 against developer.personio.de):** the Personio API — v1 and v2 — authenticates exclusively with **company-level credentials** (`client_credentials` grant; "At the moment, only client_credentials grant type is supported"). There is no per-employee OAuth delegation. The per-user-token accountability model of the ADR-023 Jira sync is therefore not technically transferable; accountability must be established TT-side.

## Decision

### 1. Accountability: TT-side opt-in per user; execution under the company credential

- `users.personio_sync_enabled` (bool, default false) — one opt-in switch (Settings UI toggle) covering **both** directions ("TT and Personio exchange my time data"). Users who did not opt in are never exported and never receive imported absences.
- `users.personio_employee_id` (bigint, nullable) — the identity mapping to Personio's numeric employee id. Auto-matchable via the Personio Employees API (Personio e-mail localpart == TT username, the company's `firstname.lastname` scheme) through an admin action; manually correctable.
- Every run is a `SyncRun` (new `SyncRunType` values `personio_export` / `personio_import`) with per-user/day items — the existing audit surface (run history UI, list API) applies unchanged.

### 2. Configuration: admin-managed, encrypted at rest

New table `personio_configs` (`name`, `base_url`, `client_id`, `client_secret`, `absence_project_id` FK, `active`): one row in practice, managed through the existing generic admin CRUD with the same blank-secret-preservation pattern as ticket systems. `client_secret` is encrypted at rest via `TokenEncryptionService`. Personio is deliberately **not** a `TicketSystem` row — it is not a ticket system, and the worklog reconciliation semantics do not apply to it.

### 3. Attendance export: one block per day, TT-owned records only

`tt:export-personio-attendances [--from --to] [--dry-run]`, cron-driven, default rolling 14-day window. Per opted-in, mapped user and day:

- **Projection:** the day's entries (`findByDay`) are merged into overlap-free intervals; the attendance block is earliest start → latest end with `break` = the sum of the gaps. Overlaps never double-count. A day without entries projects to "no attendance".
- **Write rule (TT-owned records):** `personio_attendance_export` stores, per (user, day), the Personio attendance id TT created plus the last-sent projection. Unchanged → skip; new → `POST` and record the id; changed → `PATCH` **only the stored TT-owned id**; day emptied → `DELETE` the TT-owned record and clear the state. Attendances created manually in Personio are never touched.
- **Approved records / rejections:** Personio rejects changes to approved attendances — such rejections are parked as `sync_run_items` (divergence stays visible; nothing is overwritten). Per-day errors are isolated; the run continues. The HTTP client backs off on 429.

### 4. Absence import: contract-based day entries, cancellation-aware

`tt:import-personio-absences [--from --to]`, cron-driven, default window −30/+90 days (vacations lie in the future). Per opted-in, mapped user:

- **Activity mapping:** Personio time-off type name → `Activity` by name match ("krank" → Krank, "urlaub" → Urlaub); unknown types are parked as items — no guessing.
- **Entry shape:** one entry per absence day — start 08:00, duration = the user's contract hours for that weekday (`Contract.hours_0..6`; half-days = half; no contract → 8 h default plus a warning item), project = the configured **absence project** (`personio_configs.absence_project_id`) with its customer; no `EntryEvent` dispatch (no Jira echo); day-class recalculation as in the Jira import.
- **Idempotency & cancellation:** `personio_absence_import` maps each Personio absence id to the entries it created. Re-runs are no-ops; an absence cancelled/deleted in Personio deletes its TT entries — only if locally unchanged, else the case is parked as an item (ADR-023 pattern).

### 5. Architecture: Personio provider layer beside the Jira engine

New, Personio-specific units under `src/Service/Personio/`: `PersonioClient` (v2 OAuth client-credentials token handling + attendances/absences/employees endpoints), `AttendanceProjector` (day → block+break, pure and unit-testable), `AttendanceExportService`, `AbsenceImportService`, plus an `EmployeeMatcher` for the admin auto-match action. Reused from ADR-023 unchanged: `SyncRun`/`SyncRunItem` + `AbstractSyncRunService` (lifecycle, EM-closure hardening), `DayClassService`, the Settings/Admin/Command/UI patterns, the run-history surfaces. The Jira reconciliation engine is not touched — its worklog↔entry lease semantics do not fit day blocks or absences, and bending it would compromise both.

### 6. Phasing

- **P1 — Attendance export:** schema (`personio_configs`, users columns, `personio_attendance_export`), client (auth + attendances + employees), projector, export service, command, admin config CRUD, Settings opt-in toggle, docs.
- **P2 — Absence import:** `personio_absence_import`, absences endpoint, import service, command, activity/contract mapping.
- **P3 — polish:** admin auto-match action (Employees API), optional v2 API/MCP triggers (scopes `sync:read`/`sync:write`), operator guide integration.

Each phase is a separate plan → ultracode workflow → PR, per the established ADR-023 delivery pipeline.

## Alternatives considered

- **Personio as a `TicketSystemType`** inside the Jira sync engine: maximal nominal reuse, but the reconciliation matrix (worklog identity, lease on `updated`, field-scoped diffs) is semantically wrong for day blocks and absences; rejected.
- **Fully standalone module** with its own run/audit tables: clean separation but needless duplication of audit, opt-in, UI and command patterns; rejected.
- **ENV-based credentials:** simpler and DB-free, but rejected in review — admin-manageable configuration with encrypted secrets was preferred (consistent with how Jira systems are managed).
- **Segment-per-gap attendance blocks:** most precise projection but noisy (many records, sensitive to micro-gaps); one block + break sum matches the HR view and TT's own day classes.
- **Central/mandatory export (no opt-in):** arguably matches the employer's recording duty, but rejected for consistency with the ADR-023 accountability model — participation is an explicit, visible per-user decision.
- **Per-user Personio access (user's own credentials), user-triggered only** — re-investigated 2026-07-11: infeasible in every form and rejected. Personio's v2 token endpoint supports only the `client_credentials` grant — no `authorization_code`/consent flow, no employee- or personal-scoped API tokens (Personio explicitly disallows employee-level API credentials), and no server-holdable per-user web session (SSO/OIDC against the customer IdP, enforceable 2FA, and the GTC no-credential-sharing/no-reverse-engineering clauses; holding a user's unscoped Personio session is a GDPR Art. 9/33 blast-radius no feature justifies). "User-triggered only" relaxes *when* a credential fires, not *which* grant can exist, so it unlocks nothing. The company-credential + TT-side-accountability model (§1) stands. The single fact to re-verify before any future attempt is whether the v2 token endpoint still lists `client_credentials` as its only grant.

## Consequences

- Two new state tables + two `users` columns + `personio_configs`; two new `SyncRunType` values (additive enum change).
- Working-time data leaves TT toward the HR system — gated by explicit per-user opt-in; every transfer is auditable as a run with items.
- The export's correctness depends on the projection matching TT's day semantics (overlap merge, break = gaps) — the projector is pure and table-driven-testable.
- Approved Personio attendances create permanent, visible divergence items when TT data changes afterwards; resolving them is a manual HR conversation, by design.
- A second provider (after Jira) begins to shape a de-facto provider pattern over the ADR-023 infrastructure; a third integration should trigger extracting shared provider abstractions — deliberately not done now (YAGNI).

## Verification points before implementation

1. ~~Personio API v2 coverage of attendances vs v1~~ **Resolved (P1):** v2 `/v2/attendance-periods` provides full POST/GET/PATCH/DELETE. Approval state is a `status` field (`PENDING`/`CONFIRMED`/`REJECTED`); a write to a confirmed period is rejected and parked as a conflict item.
2. Exact shape of the time-off/absences payload (per-day breakdown, half-day flags) — **P2**, `/v2/absence-periods` + `/v2/absence-types` (endpoints confirmed present; payload shape to pin in P2).
3. ~~Whether attendance PATCH exists in v2~~ **Resolved (P1):** PATCH exists — no delete+recreate needed. **Refinement:** v2 has no break-minutes field; breaks are the gaps between `WORK`-type periods (Personio derives the break in its day view). So a day projects to **one WORK period per worked segment** and maps to a *set* of period ids; §3's write rule reconciles that set positionally. Same day-view outcome as "one block + break sum".
4. Employees API pagination + e-mail availability for auto-match — **P3.** `/v2/persons` is paginated; the list documentation does not confirm an email field, so P3's auto-match may need `firstname.lastname` name matching instead of email-localpart.
5. ~~Rate limits per endpoint~~ **Resolved (P1):** the auth endpoint is documented at 150 req/min; attendance endpoints are unspecified — `PersonioClient` backs off on HTTP 429 (honoring `Retry-After`, up to 3 attempts).
