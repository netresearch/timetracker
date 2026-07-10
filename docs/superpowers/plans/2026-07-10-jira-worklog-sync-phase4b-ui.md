# Jira Worklog Sync — Phase 4b: SolidJS UI Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The worklog-sync UI (ADR-023 §6, last phase): self-service Jira import in Settings, an admin sync area (run history + trigger + reports), and a conflict-resolution screen — all against the Phase 4a v2 endpoints.

**Architecture:** SolidJS 1.9 SPA (ADR-016) under `/ui`, following the codebase's established idioms — **no `useMutation`** (manual `postJson` + `queryClient.invalidateQueries`), hand-written DTO interfaces colocated with queries, hand-written CSS design tokens (no Tailwind utilities), Paraglide i18n across all five catalogs, WCAG 2.2 AA (axe in unit tests). One small backend addition: a list-runs endpoint (the `SyncRunRepository::findLatest` query exists but is unexposed).

**Backend endpoints (Phase 4a, live):**
- `POST /api/v2/worklog-sync/runs` `{type, ticket_system_id, from?, to?, users?, default_activity_id?, dry_run?, since?}` → 201 `SyncRunDto`
- `GET /api/v2/worklog-sync/runs/{id}` → `SyncRunDto`
- `GET /api/v2/worklog-sync/conflicts?user=` → `{conflicts: SyncConflictDto[], count}`
- `POST /api/v2/worklog-sync/conflicts/{id}/resolve` `{winner: 'local'|'remote'}` → `{resolved, action, conflict_id}`
- **NEW (Task 1):** `GET /api/v2/worklog-sync/runs?ticket_system_id=&limit=` → `{runs: SyncRunDto[] (no items), count}`

**DTO shapes (snake_case, from the backend DTOs):**
- `SyncRunDto`: `{id, type, status, ticket_system_id, triggered_by, scope, counters: Record<string,number>, started_at, finished_at, items?: SyncRunItemDto[]}`
- `SyncRunItemDto`: `{kind, issue_key, remote_worklog_id, entry_id, author, reason, payload, created_at}`
- `SyncConflictDto`: `{id, status, entry: {id, user, ticket, day, start, end, duration, description}, base_payload, base_updated_at, conflict_remote: {comment, started, timeSpentSeconds, updated}|null, last_synced_at}`

**Tech stack:** bun, SolidJS 1.9, @solidjs/router 0.16, @tanstack/solid-query 5, Vitest 4 + @solidjs/testing-library + vitest-axe, Paraglide i18n. PHP 8.5 for the one backend endpoint.

## Global Constraints

- **Frontend commands run inside `frontend/`** (bun): `bun run lint`, `bun run typecheck` (compiles i18n then `tsc --noEmit`), `bun run test`, single file `bun run test src/path/File.test.tsx`. Run all three before every commit that touches the frontend.
- Backend command/gates for Task 1 run in the container: `docker compose --profile dev exec app-dev composer analyze && composer analyze:arch && composer rector && composer cs-fix && composer test:fast`.
- Writes use the established idiom: `await postJson(path, payload)` in try/catch/finally with a busy signal, then `queryClient.invalidateQueries({queryKey})`. **Never add `useMutation`.**
- API calls use `getJson<T>` / `postJson<T>` from `src/api/client.ts` with full v2 path strings; errors surfaced via `apiErrorMessage(err, fallback)`.
- DTO interfaces are hand-written and colocated (a new `src/api/worklogSync.ts` module).
- **i18n:** every user-facing string is a Paraglide key `m.worklogsync_*()`; add the identical key to ALL FIVE catalogs `frontend/messages/{en,de,es,fr,ru}.json` (they must stay key-identical or `typecheck` fails). German is the e2e default — e2e assertions match German-or-English regex.
- **CSS:** hand-written in `frontend/src/styles/app.css` using existing tokens and the `is-<state>` badge convention (`.form-status.is-ok/.is-error`, `.subsystem-status.is-*`); reuse `.primary-button`, `.ghost-button`, `.field`, `.form-actions`, `.stack-form`, `.security-block`. No Tailwind utility classes.
- **a11y (WCAG 2.2 AA + AAA subset):** real `<button>`s, `role="status"`/`role="alert"` feedback, `aria-pressed`/`aria-current` for the chosen conflict side, keyboard reachable, 44px targets, 7:1 contrast in both schemes, no color-only signals (pair badges with text). Every component test asserts `expect(await axe(container)).toHaveNoViolations()`.
- **Role gating:** `hasRole('ROLE_ADMIN')` from `src/config.ts` for the admin area (route `guarded()` + in-component `<Show>`); `appConfig().userId` to scope self-service.
- Commits conventional, `git commit -S --signoff`, no AI attribution. NEVER stage `config/reference.php`.
- Reference-data selects reuse existing queries: `activitiesQuery`, `usersQuery`, `ticketSystemsQuery` from `src/api/queries.ts` (return `NamedOption[] = {id, label, active?}`).

---

### Task 1: Backend — list-runs endpoint

**Files:**
- Create: `src/Controller/Api/V2/ListWorklogSyncRunsAction.php`
- Test: `tests/Controller/Api/V2/WorklogSyncRunActionsTest.php` (extend — add list cases)

**Interfaces:**
- `GET /api/v2/worklog-sync/runs` (`api_v2_worklog_sync_run_list`), `#[RequireScope('sync:read')]`. Query params: `ticket_system_id?` (int, filter), `limit?` (int, default 20, clamp 1..100). Non-admin: only the caller's own runs (`triggeredBy`); admin: all. Uses `SyncRunRepository::findLatest($limit, $ticketSystem?)` then filters by owner for non-admins in PHP (or add a `findLatestForUser` — pick the simpler; `findLatest` + array filter is fine at limit ≤100). Response `{'runs': [SyncRunDto::fromEntity($run, withItems: false)...], 'count': N}`.
- Route must be declared so it does not collide with `runs/{id}` (`{id}` has `requirements: ['id' => '\d+']`, so `/runs` (no segment) is distinct — verify with `debug:router`).

- [ ] **Step 1: Failing functional tests** (mirror the existing `WorklogSyncRunActionsTest` — mock the run services in the container is not needed here; seed real `SyncRun` rows like `SyncSurfaceQueriesTest` does, or reuse its trait). Cases:
  - `testListReturnsRunsNewestFirst` (PAT `sync:read`, seeds 2 runs → 200, `runs` array length 2, no `items` key on entries)
  - `testListFiltersByTicketSystem`
  - `testListRequiresReadScope` (PAT `sync:write`-only → 403)
  - `testListNonAdminSeesOnlyOwnRuns` (session `developer`, admin's run absent)
  - `testListRespectsLimit` (`?limit=1` → 1 run)

- [ ] **Step 2: Implement** the action (copy `GetWorklogSyncRunAction` structure; inject `SyncRunRepository`, `AuthorizationCheckerInterface`; `SyncRunDto::fromEntity($run, false)`).

- [ ] **Step 3: Pass, gates, commit** — `composer analyze && analyze:arch && rector && cs-fix && test:controller`; `feat(api): list worklog sync runs endpoint (ADR-023 §6)`.

---

### Task 2: Frontend API module + i18n keys

**Files:**
- Create: `frontend/src/api/worklogSync.ts`
- Modify: `frontend/messages/{en,de,es,fr,ru}.json` (add `worklogsync_*` keys)
- Test: `frontend/src/api/worklogSync.test.ts`

**Interfaces (Produces):**
```ts
// DTO interfaces
export interface SyncRunItem { kind: string; issue_key: string | null; remote_worklog_id: number | null; entry_id: number | null; author: string | null; reason: string; payload: Record<string, unknown> | null; created_at: string }
export interface SyncRun { id: number; type: string; status: string; ticket_system_id: number | null; triggered_by: string | null; scope: Record<string, unknown>; counters: Record<string, number>; started_at: string | null; finished_at: string | null; items?: SyncRunItem[] }
export interface SyncConflict { id: number; status: string; entry: { id: number; user: string | null; ticket: string; day: string; start: string; end: string; duration: number; description: string }; base_payload: Record<string, unknown>; base_updated_at: string; conflict_remote: { comment: string | null; started: string | null; timeSpentSeconds: number | null; updated: string | null } | null; last_synced_at: string | null }

export interface CreateRunPayload { type: 'verify' | 'import' | 'sync'; ticket_system_id: number; from?: string; to?: string; users?: string[]; default_activity_id?: number; dry_run?: boolean; since?: string }

// query factories (TanStack)
export function syncRunsQuery(ticketSystemId?: number, limit?: number): { queryKey; queryFn }   // GET /runs
export function syncRunQuery(id: number): { queryKey; queryFn }                                   // GET /runs/{id}
export function syncConflictsQuery(user?: string): { queryKey; queryFn }                          // GET /conflicts -> {conflicts,count}
export const worklogSyncKeys = { runs: ['worklog-sync','runs'] as const, conflicts: ['worklog-sync','conflicts'] as const }

// write helpers (plain async; caller invalidates)
export function createSyncRun(payload: CreateRunPayload): Promise<SyncRun>                        // POST /runs
export function resolveConflict(id: number, winner: 'local' | 'remote'): Promise<{ resolved: boolean; action: string; conflict_id: number }>
```
- Query factories return `{queryKey, queryFn}` matching the `src/api/queries.ts` convention. `syncRunsQuery` `queryFn` → `getJson<{runs: SyncRun[]; count: number}>('/api/v2/worklog-sync/runs', params)`. Writes call `postJson`.

- [ ] **Step 1: Failing tests** — `worklogSync.test.ts`: mock `../api/client` (`getJson`/`postJson`), assert each factory builds the right path+params and each write posts the right body. E.g. `createSyncRun({type:'verify',ticket_system_id:1})` calls `postJson('/api/v2/worklog-sync/runs', {type:'verify',ticket_system_id:1})`; `resolveConflict(5,'local')` calls `postJson('/api/v2/worklog-sync/conflicts/5/resolve',{winner:'local'})`; `syncConflictsQuery('jdoe').queryKey` deep-equals `['worklog-sync','conflicts',{user:'jdoe'}]`.

- [ ] **Step 2: Implement** `worklogSync.ts`.

- [ ] **Step 3: i18n keys** — add to all five catalogs (identical keys). Minimum set (English values shown; translate de/es/fr/ru — for de use natural German technical register, e.g. `worklogsync_import_title` → "Jira-Zeiten importieren"):
  ```
  worklogsync_import_title, worklogsync_import_intro, worklogsync_from, worklogsync_to,
  worklogsync_default_activity, worklogsync_ticket_system, worklogsync_preview, worklogsync_execute,
  worklogsync_dryrun_note, worklogsync_created, worklogsync_would_create, worklogsync_skipped,
  worklogsync_running, worklogsync_admin_title, worklogsync_trigger, worklogsync_type,
  worklogsync_type_verify, worklogsync_type_import, worklogsync_type_sync, worklogsync_run_history,
  worklogsync_run_status, worklogsync_run_started, worklogsync_conflicts_title, worklogsync_conflict_local,
  worklogsync_conflict_remote, worklogsync_resolve_local, worklogsync_resolve_remote, worklogsync_resolved,
  worklogsync_no_conflicts, worklogsync_no_runs, worklogsync_users, worklogsync_since, worklogsync_all_users
  ```

- [ ] **Step 4: Verify + commit** — `cd frontend && bun run typecheck && bun run test src/api/worklogSync.test.ts && bun run lint`; `feat(ui): worklog sync API module and i18n keys (ADR-023 §6)`.

---

### Task 3: Shared presentation — counters + items + status badge

**Files:**
- Create: `frontend/src/components/SyncRunSummary.tsx` (counters table + items list, reused by import + admin)
- Modify: `frontend/src/styles/app.css` (add `.sync-*` classes + `.sync-status.is-*` badges)
- Test: `frontend/src/components/SyncRunSummary.test.tsx`

**Interfaces (Produces):**
- `SyncRunSummary(props: { run: SyncRun })` — renders a status badge (`<span class={`sync-status is-${run.status}`}>` + text label via `m.worklogsync_run_status()` mapping), a counters table (`Object.entries(run.counters)`, tabular, each key humanized via an i18n map with a fallback to the raw key), and, when `run.items?.length`, a list of items (`kind` badge + `issue_key` + `reason`). Empty counters → a muted "—".
- CSS: `.sync-status.is-completed/.is-partial/.is-running/.is-failed` (semantic colors, 7:1, paired with text), `.sync-counters` (table), `.sync-items` (list), `.sync-item-kind.is-conflict/.is-error/...`. Model on the existing `.subsystem-status.is-*` and `.form-status` rules.

- [ ] **Step 1: Failing test** — render a `SyncRun` with `status:'completed'`, `counters:{created:3, conflicts:1}`, one item `{kind:'conflict', issue_key:'ABC-1', reason:'both changed'}`; assert the status badge text, both counter rows, the item line; `axe` clean. Plus a `status:'failed'` variant asserting the failed badge class/text.

- [ ] **Step 2: Implement** the component + CSS.

- [ ] **Step 3: Verify + commit** — `bun run typecheck && bun run test src/components/SyncRunSummary.test.tsx && bun run lint`; `feat(ui): sync run summary component (ADR-023 §6)`.

---

### Task 4: Self-service import in Settings

**Files:**
- Create: `frontend/src/components/WorklogImportSection.tsx`
- Modify: `frontend/src/pages/Settings.tsx` (render `<WorklogImportSection />` after `<SecuritySection />`)
- Test: `frontend/src/components/WorklogImportSection.test.tsx`

**Interfaces (Produces):**
- `WorklogImportSection()` — a `.security-block`-style card. Fields: ticket-system select (`useQuery(ticketSystemsQuery)`), two `DateField`s (from/to, default: first-of-month / today), default-activity select (`useQuery(activitiesQuery)`). Two-step flow mirroring `SecuritySection`'s TOTP multi-`<Show>`: **Preview** button → `createSyncRun({type:'import', ticket_system_id, from, to, default_activity_id, users:[appConfig().userName], dry_run:true})` → shows `<SyncRunSummary>` of the dry run + an **Execute import** button → same payload with `dry_run:false` → shows the final `<SyncRunSummary>` + a success `role="status"`. Busy signal disables buttons (label → `m.app_saving()`); errors → `role="alert"` via `apiErrorMessage`. `users` is scoped to the current user (self-service; the backend enforces this for non-admins anyway).
- On a successful execute, `queryClient.invalidateQueries({queryKey: worklogSyncKeys.conflicts})` (a real import can surface parked items) — inject `useQueryClient()`.

- [ ] **Step 1: Failing test** — mock `../api/worklogSync` (`createSyncRun`) and the reference queries; render, select a ticket system + activity, click Preview → assert `createSyncRun` called with `dry_run:true` and the dry-run summary shows; click Execute → assert `dry_run:false` call and success status; error path asserts `role="alert"`. axe clean. (Use `renderWithProviders`.)

- [ ] **Step 2: Implement** the component; wire it into `Settings.tsx`.

- [ ] **Step 3: Verify + commit** — full frontend gate (`bun run typecheck && bun run test && bun run lint`); `feat(ui): self-service Jira worklog import in settings (ADR-023 use case 1)`.

---

### Task 5: Ticket-system sync configuration fields (admin form)

**Files:**
- Modify: `frontend/src/admin/entities.ts` (the `ticketsystems` descriptor)
- Test: extend `frontend/src/pages/Admin.test.tsx` (or the ticketsystems-specific test if one exists)

**Interfaces (Produces):**
- Add two `FieldDef`s to the `ticketsystems` descriptor `fields`: `{name:'sync_user', label: () => m.worklogsync_ticket_system_sync_user(), type:'select', source:'users', activeOnly:false}` and `{name:'sync_default_activity', label: () => m.worklogsync_ticket_system_sync_activity(), type:'select', source:'activities'}`. Wire both into `toForm` (read `sync_user_id`/`sync_default_activity_id` from the row) and `toPayload` (emit `syncUserId`/`syncDefaultActivityId` — match the `TicketSystemSaveDto` field names from Phase 4a). Add the two i18n keys to all catalogs.
- Both nullable (a "— none —" option clears the setting); `worklog_sync_cursor` stays read-only (not a form field).

- [ ] **Step 1: Failing test** — in the admin test, open the ticketsystems edit form, assert the two new selects render with options from the mocked `users`/`activities` sources, and that saving posts `syncUserId`/`syncDefaultActivityId`.

- [ ] **Step 2: Implement** the descriptor changes + i18n keys.

- [ ] **Step 3: Verify + commit** — full frontend gate; `feat(ui): configure ticket-system sync user and default activity (ADR-023 §5)`.

---

### Task 6: Admin sync area (run history + trigger + conflicts)

**Files:**
- Create: `frontend/src/pages/WorklogSync.tsx` (the admin sync page)
- Modify: `frontend/src/App.tsx` (guarded route + `PAGE_TITLES`), `frontend/src/pages/Admin.tsx` or `frontend/src/components/SidebarAdminMenu.tsx` (nav entry — follow whichever pattern `AdminStatus` uses so no Twig edit is needed)
- Test: `frontend/src/pages/WorklogSync.test.tsx`

**Interfaces (Produces):**
- `WorklogSync()` — `ROLE_ADMIN`-guarded page with three regions:
  1. **Trigger**: type select (`verify`/`import`/`sync`), ticket-system select, date range, optional users (comma field or multiselect) for import, `dry_run` checkbox; submit → `createSyncRun(...)` → show the returned `<SyncRunSummary>` + `invalidateQueries(worklogSyncKeys.runs)`.
  2. **Run history**: `useQuery(() => syncRunsQuery(selectedTicketSystem))` → table (type, status badge, triggered_by, started_at, key counters); clicking a row loads `useQuery(syncRunQuery(id))` and shows its `<SyncRunSummary>` with items.
  3. **Conflicts**: embed `<ConflictList />` (Task 7).
- Registered so it appears in the admin subnav (mirror `AdminStatus`: a non-CRUD key handled in `Admin.tsx`, listed in `SidebarAdminMenu.tsx`) OR as a standalone guarded route `/worklog-sync` — pick the one matching `AdminStatus`'s exact mechanism (read it first). Add to `PAGE_TITLES` so `<h1>`/title/SR-announcement stay single-sourced.

- [ ] **Step 1: Failing test** — mock `../api/worklogSync`; render the page as admin (setup.ts config has ROLE_ADMIN), assert the run-history table renders seeded runs, triggering a run calls `createSyncRun` and shows the summary, and a non-admin render (override `hasRole`) does not expose it. axe clean.

- [ ] **Step 2: Implement** the page + routing/nav wiring.

- [ ] **Step 3: Verify + commit** — full frontend gate; `feat(ui): admin worklog sync area with run history and triggers (ADR-023 §6)`.

---

### Task 7: Conflict resolution screen

**Files:**
- Create: `frontend/src/components/ConflictList.tsx`
- Modify: `frontend/src/styles/app.css` (`.conflict-*` side-by-side layout)
- Test: `frontend/src/components/ConflictList.test.tsx`

**Interfaces (Produces):**
- `ConflictList(props?: { user?: string })` — `useQuery(() => syncConflictsQuery(props?.user))`. Empty → `m.worklogsync_no_conflicts()`. Each conflict renders a card with a **side-by-side** comparison: left = local (`entry`: ticket, day, start–end, duration, description) vs right = remote (`conflict_remote`: comment, started, `timeSpentSeconds`→minutes) — for `orphaned` status (remote gone) show "remote deleted". Two real `<button>`s **Keep local** / **Keep remote** with `aria-label` naming the conflict; clicking → `resolveConflict(id, winner)` in try/catch/finally with a per-row busy signal, then `invalidateQueries(worklogSyncKeys.conflicts)`; success → the row disappears (query refetch) and a `role="status"` announces `m.worklogsync_resolved()`. Errors → `role="alert"`.
- Layout: `.conflict-card` with a two-column `.conflict-sides` (stacks on narrow), each side `.conflict-side` with a heading; the resolve buttons in a `.conflict-actions` bar. Diff-highlight the fields that differ (compare `base_payload` vs each side) with a `.is-changed` class — optional but improves scanability; if included, don't rely on color alone (add a "changed" visually-hidden note).

- [ ] **Step 1: Failing test** — mock the conflicts query returning one `conflict` + `resolveConflict`; assert both sides render with their values, clicking "Keep remote" calls `resolveConflict(id,'remote')` and invalidates, empty state renders when the list is empty, orphaned status shows the deleted-remote note. axe clean (side-by-side must be keyboard reachable, buttons labeled).

- [ ] **Step 2: Implement** the component + CSS.

- [ ] **Step 3: Verify + commit** — full frontend gate; `feat(ui): worklog sync conflict resolution screen (ADR-023 §2)`.

---

### Task 8: e2e smoke + ADR/docs finalization

**Files:**
- Create: `e2e/worklog-sync.spec.ts` (Playwright — admin gating + presence smoke, no real Jira)
- Modify: `docs/adr/ADR-023-jira-worklog-bidirectional-sync.md`, `docs/adr/README.md`, `docs/worklog-sync.md`

**Interfaces:**
- e2e spec (hits the real e2e stack, which has **no Jira** — so assert on UI presence/gating, not sync results): a non-admin is redirected away from the admin sync area; an admin sees it; the Settings import section renders its controls; the trigger form validates (e.g. import without activity shows an error). Use the German-or-English regex matchers and helpers (`e2e/helpers/auth.ts`, `navigation.ts`) per `e2e/AGENTS.md`. Keep it a thin smoke — the engine is unit/functional-tested and real-Jira-validated already.

- [ ] **Step 1: Write the e2e spec** (model on `e2e/admin/admin-ui.spec.ts` gating test). Run: `npx playwright test e2e/worklog-sync.spec.ts` (needs `make e2e` stack up; if the stack is unavailable in the task environment, write the spec and note it runs in CI — do not fake a pass).

- [ ] **Step 2: ADR + docs**
  1. ADR-023 status → `Accepted — fully implemented (Phases 1–4).`
  2. §6 note: `Implementation note (Phase 4b): the SolidJS UI ships the self-service import (Settings), the admin sync area (run history, triggers, ticket-system sync config), and the conflict-resolution screen, all against the v2 endpoints; a list-runs endpoint was added to back the run history.`
  3. README index row → `Accepted (fully implemented)`.
  4. `docs/worklog-sync.md` — add a short "UI" section pointing operators to Settings (self-service) and the admin sync area (runs + conflicts).

- [ ] **Step 3: Commit** — `docs(adr): ADR-023 fully implemented; worklog sync UI`.

---

## Notes for the implementer

- **Read before writing:** `frontend/AGENTS.md`, `src/components/SecuritySection.tsx` (multi-step flow template for Task 4), `src/pages/AdminStatus.tsx` (non-CRUD admin sub-page template + registration for Task 6), `src/admin/entities.ts` ticketsystems descriptor (Task 5), `src/api/queries.ts` (query-factory + option-source conventions), `src/api/client.ts` (getJson/postJson/apiErrorMessage), `src/test/renderWithProviders.tsx` (test harness).
- **i18n discipline:** all five catalogs must have identical keys or `typecheck` fails. Add keys as you introduce them; use natural German (technical register) for `de.json`.
- **No `useMutation`** — every write is `postJson` + `invalidateQueries`. **No Tailwind utilities** — extend `app.css` with the `is-<state>` convention.
- The engine is already validated (unit + functional + real-Jira). Phase 4b is presentation only — no new sync logic; the UI just drives the four endpoints.
