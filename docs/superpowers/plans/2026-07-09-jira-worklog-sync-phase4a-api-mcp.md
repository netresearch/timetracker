# Jira Worklog Sync — Phase 4a: Conflict Resolution, v2 API, MCP Tools Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Expose the ADR-023 sync engine as programmable surfaces — conflict resolution (forced lease-era writes), four v2 REST endpoints under PAT scopes, four MCP tools (the agentic worklog-hub interface), and admin configuration of the ticket-system sync fields. UI (Phase 4b) comes separately.

**Architecture:** Per [ADR-023](../../adr/ADR-023-jira-worklog-bidirectional-sync.md) §6 and ADR-021/ADR-022 conventions. Every surface is a thin adapter over the existing engine: v2 actions use `#[RequireScope]` + `#[CurrentUser]` + `#[MapRequestPayload]` and delegate to services; MCP tools use `ScopeGuard` and delegate to the same services; response DTOs are `final readonly` + `JsonSerializable` with snake_case keys. **Scope reconciliation:** ADR-023 §6 proposed `worklog-sync:read/run/resolve`, which the landed ADR-021 taxonomy (fixed resources × `read|write`) cannot express; this phase uses the existing **`sync:read` / `sync:write`** scopes and amends the ADR (Task 8). Runs execute inline (no queue); the `SyncRun.continuation` column stays reserved — date ranges and the cursor bound the work; amended into the ADR too.

**Authorization matrix** (enforced in actions/tools, session users bypass scopes but not role/ownership checks):

| Operation | Non-admin | Admin |
|---|---|---|
| create run `verify` | self only (own token reads Jira) | self |
| create run `import` | only with `users == [own username]` | any users / all |
| create run `sync` | forbidden | allowed |
| get run | own runs (`triggeredBy`) | all |
| list conflicts | own entries only | all (optional user filter) |
| resolve conflict | own entry only | any |

**Tech Stack:** PHP 8.5, Symfony 8, PHPUnit 13, PHPStan level 10, Rector.

## Global Constraints

- License header + `declare(strict_types=1);` everywhere new (copy from any `src/Service/Sync/*.php`).
- Container commands: `docker compose --profile dev exec app-dev <cmd>`. Gates before EVERY commit: `composer analyze`, `composer analyze:arch`, `composer rector` (apply, re-run cs-fix + tests), `composer cs-fix`.
- Commits conventional, `-S --signoff`, no AI attribution. NEVER stage `config/reference.php`.
- SonarCloud: complexity ≤15/method, params ≤7, no copy-paste blocks between the four actions/tools — shared logic goes into the services or small helpers.
- v2 action exemplars to copy style from: `src/Controller/Api/V2/GetDayAction.php` (GET), `src/Controller/Api/V2/UpdateEntryAction.php` (write, `#[MapRequestPayload]`). Response DTO exemplar: `src/Dto/Response/DaySummaryDto.php`. MCP exemplars: `src/Mcp/Tool/GetDayTool.php` (read), `src/Mcp/Tool/SaveTicketSystemTool.php` (admin write, validator usage). Test exemplars: `tests/Controller/Api/V2/GetDayActionTest.php` (+ `MintsApiTokens` trait), `tests/Mcp/McpToolsTest.php` (+ `ActsAsApiTokenUser` trait).
- Functional tests: fixture user `unittest` (id 1, ADMIN) and `developer` (id 2, DEV) exist; ticket system id 1 exists; `tests/Controller/Api/V2/` runs in the `controller` suite, `tests/Mcp/` — check which suite includes it and follow.
- Where this plan lists test cases by name + expected assertions, write COMPLETE test methods for every one.

---

### Task 1: Repository queries for runs and parked states

**Files:**
- Modify: `src/Repository/SyncRunRepository.php`, `src/Repository/WorklogSyncStateRepository.php`
- Test: `tests/Repository/SyncSurfaceQueriesTest.php` (integration; extends `Tests\AbstractWebTestCase` like `ImportFindersTest` — per-test transaction rollback)

**Interfaces (Produces):**
- `SyncRunRepository::findLatest(int $limit = 20, ?TicketSystem $ticketSystem = null): array` → `list<SyncRun>` newest-first (`startedAt DESC, id DESC`).
- `WorklogSyncStateRepository::findParked(?User $user = null, int $limit = 100): array` → `list<WorklogSyncState>` with `status IN (CONFLICT, ORPHANED)`, joined+fetched `entry` (and entry's user), optional owner filter, ordered `lastSyncedAt DESC`.
- `WorklogSyncStateRepository::findParkedById(int $id): ?WorklogSyncState` — parked-status-guarded find (returns null for `IN_SYNC` rows).

- [ ] **Step 1: Failing integration test** — fixture-build two `WorklogSyncState` rows (one CONFLICT for user 2's entry, one IN_SYNC) + two `SyncRun`s (verify test orders newest-first). Cases: `testFindLatestOrdersNewestFirst`, `testFindLatestFiltersByTicketSystem`, `testFindParkedReturnsOnlyParkedStates`, `testFindParkedFiltersByUser`, `testFindParkedByIdRejectsInSyncRows`. Build entries/states with the same fluent patterns `ImportFindersTest` uses (wire project 2 → ticket system 1 in setUp).

- [ ] **Step 2: Implement** — three query-builder methods (`WHERE s.status IN (:parked)` with `setParameter('parked', [WorklogSyncStatus::CONFLICT, WorklogSyncStatus::ORPHANED])`; join `s.entry e` + `e.user`, `addSelect` for fetch).

- [ ] **Step 3: Pass, gates, commit** — `feat(sync): repository queries for run listing and parked conflict states (ADR-023 §6)`.

---

### Task 2: Response DTOs

**Files:**
- Create: `src/Dto/Response/SyncRunDto.php`, `src/Dto/Response/SyncRunItemDto.php`, `src/Dto/Response/SyncConflictDto.php`
- Test: `tests/Dto/SyncResponseDtosTest.php` (unit — `tests/Dto` is in the unit suite)

**Interfaces (Produces):**
- `SyncRunDto::fromEntity(SyncRun $syncRun, bool $withItems = true): self`; `jsonSerialize()` keys: `id, type, status, ticket_system_id, triggered_by, scope, counters, started_at, finished_at, items` (items = list of `SyncRunItemDto->jsonSerialize()`, omitted when `$withItems === false`); timestamps ISO 8601 (`format(DATE_ATOM)`), `triggered_by` = username string or null.
- `SyncRunItemDto::fromEntity(SyncRunItem $item): self`; keys: `kind, issue_key, remote_worklog_id, entry_id, author, reason, payload, created_at`.
- `SyncConflictDto::fromEntity(WorklogSyncState $state): self`; keys: `id, status, entry: {id, user, ticket, day, start, end, duration, description}, base_payload, base_updated_at, conflict_remote, last_synced_at` (`entry.user` = username; day `Y-m-d`, start/end `H:i:s`; `conflict_remote` = raw stored payload or null).

- [ ] **Step 1: Failing unit tests** — build real entities (SyncRun with one item; WorklogSyncState with a real Entry carrying user stub) and assert the exact serialized arrays (`self::assertSame` on full arrays for one representative each, plus `testRunDtoWithoutItemsOmitsKey`).

- [ ] **Step 2: Implement** following `DaySummaryDto` style (final readonly, promoted props, `fromEntity` named constructors, explicit array-shape docblocks).

- [ ] **Step 3: Pass, gates, commit** — `feat(sync): response DTOs for sync runs and conflicts (ADR-022 pattern)`.

---

### Task 3: Forced write + ConflictResolutionService

**Files:**
- Modify: `src/Service/Sync/WorklogWriteService.php` (add `forcePush`)
- Create: `src/Service/Sync/ConflictResolutionService.php`, `src/ValueObject/Sync/ResolutionResult.php`
- Test: extend `tests/Service/Sync/WorklogWriteServiceTest.php`, create `tests/Service/Sync/ConflictResolutionServiceTest.php`

**Interfaces (Produces):**
- `WorklogWriteService::forcePush(JiraOAuthApiService $api, Entry $entry, TicketSystem $ticketSystem): WriteOutcome` — identical to `push()` but **skips the lease comparison** (still handles empty ticket → SKIPPED; delegates to the legacy write, which itself nulls a stale worklogId and re-creates — so it also covers orphaned recreation); refreshes base afterwards (existing `refreshBase`). Returns WRITTEN/SKIPPED.
- `ResolutionResult` readonly: `(bool $resolved, string $action, string $reason = '')` — `action` ∈ `pushed_local | pulled_remote | recreated_local | deleted_local`.
- `ConflictResolutionService::resolve(WorklogSyncState $state, string $winner, User $actor): ResolutionResult`:
  - Guard: `$state->getStatus()` must be CONFLICT or ORPHANED, else `(false, '', 'state is not parked')`. `$winner` ∈ `local|remote` else invalid-argument result.
  - Token selection: entry owner if connected (`UserTicketsystem` row with non-empty access token and `!avoidConnection`) else ticket system's `getSyncUser()` else `$actor`; build api via `JiraOAuthApiFactory::create`.
  - **winner=local:** `forcePush(...)` → WRITTEN → `(true, $state->getStatus() === ORPHANED (checked before push) ? 'recreated_local' : 'pushed_local')`. (`forcePush`'s base refresh already sets IN_SYNC + clears `conflictRemotePayload`.)
  - **winner=remote, status CONFLICT:** re-fetch the LIVE remote (`$api->getIssueWorklog($entry->getTicket(), $worklogId)`) — the stored payload is display material and may be stale. Live gone → treat as orphaned-remote-wins (below). Else normalize → `fields = projector->project($entry)->diff($remoteSnapshot)` → `EntryPullApplier::apply($entry, $remoteSnapshot, $fields, $ticketSystem)`; not applied → `(false, '', reason)`. Applied → update state: IN_SYNC, base = remoteSnapshot, `baseUpdatedAt = live->updated ?? ''`, clear conflict payload; day-class recalc via `DayClassService` for the applier's `affectedDays` (entry owner's id); `(true, 'pulled_remote')`.
  - **winner=remote, status ORPHANED** (remote is gone — remote winning means the deletion wins): `$entityManager->remove($entry)` (state cascades), day recalc for the entry's day, `(true, 'deleted_local')`.
  - Flush at the end of a successful resolve (day recalc after flush, ids exist).
  - Constructor: `(EntityManagerInterface, JiraOAuthApiFactory, WorklogWriteService, EntryPullApplier, EntryWorklogProjector, RemoteWorklogNormalizer, DayClassService, ClockInterface)`.

- [ ] **Step 1: Failing tests.**
  `WorklogWriteServiceTest` additions: `testForcePushSkipsLeaseAndWrites` (state with mismatching `baseUpdatedAt`; remote GET NOT consulted for comparison — write happens, base refreshed), `testForcePushEmptyTicketSkips`.
  `ConflictResolutionServiceTest` (mock kit like other sync tests; real Entry/WorklogSyncState where mutation is asserted): `testRejectsUnparkedState`, `testRejectsInvalidWinner`, `testLocalWinsForcePushes` (asserts `forcePush` called once, result action `pushed_local`), `testLocalWinsOnOrphanedReportsRecreated`, `testRemoteWinsPullsLiveRemote` (live fetch differs from stored payload — applier receives the LIVE snapshot fields; state base updated to live `updated`; action `pulled_remote`), `testRemoteWinsWithLiveGoneDeletesEntry` (`remove` called; action `deleted_local`), `testRemoteWinsOnOrphanedDeletesEntry`, `testPullFailureSurfacesReason` (applier returns `PullResult(false, 'worklog crosses midnight')` → resolved false, that reason).

- [ ] **Step 2: Implement** both (complete code following the interface contract; `forcePush` shares `refreshBase` — do not duplicate it).

- [ ] **Step 3: Pass, full unit suite, gates, commit** — `feat(sync): conflict resolution via forced lease-era writes (ADR-023 §2)`.

---

### Task 4: Run endpoints — create + get

**Files:**
- Create: `src/Dto/WorklogSyncRunDto.php` (request), `src/Controller/Api/V2/CreateWorklogSyncRunAction.php`, `src/Controller/Api/V2/GetWorklogSyncRunAction.php`
- Test: `tests/Controller/Api/V2/WorklogSyncRunActionsTest.php`

**Interfaces (Produces):**
- Request DTO `WorklogSyncRunDto` (snake_case props, `#[Assert]`): `string $type` (`#[Assert\Choice(['verify','import','sync'])]`), `int $ticket_system_id` (`#[Assert\Positive]`), `?string $from = null`, `?string $to = null`, `/** @var list<string> */ array $users = []`, `?int $default_activity_id = null`, `bool $dry_run = false`, `?string $since = null` (sync only; `Y-m-d` or epoch-ms digits).
- `POST /api/v2/worklog-sync/runs` (`api_v2_worklog_sync_run_create`), `#[RequireScope('sync:write')]`. Resolves ticket system (404), parses dates (default: first day of current month / today; invalid → 422). Authorization per the matrix (non-admin: verify always allowed; import only when `users === [$user->getUsername()]`; sync → 403 `['message' => 'Admin role required for sync runs.']`). Admin detection: `$this->authorizationChecker->isGranted('ROLE_ADMIN')` (inject `AuthorizationCheckerInterface`). Dispatch: verify → `VerifyWorklogsService::verify($user, ...)`; import → `ImportWorklogsService::import($user, ..., $default_activity_id ?? 0, users, dry_run)` (missing/invalid activity id for import → 422 before dispatch); sync → `SyncWorklogsService::sync($ts, $sinceMillis, $dry_run)` (since parsing like the command; a FAILED run returns 200 with the run body — the run itself records the failure). Response: 201 + `SyncRunDto::fromEntity($run)`.
- `GET /api/v2/worklog-sync/runs/{id}` (`requirements: ['id' => '\d+']`), `#[RequireScope('sync:read')]`. 404 unknown; 403 when non-admin and `getTriggeredBy()?->getId() !== $user->getId()`; 200 + `SyncRunDto`.

- [ ] **Step 1: Failing functional tests** (exemplar: `GetDayActionTest` + `MintsApiTokens`; the Jira-touching services must NOT be hit — mock them in the container: `self::getContainer()->set(VerifyWorklogsService::class, $mock)` returning a canned COMPLETED `SyncRun`; same for import/sync services). Cases: `testCreateVerifyRunReturnsRunBody` (PAT `sync:write`, 201, body has `type: verify`, counters), `testCreateRunRequiresWriteScope` (PAT `sync:read` → 403), `testNonAdminCannotTriggerSync` (session login as `developer` → 403), `testNonAdminSelfImportAllowed` (developer, users=[developer] → 201), `testNonAdminForeignImportForbidden` (users=[unittest] → 403), `testImportWithoutActivityRejected` (422), `testUnknownTicketSystem404`, `testGetRunReturnsBody` (owner), `testGetRunForeign403ForNonAdmin`, `testGetRunAdminSeesAll`, `testInvalidDate422`.

- [ ] **Step 2: Implement.** Keep `CreateWorklogSyncRunAction::__invoke` complexity in check: private methods `authorize(...)`, `parseRange(...)`, `dispatch(...)`. No duplicated date-parsing between actions — a tiny `SyncRunRequestMapper` helper service is acceptable if needed by later tasks.

- [ ] **Step 3: Pass (`composer test:controller` for the new file), gates, commit** — `feat(api): worklog sync run endpoints (ADR-023 §6)`.

---

### Task 5: Conflict endpoints — list + resolve

**Files:**
- Create: `src/Dto/ResolveConflictDto.php`, `src/Controller/Api/V2/ListWorklogSyncConflictsAction.php`, `src/Controller/Api/V2/ResolveWorklogSyncConflictAction.php`
- Test: `tests/Controller/Api/V2/WorklogSyncConflictActionsTest.php`

**Interfaces (Produces):**
- `GET /api/v2/worklog-sync/conflicts`, `#[RequireScope('sync:read')]`: non-admin → `findParked($user)`; admin → `findParked()` or `findParked($filterUser)` via `?user=<username>` query (unknown username → 422). Response `{'conflicts': [SyncConflictDto...], 'count': N}`.
- `ResolveConflictDto`: `string $winner` (`#[Assert\Choice(['local','remote'])]`).
- `POST /api/v2/worklog-sync/conflicts/{id}/resolve`, `#[RequireScope('sync:write')]`: `findParkedById` → 404; ownership (entry user) or admin → 403; `ConflictResolutionService::resolve($state, $winner, $user)` → resolved false → 422 with reason; true → 200 `{'resolved': true, 'action': ..., 'conflict_id': id}`.

- [ ] **Step 1: Failing functional tests** — fixture states created like Task 1's test (real DB rows; ConflictResolutionService MOCKED in container for the resolve action — assert delegation args — plus one 422 pass-through case). Cases: `testListOwnConflictsAsNonAdmin` (developer sees only own), `testAdminSeesAllAndCanFilterByUser`, `testListRequiresReadScope`, `testResolveDelegatesAndReturnsAction`, `testResolveForeignConflict403ForNonAdmin`, `testResolveUnknown404`, `testResolveFailureReturns422WithReason`, `testResolveRequiresWriteScope`.

- [ ] **Step 2: Implement** (thin; DTO mapping via the Task 2 DTOs).

- [ ] **Step 3: Pass, gates, commit** — `feat(api): worklog sync conflict listing and resolution endpoints (ADR-023 §6)`.

---

### Task 6: Ticket-system sync configuration exposure

**Files:**
- Modify: `src/Dto/TicketSystemSaveDto.php`, `src/Controller/Admin/SaveTicketSystemAction.php`
- Test: extend the existing SaveTicketSystemAction test (locate it: `grep -rl SaveTicketSystem tests/`)

**Interfaces (Produces):**
- DTO gains `?int $syncUserId = null`, `?int $syncDefaultActivityId = null` (both `#[Map(if: false)]` — resolved manually like other relation fields; check how the DTO maps relations today and mirror). The save action resolves them (`UserRepository::find` / `ActivityRepository::find`; unknown id → 422 message) and sets `setSyncUser`/`setSyncDefaultActivity`; **null clears** the setting (explicit tri-state is not needed — the admin form always sends the full config). `worklogSyncCursor` stays read-only (already emitted by `toSafeArray()`; operators reset via `tt:sync-worklogs --since`).

- [ ] **Step 1: Failing test additions** — `testSavePersistsSyncConfiguration` (POST with `syncUserId: 2`, `syncDefaultActivityId: 1` → entity relations set; response body contains `sync_user_id`-ish keys via toSafeArray), `testSaveClearsSyncConfigurationWithNulls`, `testSaveRejectsUnknownSyncUser` (422).

- [ ] **Step 2: Implement** minimal DTO + action changes (respect the existing blank-secret-preservation code — do not touch it).

- [ ] **Step 3: Pass, gates, commit** — `feat(admin): configure worklog sync user and default import activity (ADR-023 §5)`.

---

### Task 7: MCP tools

**Files:**
- Create: `src/Mcp/Tool/SyncJiraWorklogsTool.php`, `src/Mcp/Tool/GetSyncRunTool.php`, `src/Mcp/Tool/ListSyncConflictsTool.php`, `src/Mcp/Tool/ResolveSyncConflictTool.php`
- Test: `tests/Mcp/WorklogSyncToolsTest.php`

**Interfaces (Produces):** four `#[McpTool]` tools, exemplar style `GetDayTool` (read) / `SaveTicketSystemTool` (admin write):
- `sync_jira_worklogs(string $type, int $ticket_system_id, ?string $from = null, ?string $to = null, array $users = [], ?int $default_activity_id = null, bool $dry_run = false, ?string $since = null)` — `ScopeGuard::requireScope('sync:write')`; the SAME authorization matrix as Task 4 (admin check via the guard's `requireAdminScope` only for `sync` type and foreign import — structure: resolve user via `requireScope`, then re-check admin with `requireAdminScope` in the branches that need it); delegates to the three services; returns `SyncRunDto::fromEntity($run)->jsonSerialize()`. Description text (agent-facing): explain the three types, dry_run preview, that verify is read-only, and that parked items land in `list_sync_conflicts`.
- `get_sync_run(int $run_id)` — `sync:read`; ownership rule as Task 4; returns run array.
- `list_sync_conflicts(?string $user = null)` — `sync:read`; non-admin forced to self; returns `{'conflicts': [...], 'count': N}`.
- `resolve_sync_conflict(int $conflict_id, string $winner)` — `sync:write`; ownership or admin; delegates to `ConflictResolutionService`; failure → `ToolCallException` with the reason.
- Shared authorization/ownership logic between v2 actions and tools: extract a small `src/Service/Sync/SyncRunAuthorization.php` helper (`canTrigger(User $user, bool $isAdmin, string $type, array $users): bool`, `canSeeRun(User, bool, SyncRun): bool`, `canResolve(User, bool, WorklogSyncState): bool`) used by BOTH Task 4/5 actions and these tools — if Task 4 inlined it, refactor here into the helper (SonarCloud duplication).

- [ ] **Step 1: Failing tests** (exemplar `McpToolsTest` + `ActsAsApiTokenUser::useToken([...])`; container-mock the three run services + resolution service). Cases: `testSyncToolTriggersVerify`, `testSyncToolRejectsReadOnlyScope` (ToolCallException on `sync:read`), `testSyncToolSyncTypeRequiresAdmin` (token user `unittest` is ADMIN — also mint a developer-token variant; check how `ActsAsApiTokenUser` picks the user and parametrize if supported, else create the token for user 2 manually), `testGetSyncRunOwnership`, `testListConflictsForcesSelfForNonAdmin`, `testResolveDelegates`, `testResolveFailureThrowsToolCallException`, plus the tool-discovery smoke: extend/verify `McpToolsTest`'s attribute enumeration picks up all four (it globs `src/Mcp/Tool/*Tool.php` — confirm it passes).

- [ ] **Step 2: Implement** the four tools + the shared authorization helper (refactor Tasks 4/5 actions onto it in the same commit if they inlined the logic).

- [ ] **Step 3: Pass (`tests/Mcp` + re-run Task 4/5 test files), gates, commit** — `feat(mcp): worklog sync tools — trigger runs, inspect, resolve conflicts (ADR-023 §6)`.

---

### Task 8: ADR + docs

**Files:**
- Modify: `docs/adr/ADR-023-jira-worklog-bidirectional-sync.md`, `docs/adr/README.md`, `docs/worklog-sync.md`

- [ ] **Step 1: ADR amendments**
  1. Status → `Accepted — Phases 1–4a (verify, import, bidirectional sync, API/MCP surfaces) implemented; Phase 4b (SPA UI) pending.`
  2. §6 scope note: `Implementation note (Phase 4a): the dedicated worklog-sync:* scopes were reconciled to the existing ADR-021 taxonomy — sync:read guards run/conflict reads, sync:write guards run triggers and conflict resolution. Session-authenticated users bypass PAT scopes (per ADR-021); the authorization matrix (self-verify/self-import for users, sync triggers and foreign operations for admins) is enforced in the actions and tools themselves.`
  3. §4 note: `Implementation note (Phase 4a): HTTP-triggered runs execute inline and are bounded by date range / cursor delta; SyncRun.continuation remains reserved for future chunked execution.`
  4. §2 note (resolution): `Implementation note (Phase 4a): conflict resolution is a forced write — winner=local force-pushes (recreating the worklog for orphans), winner=remote pulls the LIVE remote (re-fetched, not the stored snapshot) or, for orphans, accepts the remote deletion by removing the local entry.`
  5. README index row → `Accepted (Phases 1–4a done; UI pending)`.
- [ ] **Step 2: `docs/worklog-sync.md`** — add an "API & MCP" section: the four endpoints with scope requirements, the four MCP tools, one curl example (create verify run) and one conflict-resolution example.
- [ ] **Step 3: Commit** — `docs(adr): ADR-023 phase 4a implemented; scope reconciliation and API docs`.

---

## Phase boundary (orientation — NOT this plan)

- **Phase 4b (final):** SolidJS UI — Settings self-service import (date range, default activity, dry-run preview → execute), admin sync area (run history + reports, trigger runs, ticket-system sync config fields in the admin form), conflict-resolution screen (side-by-side local vs remote from `conflict_remote`, per-conflict winner buttons) — all against the Phase 4a endpoints.
