# Jira Worklog Sync — Opt-In Per-User Sync Rework Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the feed-based, central-sync-user cron model with an **opt-in, per-user sync** where every Jira operation happens under an accountable person's own token:
- a normal user **opts in** (a per-ticket-system setting) to have *their own* worklogs synced under *their own* token;
- a **PO** (ROLE_PL/ROLE_ADMIN) can additionally opt in to "sync all worklogs I can access", covering Jira-only / non-TT / opted-out authors under the *PO's* token (creating shadow users as needed).
Writes are full bidirectional; the access control is Jira's own permission model — what a token may do in Jira, TT lets it do.

**Why (governance):** ADR-023 §5 chose a central "sync user" whose token read the instance-wide feed and served as a write fallback. That obscures responsibility. The reworked model makes responsibility explicit and self-service: a worklog is synced under its author's token when the author opted in; otherwise under a PO's token when a PO opted into sync-all and can access it; otherwise not synced. No anonymous background account, no acting on someone's behalf without an explicit, accountable opt-in.

**Scope:** the sync engine is deployed to prod but **dormant** (no cron, nothing opted in), so this changes nothing live. It reworks Phase 3's `SyncWorklogsService`, `tt:sync-worklogs`, the Phase 4a v2 sync endpoint + MCP tool, adds per-user opt-in settings (schema + a Settings UI), drops the now-superseded `ticket_systems.sync_user_id` + `worklog_sync_cursor` columns and the admin sync-user field, and removes the dead feed read methods. `sync_default_activity_id` stays (default activity for imported worklogs).

**Token / responsibility rules (the heart of this rework):**
1. Author opted in (`users_ticket_systems.sync_enabled` on their own row) → synced under the **author's** token; author is responsible.
2. Else a PO opted into sync-all (`sync_all` on the PO's row, PO is ROLE_PL/ADMIN) and the PO's Jira token can see the worklog → synced under the **PO's** token; PO is responsible.
3. Else → not synced.

**Tech stack:** PHP 8.5, Symfony 8, Doctrine ORM 3 + Migrations, PHPUnit 13, PHPStan level 10, Rector; SolidJS/bun for the Settings UI.

## Global Constraints

- License header + `declare(strict_types=1);` on new PHP files.
- Container commands via `docker compose --profile dev exec app-dev`. Backend gates before each commit, all four: `composer analyze && composer analyze:arch && composer rector && composer cs-fix`, plus relevant tests.
- **Backend functional/integration tests hit the `.env.test.local` dev-DB trap** — run with `docker compose --profile dev exec -T -e 'DATABASE_URL=mysql://unittest:unittest@db_unittest:3306/unittest?serverVersion=mariadb-12.1.2&charset=utf8mb4' app-dev php bin/phpunit <path>`. Pure unit tests (`tests/Service/Sync/*` without DB) are unaffected.
- Frontend gates inside `frontend/`: `bun run typecheck && bun run test <file> && bun run lint`. Every new user string is a Paraglide `m.*()` key in ALL FIVE `messages/{en,de,es,fr,ru}.json` (identical key sets, natural German). No `useMutation` (write = `postJson` + `invalidateQueries`), no Tailwind utilities, axe-clean, WCAG 2.2 AA.
- Commits conventional, `-S --signoff`, no AI attribution. NEVER stage `config/reference.php`.
- SonarCloud: complexity ≤15/method, params ≤7, no copy-paste blocks (share the per-user read via `RemoteWorklogReader`).
- Writes only under the responsible token (author's or the PO's per the rules); TT adds no extra permission gate beyond opt-in — Jira enforces access. A lease failure still parks a conflict; a Jira-permission denial surfaces as an error item.
- Reuse unchanged: `ReconciliationService`, `WorklogWriteService`, `EntryPullApplier`, `EntryWorklogProjector`, `RemoteWorklogNormalizer`, `WorklogSyncStateRepository`, `ImportWorklogsService::processWorklog`, `JiraAuthorMapper`, `DayClassService`, `AbstractSyncRunService`.

---

### Task 1: ADR amendment (design anchor)

**Files:** Modify `docs/adr/ADR-023-jira-worklog-bidirectional-sync.md`.

- [ ] **Step 1:** In §5, add a dated amendment replacing the central-sync-user token model with the opt-in model above (the three token/responsibility rules verbatim; note writes are Jira-permission-gated; note the schema change: `users_ticket_systems.sync_enabled` + `.sync_all` replace `ticket_systems.sync_user_id` + `worklog_sync_cursor`, which are dropped; `sync_default_activity_id` kept; the incremental cursor is dropped for a rescanned date window, idempotent via worklog id).
- [ ] **Step 2:** Commit — `docs(adr): ADR-023 amendment — opt-in per-user sync replaces central sync user`.

---

### Task 2: Schema — opt-in flags, drop superseded columns

**Files:**
- Create: `migrations/Version20260710_WorklogSyncOptIn.php`
- Modify: `src/Entity/UserTicketsystem.php` (add `syncEnabled`, `syncAll` + accessors), `src/Entity/TicketSystem.php` (remove `syncUser` + `worklogSyncCursor` properties/accessors; keep `syncDefaultActivity`)
- Modify: `sql/full.sql` (mirror the column add/drop)
- Create/modify: `src/Repository/UserTicketsystemRepository.php`
- Test: `tests/Entity/UserTicketsystemTest.php` (accessors), `tests/Repository/WorklogSyncOptInQueriesTest.php` (integration)

**Interfaces (Produces):**
- `UserTicketsystem`: `getSyncEnabled(): bool` / `setSyncEnabled(bool): static`; `getSyncAll(): bool` / `setSyncAll(bool): static` (columns `sync_enabled`, `sync_all`, bool default 0).
- `TicketSystem`: `getSyncUser`/`setSyncUser`/`getWorklogSyncCursor`/`setWorklogSyncCursor` **removed**; `getSyncDefaultActivity`/`setSyncDefaultActivity` kept.
- `UserTicketsystemRepository::findSyncEnabled(TicketSystem $ticketSystem): array` → `list<UserTicketsystem>` (rows with `sync_enabled = true`, non-empty token, `avoidConnection = false`); `findSyncAllOwners(TicketSystem $ticketSystem): array` → `list<UserTicketsystem>` (rows with `sync_all = true`, non-empty token, `avoidConnection = false`; the runtime additionally checks the user is ROLE_PL/ADMIN).
- Migration `up`: `ALTER TABLE users_ticket_systems ADD sync_enabled TINYINT(1) NOT NULL DEFAULT 0, ADD sync_all TINYINT(1) NOT NULL DEFAULT 0`; drop the `ticket_systems` FK `fk_ts_sync_user` then `DROP COLUMN sync_user_id, DROP COLUMN worklog_sync_cursor`. `down` reverses. Mirror in `sql/full.sql`.

- [ ] **Step 1: Failing tests** — entity accessor test + integration test seeding rows (one sync_enabled, one sync_all PL user, one plain) asserting the two finders.
- [ ] **Step 2: Implement** entity changes, migration, repo, full.sql. Run migrate + `doctrine:schema:validate` (mapping validates; if the schema tool wants to drop an index we added, map it). **Grep for any remaining references to `getSyncUser`/`setSyncUser`/`WorklogSyncCursor`** across `src/` and fix (the current `SyncWorklogsService` + `SyncRunRequestMapper` + admin DTO reference them — those get reworked in later tasks, but this task must leave the tree compiling; temporarily guard or stub is NOT allowed — instead do this task AFTER confirming the later tasks' order, or keep the getters until Task 4/6 remove their callers). **Ordering note:** because `TicketSystem::getSyncUser` has callers, KEEP the `TicketSystem` accessor removal for the END — in this task only ADD the two new columns/accessors + finders + migration for the ADD; do the `ticket_systems` column DROP + accessor removal in Task 7 once all callers are gone. (So split: Task 2 = add opt-in flags; Task 7 = drop superseded columns.)
- [ ] **Step 3: Pass, gates, commit** — `feat(sync): per-user sync opt-in flags on user ticket systems (ADR-023 amendment)`.

---

### Task 3: RemoteWorklogReader — shared per-user/per-author read

**Files:**
- Create: `src/Service/Sync/RemoteWorklogReader.php`
- Refactor: `src/Service/Sync/VerifyWorklogsService.php` to use it
- Test: `tests/Service/Sync/RemoteWorklogReaderTest.php`; keep `VerifyWorklogsServiceTest` green

**Interfaces (Produces):**
- `RemoteWorklogReader::readForAuthor(JiraOAuthApiService $api, callable $matchesAuthor, string $jql, DateTimeImmutable $from, DateTimeImmutable $to, callable $onNotice): array` → `array<int, array{snapshot, updated, author, issueKey}>` keyed by worklog id. Generalizes verify's remote-collection loop: run `searchIssueKeysWithWorklogs($jql)` (truncation → `$onNotice('truncated')`), per issue `getIssueWorklogs` (failure → `$onNotice('error', issueKey, throwable)`), keep worklogs where `id !== null && $matchesAuthor($worklog)` and started in range, normalize (InvalidArgumentException → `$onNotice('error', ...)`).
  - Self read: `$jql = 'worklogAuthor = currentUser() AND worklogDate >= "…" AND <= "…"'`, `$matchesAuthor = fn($w) => $myself->matchesWorklogAuthor($w)`.
  - PO read of one target author: `$jql = 'worklogAuthor = "<targetRemoteId>" AND worklogDate …'`, `$matchesAuthor = fn($w) => $targetIdentity->matchesWorklogAuthor($w)`.
  - PO broad read (all accessible): `$jql = 'worklogDate >= "…" AND <= "…"'`, `$matchesAuthor` = a predicate excluding self-sync-enabled authors (caller supplies the set).

- [ ] **Step 1: Failing test** — canned api; assert keyed map, author filtering via the predicate, range filter, and `$onNotice` for truncation/unparseable.
- [ ] **Step 2: Implement**; refactor `VerifyWorklogsService::run` to delegate its remote half to `readForAuthor` (self variant). `VerifyWorklogsServiceTest` stays green.
- [ ] **Step 3: Pass, gates, commit** — `refactor(sync): shared remote worklog reader for per-user/per-author reads`.

---

### Task 4: SyncWorklogsService → opt-in per-user + PO

**Files:**
- Rewrite: `src/Service/Sync/SyncWorklogsService.php` (drop feed/cursor; drop `SyncRunContext` if unused)
- Test: rewrite `tests/Service/Sync/SyncWorklogsServiceTest.php`

**Interfaces (Produces):**
- `syncUser(User $targetUser, User $tokenOwner, TicketSystem $ticketSystem, DateTimeImmutable $from, DateTimeImmutable $to, bool $dryRun = false): SyncRun` — the accountable unit. One `SyncRun` (type `SYNC`, `triggeredBy = $tokenOwner`, scope `{from,to,dry_run,target: <targetUser username>}`). API = `jiraOAuthApiFactory->create($tokenOwner, $ticketSystem)`. Reads the target's worklogs via `RemoteWorklogReader` (self JQL when `tokenOwner === targetUser`, else per-author JQL filtered by the target's `JiraUserIdentity` from their `remote_account_id`/username). Reconcile the target's TT entries (`findJiraSyncCandidates($targetUser, …)`) + execute under `$tokenOwner`'s token: push/pull/merge/conflict/delete-by-absence/relink/import-unmatched — the same handlers the current service has, minus the feed. Dry-run never writes. `AbstractSyncRunService::executeRun` for lifecycle.
- `syncTicketSystem(TicketSystem $ticketSystem, DateTimeImmutable $from, DateTimeImmutable $to, bool $dryRun = false): array` → `list<SyncRun>` — the cron entry point, two passes:
  1. **Self-sync:** for each `UserTicketsystemRepository::findSyncEnabled($ts)` → `syncUser($user, $user, …)` (author = token owner).
  2. **PO sync-all:** for each `findSyncAllOwners($ts)` whose user is ROLE_PL/ADMIN → read the PO-visible worklogs (broad JQL) excluding authors that are self-sync-enabled TT users; group by author; for each covered author `syncUser($authorUser, $po, …)` where `$authorUser` is the mapped TT/shadow user (via `JiraAuthorMapper::find`/`createShadow`) and `$po` is the token owner. One `SyncRun` per (author) under the PO, or one aggregate PO run with per-author items — pick per-author runs for accountability, but cap/paginate; if that is too many runs, one PO run with author-tagged items is acceptable — document the choice.
- Token-owner authorization: `syncUser` trusts its caller; the *authorization* (self, or PO for others) is enforced by the callers (command/API/`syncTicketSystem`). `findSyncAllOwners` + a `ROLE_PL/ADMIN` check via `RoleHierarchyInterface` gates pass 2.
- **No cursor. No `getWorklogsUpdatedSince/getDeletedWorklogsSince/getWorklogsByIds/getIssueKeyById`.**

- [ ] **Step 1: Failing tests** — per-user matrix (push/pull/merge/conflict/in-sync-seed/delete-clean/orphan-dirty/import-unmatched/report-unmatched/dry-run) all asserting the write goes through `worklogWriteService` with the `$tokenOwner` api; plus `testSyncUserRunTriggeredByTokenOwner`, `testSyncTicketSystemSelfSyncsEnabledUsers`, `testSyncTicketSystemPoCoversNonEnabledAuthors`, `testSyncTicketSystemPoSkipsSelfEnabledAuthors`, `testNonPoSyncAllIgnored`.
- [ ] **Step 2: Implement**, reusing the existing handlers; keep methods ≤15 complexity / ≤7 params (bundle run state in a small context object).
- [ ] **Step 3: Pass (`test:unit`), gates, commit** — `feat(sync): opt-in per-user sync with PO coverage under each responsible token (ADR-023 amendment)`.

---

### Task 5: `tt:sync-worklogs` command rework

**Files:** Rewrite `src/Command/TtSyncWorklogsCommand.php`; rewrite `tests/Command/TtSyncWorklogsCommandTest.php`.

**Interfaces (Produces):**
- `tt:sync-worklogs <ticket-system-id> [--from=Y-m-d] [--to=Y-m-d] [--dry-run]`. No `--since`, no `--user` (the cron does opt-in users + PO coverage). Default window: `--from` = 30 days ago, `--to` = today. Resolve ticket system (404→error). Call `syncTicketSystem(...)`; render each returned run via `SyncRunConsoleRenderer` (label `'Sync'`); exit 1 if any run FAILED. Reject blank/whitespace dates.

- [ ] **Step 1: Failing tests** — `testSyncsTicketSystem` (syncTicketSystem called, prints runs), `testUnknownTicketSystemFails`, `testInvalidDateFails`, `testFailedRunExitsNonZero`, `testDefaultWindowIsRollingThirtyDays`.
- [ ] **Step 2: Implement.**
- [ ] **Step 3: Pass, smoke `bin/console list tt`, gates, commit** — `feat(sync): tt:sync-worklogs runs opt-in per-user + PO passes (no cursor)`.

---

### Task 6: v2 API + MCP sync trigger

**Files:** Modify `src/Service/Sync/SyncRunRequestMapper.php`, `src/Controller/Api/V2/CreateWorklogSyncRunAction.php`, `src/Mcp/Tool/SyncJiraWorklogsTool.php`, `src/Dto/WorklogSyncRunDto.php` (drop `since`), `src/Service/Sync/SyncRunAuthorization.php`; a small preferences endpoint (below). Tests: update `WorklogSyncRunActionsTest`, `WorklogSyncToolsTest`.

**Interfaces (Produces):**
- `POST /api/v2/worklog-sync/runs` type `sync`: syncs a **single target** and returns one `SyncRunDto`. Self by default (`syncUser($self, $self, …)`); a ROLE_PL/ADMIN caller may name a target in `users[0]` → `syncUser($target, $caller, …)` (PO acts under their own token). Drop the `since`/cursor path. Non-admin naming another user → 403. (Self-sync doesn't require opt-in to be *triggered* manually — the flag governs the *cron*; a manual self-sync is always allowed.)
- MCP `sync_jira_worklogs` type `sync`: same.
- **New preferences endpoint** `PUT /api/v2/worklog-sync/preferences` `{ticket_system_id, sync_enabled, sync_all?}`, `#[RequireScope('sync:write')]`, self only: sets the caller's own `UserTicketsystem.sync_enabled`; `sync_all` accepted only when the caller is ROLE_PL/ADMIN (else 403/ignored). `GET /api/v2/worklog-sync/preferences` → the caller's per-ticket-system flags (for the Settings UI). Response DTO in `src/Dto/Response/`.

- [ ] **Step 1: Failing tests** — `testCreateSyncRunSyncsSelf`, `testAdminCanSyncAnotherUserUnderOwnToken`, `testNonAdminCannotSyncAnotherUser`, `testGetPreferences`, `testPutPreferencesSelf`, `testPutSyncAllRequiresPl`; MCP equivalents. Remove old `since`/`sync-requires-admin` tests.
- [ ] **Step 2: Implement**; adjust `SyncRunAuthorization` (self-sync allowed for all; sync-another requires PL/ADMIN).
- [ ] **Step 3: Pass (controller + mcp via DB override), gates, commit** — `feat(api): single-target sync trigger + worklog sync preferences endpoints (ADR-023 amendment)`.

---

### Task 7: Drop superseded columns + dead feed methods + admin field

**Files:**
- Modify: `src/Entity/TicketSystem.php` (now remove `syncUser`/`worklogSyncCursor` — all callers gone), extend `migrations/Version20260710_WorklogSyncOptIn.php`'s `up` to DROP those columns (if not already in Task 2's migration; keep one migration file).
- Modify: `src/Service/Integration/Jira/JiraOAuthApiService.php` — remove `getWorklogsUpdatedSince`, `getDeletedWorklogsSince`, `getWorklogsByIds`, `getIssueKeyById`; delete `src/DTO/Jira/JiraWorklogFeedPage.php`; keep `getIssueWorklog`, `searchIssueKeysWithWorklogs`, `getMyself`. Remove their tests.
- Modify: `frontend/src/admin/entities.ts` — remove the `sync_user` field from `ticketsystems` (fields + `toForm` + `toPayload`); keep `sync_default_activity`; remove the `worklogsync_ticket_system_sync_user` key from all catalogs. Update the admin frontend test.

- [ ] **Step 1:** `grep -rn` each removed symbol confirms zero references; remove; `composer analyze` proves no dangling refs; schema drop migration verified on db_unittest.
- [ ] **Step 2:** frontend removal; `bun run typecheck && bun run test && bun run lint`.
- [ ] **Step 3: Gates, commit** — `refactor(sync): drop superseded sync-user/cursor columns, feed methods and admin field`.

---

### Task 8: Settings UI — per-user sync opt-in

**Files:**
- Create: `frontend/src/components/WorklogSyncPreferences.tsx` (a `.security-block` section in Settings)
- Modify: `frontend/src/pages/Settings.tsx` (render it), `frontend/src/api/worklogSync.ts` (preferences query + write)
- Add: `worklogsync_prefs_*` i18n keys (all five catalogs)
- Test: `frontend/src/components/WorklogSyncPreferences.test.tsx`

**Interfaces (Produces):**
- `WorklogSyncPreferences()` — lists the current user's connected Jira ticket systems (`GET /api/v2/worklog-sync/preferences`), each with a **"Sync my Jira worklogs"** toggle (`sync_enabled`); for ROLE_PL/ADMIN (`hasRole`) an additional **"Sync all worklogs I can access in Jira"** toggle (`sync_all`) with a short explanatory note (acts under your token, covers colleagues not using TT). Toggling → `putWorklogSyncPreferences(...)` (postJson/PUT) + `invalidateQueries`. `role="status"` on save, `role="alert"` on error. axe-clean.
- `worklogSync.ts`: `worklogSyncPreferencesQuery()`, `putWorklogSyncPreferences(payload)`.

- [ ] **Step 1: Failing test** — mock the preferences API; render, toggle sync_enabled → asserts the PUT; PL/admin sees the sync_all toggle, a plain user does not (override `hasRole`); axe clean.
- [ ] **Step 2: Implement** the component + Settings wiring + i18n.
- [ ] **Step 3: Full frontend gate, commit** — `feat(ui): per-user Jira worklog sync opt-in in settings (ADR-023 amendment)`.

---

### Task 9: Docs

**Files:** Modify `docs/worklog-sync.md`.

- [ ] **Step 1:** Rewrite: users opt in via Settings; POs opt into sync-all; the cron `tt:sync-worklogs <ts-id>` runs both passes; each Jira op is under the responsible token; Jira permissions are the access control; the ticket-system default import activity governs auto-import; unconnected/non-opted-in users are covered only if a PO opts into sync-all. Update the API/MCP section (single-target sync + preferences endpoints).
- [ ] **Step 2: Commit** — `docs: opt-in per-user worklog sync operator guide`.

---

## After merge

Redeploy (hot-deploy runbook, memory `tt-prod-hotdeploy-state`): new image + the `Version20260710_WorklogSyncOptIn` migration (adds two columns, drops two — additive-safe, old rollback image predates all sync columns). Activation is then self-service: users toggle sync in Settings, POs toggle sync-all, and a cron runs `tt:sync-worklogs <id>` per Jira ticket system.
