# ADR-023: Jira Worklog Import and Bidirectional Sync

**Status:** Proposed — design approved 2026-07-09, implementation not started.
**Date:** 2026-07-09
**Relates to:** [ADR-003](ADR-003-jira-integration-architecture.md) (push-sync architecture this ADR extends to a pull/reconcile model), [ADR-017](ADR-017-jira-cloud-oauth2.md) (Server/Cloud dual auth the I/O layer reuses), [ADR-020](ADR-020-subticket-ticket-resolution.md) (ticket→project resolution used for imported worklogs; precedent for pull-direction sync and designated-token auth), [ADR-021](ADR-021-api-token-authentication.md) (PAT scopes for the new endpoints), [ADR-022](ADR-022-v2-api-layer-and-response-dtos.md) (v2 API + MCP tool pattern the new surfaces follow).

## Context

Worklog synchronization is currently one-directional: TimeTracker pushes entries to Jira (`EntryEventSubscriber` → `JiraOAuthApiService`, inline in the save/delete request). There is no path from Jira into TimeTracker. Four use cases require the reverse direction:

1. **First-time import** — a new TT user wants their existing Jira worklog history in TT.
2. **Ongoing sync** — worklogs added or edited directly in Jira must flow into TT continuously.
3. **PO import** — a PO imports worklogs for colleagues who do not use TT, so company-wide analysis covers everyone.
4. **Verification** — answer "are TT and Jira consistent?" for a user/team/period, with an auditable report.

The strategic goal: TT as the central worklog hub — the single place for real-time worklog analysis (paid vs unpaid work, projected vs actual) and the agentic interface (MCP) to all work logging.

Existing building blocks: `Entry.worklogId` + `Entry.ticket` anchor worklog identity; `Entry.syncedToTicketsystem` flags push state; `UserTicketsystem` holds per-user OAuth tokens (no service accounts exist); a refactored Jira service stack (`JiraHttpClientService`, `JiraWorkLogService`, `JiraWorkLog::fromApiResponse()`) exists but is not container-wired for sync (deferred by ADR-003 pending token encryption, which has since landed as `TokenEncryptionService`); background work is cron console commands (no Messenger/queue); TT flags overlapping entries (`EntryClass::OVERLAP`) but does not reject them.

Structural gaps a pull direction must solve:

- A TT entry requires **project, customer, activity**; a Jira worklog carries only issue key, started, timeSpentSeconds, comment, author.
- A worklog edited on both sides needs deterministic conflict semantics; the current push blindly overwrites Jira ("silent clobber").
- An imported entry that fires the normal `EntryEvent::CREATED` would push itself back to Jira as a duplicate.
- Jira worklog authors (accountId/username) must map to TT users, including people with no TT account.

## Decision

### 1. Truth model: last-write-wins guarded by a lease (compare-and-swap), not a fixed source of truth

Neither side is globally authoritative. Either side may edit a worklog; every **remote write requires a lease check**: the write proceeds only if the remote worklog's `updated` timestamp still equals the last-synced base state. A mismatch means a sync is missing — the item is re-reconciled with fresh remote data first (git's "pull before push").

Jira's REST API has no conditional writes (`If-Match`), so the lease is **read-compare-then-write** (GET worklog → compare `updated` → PUT/DELETE). The ~seconds race window between compare and write is accepted and documented; it converts today's silent clobber into a bounded race for human-timescale edits.

### 2. Reconciliation core: persisted three-way diff, field-scoped

A new table `worklog_sync_state` (1:1 with linked entries) persists the lease base:

| Column | Purpose |
|---|---|
| `entry_id` (FK, unique, cascade) | link to `entries` |
| `ticket_system_id` (FK) | owning Jira |
| `base_payload` (json) | remote worklog as of last sync (issue key, started, timeSpentSeconds, comment, author) — the three-way-diff base |
| `base_updated_at` | Jira `updated` at last sync — the CAS comparand |
| `status` | `in_sync` \| `conflict` \| `orphaned` |
| `conflict_remote_payload` (json, nullable) | remote version snapshotted when a conflict was parked |
| `last_synced_at`, `last_sync_run_id` | audit anchors |

`local_dirty` (entry's Jira projection ≠ base) and `remote_dirty` (fetched remote ≠ base) are computed at reconcile time, not stored. `Entry.worklogId` remains the canonical identity link.

**Synced field set** (exists on both sides): issue key, work date + start time (`started`), duration (TT minutes ↔ `timeSpentSeconds`), description ↔ worklog comment. Activity, project, customer are TT-only and never participate in conflict detection.

**Decision matrix**, per linked worklog:

| local vs base | remote vs base | action |
|---|---|---|
| clean | clean | nothing (`in_sync`) |
| dirty | clean | push local → Jira (lease-checked), refresh base |
| clean | dirty | pull remote → entry, refresh base |
| dirty | dirty, disjoint fields | merge: push local-changed fields, pull remote-changed fields |
| dirty | dirty, same field | park as `conflict`, snapshot remote, no writes |
| any | remote gone (404 / deleted-list) | move detection first (same author+started+duration under another issue → re-link and reprocess); no match: local clean → delete entry, local dirty → park as `orphaned` |

**Conflict policy: park for a human.** True conflicts never auto-resolve; they appear in the reconciliation report, the conflict UI, and via MCP. Resolution (`local` or `remote` wins, optionally per field) re-enters the engine as a forced, lease-checked write.

**Unmatched remote worklog = import.** Author is mapped (see §3), project resolved via ticket→project resolution (ADR-020; unresolvable → parked item), activity set from the run's configured default, and the entry is created **pre-marked synced** (`worklogId` set, `syncedToTicketsystem = true`, fresh sync state) with the push event suppressed — imports cannot echo back to Jira.

**Normalization rules** prevent false dirties: defined rounding direction for minutes↔seconds, timezone-normalized `started` comparison, whitespace/encoding-normalized comment comparison. The diff must use the exact projection the push writes into the Jira comment (verify the current comment format during implementation, or nothing will ever be `in_sync`).

**Pre-existing duplicates:** before creating an entry from an unmatched worklog, an unlinked TT entry with the same user + ticket + day + duration parks the item as **probable duplicate** (human confirms: link or import anyway).

The reconciliation core is a pure service — `(base, local, remote) → action` — with no Jira dependency, so the entire matrix is unit-testable. **Verify is the same engine with writes disabled** (dry-run), guaranteeing the verification report always agrees with what sync would do.

### 3. Author mapping: auto-match with shadow users

`UserTicketsystem` gains a `remote_account_id` column (Jira accountId / username). Auto-match by username/email fills it on first sight; thereafter it is the persistent mapping table. Authors with no TT account get a **shadow user**: a regular `users` row flagged non-login-capable, with a `UserTicketsystem` row carrying `remote_account_id` and no tokens. Shadow users make non-TT-users' time bookable and analyzable (use case 3); an admin view lists them.

### 4. Run model: `sync_run` + `sync_run_item`, chunked and resumable

- `sync_run`: type (`import` | `sync` | `verify`), triggering user, ticket system, scope (users, date range, `dry_run`, default activity), timestamps, status, counters (created / updated-local / updated-remote / deleted / conflicts / parked).
- `sync_run_item`: only noteworthy outcomes — parked (unresolved project, probable duplicate, conflict, orphan), errors, deletions — with issue key, worklog id, author, reason, payload. Routine successes are counters only.

There is no queue in the stack, so runs execute inline in the request but are **chunked and resumable**: a run processes up to N items, persists its continuation position on the `sync_run` row, and returns `status: partial`; the caller (UI, agent, cron) re-invokes to continue. A future queue can replace the loop without changing the API. Items are independent: an item error is recorded and the run continues. Base state updates only after a successful write, and identity matching by `worklog_id` makes re-runs idempotent.

The incremental cursor (see §5) re-reads a small overlap window (~5 min) to avoid boundary misses; idempotency makes the overlap free. The lease only compares Jira `updated` against Jira `updated` — immune to TT↔Jira clock skew.

### 5. Jira I/O: refactored read stack, one lease-checked write path

Reads go through the refactored stack (`JiraHttpClientService` / `JiraWorkLogService`), which gets container-wired (the ADR-003 token-encryption blocker is stale; confirm during implementation). Three read methods:

1. `getWorklogsUpdatedSince(cursor)` — `GET /worklog/updated` + `POST /worklog/list` (1000-ID chunks) + `GET /worklog/deleted`: the incremental path for ongoing sync. Cursor stored per ticket system (`ticket_systems` column), advanced only after a completed run.
2. `getWorklogsByAuthorAndRange(author, from, to)` — JQL `worklogAuthor = X AND worklogDate >= … AND <= …`, paginated, per-issue worklog fetch filtered to the author: first-time and PO import.
3. `getIssueWorklogs(issueKey)` — move detection and targeted verify.

Server/Cloud differences remain in the ADR-017 `DeploymentType` branch; pagination and 429 backoff live once in the HTTP client.

**Writes: one path.** A new lease-checked `WorklogWriteService` performs all remote writes. `EntryEventSubscriber`'s inline push is upgraded to delegate to it: on lease failure the local save still succeeds, but instead of overwriting Jira the entry is left for the next sync (pull or parked conflict). One write discipline across UI saves, MCP edits, and sync runs.

**Token selection:**

- On-demand import/verify → the **triggering user's** token (a PO import runs under the PO's Jira visibility — the correct authorization boundary).
- Cron incremental sync → a **designated sync user** configured per ticket system (a TT user with connected OAuth and broad Jira browse permission; ADR-020's project-lead-token precedent).
- Writes → the **entry owner's** token when connected; fallback to the sync user (requires Jira "Edit All Worklogs"). Shadow users never have tokens; their entries write via the sync user or stay TT-only when `bookTime` is off.

### 6. Surfaces (all thin adapters over the one engine, per ADR-022)

- **Console:** `tt:sync-worklogs [--ticket-system=X] [--dry-run] [--since=]` — cron heartbeat for ongoing sync, modeled on `tt:sync-subtickets`.
- **v2 REST** (PAT scopes `worklog-sync:read`, `worklog-sync:run`, `worklog-sync:resolve`):
  - `POST /api/v2/worklog-sync/runs` — start a run `{type, ticket_system_id, users?, from?, to?, dry_run?, default_activity_id?}`
  - `GET /api/v2/worklog-sync/runs/{id}` — status, counters, parked items (the import report)
  - `GET /api/v2/worklog-sync/conflicts` — parked conflicts/orphans
  - `POST /api/v2/worklog-sync/conflicts/{id}/resolve` — `{winner: local|remote}`
- **MCP tools:** `sync_jira_worklogs` (type parameter covers import/sync/verify, plus `dry_run`), `get_sync_run`, `list_sync_conflicts`, `resolve_sync_conflict` — the agentic-hub interface.
- **UI (SolidJS):** Settings → Jira self-service import (date range, default activity, dry-run preview → execute); admin/PO area (import for selected users, run history/reports, side-by-side conflict resolution using `conflict_remote_payload`); ticket system admin form (sync user picker, cursor display/reset).

### 7. Edge-case rules

- Worklogs on projects with `bookTime` off import normally; they are simply never pushed back.
- Zero-duration worklogs are skipped as items.
- Expired/broken tokens fail the run cleanly with an item pointer; the user re-authorizes via the existing OAuth redirect flow.
- Imported entries with Jira's often-arbitrary `started` times may overlap existing entries; TT already renders these via `EntryClass::OVERLAP` and does not reject them.
- Interaction with the internal-ticket-system mirror (`internalJiraTicketOriginalKey`) — which Jira an imported entry reconciles against when a project mirrors tickets — must be resolved during implementation planning.

## Alternatives considered

- **TT as fixed source of truth** (import adds only; divergence always resolved by push): rejected — worklogs imported from Jira keep changing in Jira, and TT edits (e.g. moving a worklog to another issue) must flow outward; per-side ownership blocks both.
- **Per-worklog origin tracking** (TT-born entries push-only, Jira-born entries pull-only): rejected — imported worklogs must remain editable in TT.
- **Unguarded last-write-wins by timestamp:** rejected — classic silent data loss; the lease turns it into detected divergence.
- **Minimal extension of the legacy service** (columns on `entries`, logic in `JiraOAuthApiService`): rejected — the lease requires persisted base state, and parked conflicts/run reports need a home; a false economy.
- **Webhook/queue real-time layer:** deferred, not rejected — worklog webhooks would trigger the same incremental sync; can be added on top without changing the engine.
- **Auto-create missing projects / catch-all fallback project** for unresolvable issues: rejected — pollutes curated master data or hides garbage; parked items keep a human in the loop.

## Consequences

- Two new tables (`worklog_sync_state`, `sync_run` + `sync_run_item`), new columns on `users_ticket_systems` (remote account id), `ticket_systems` (sync cursor, sync user), and a shadow-user flag on `users`.
- The refactored Jira service stack becomes production-wired; the legacy `JiraOAuthApiService` push loses its "only wired path" status, with all writes converging on the lease-checked service.
- Saving an entry in TT no longer silently overwrites Jira-side edits — behavior change: such saves now surface as sync conflicts instead of clobbering.
- Verify runs are auditable artifacts (`sync_run` rows), not transient computations.
- A per-ticket-system **sync user** becomes an operational requirement for ongoing cron sync (and needs "Edit All Worklogs" in Jira for fallback writes).
- Testing: table-driven unit tests over the pure reconciliation matrix and normalization goldens; functional tests with mocked Jira HTTP for lease paths, chunk/resume, cursor advancement, shadow-user creation, and push-event suppression. The e2e stack has no Jira; e2e coverage limited to UI flows against stubbed responses.

## Verification points before implementation

1. ~~Exact comment/description projection the current push writes to Jira~~ **Resolved (Phase 1):** `#<entryId>: <activityName>: <description>` with fallbacks `no activity specified` / `no description given` (`JiraOAuthApiService::getTicketSystemWorkLogComment`). `WorklogCommentCodec` reproduces it; the entry ID embedded in every pushed comment is a secondary identity anchor.
2. `User.type` semantics vs a new shadow-user flag.
3. ~~Container wiring status of the refactored stack~~ **Resolved (Phase 1), decision amended:** the refactored stack is container-excluded AND writes a different comment format (`Customer | Project | Activity | description`) than production. Phase 1 therefore puts read methods on the legacy `JiraOAuthApiService` (wired, dual Server/Cloud via `JiraCloudApiService`) instead of wiring the refactored stack. Revisit when the lease-checked write service lands (Phase 3).
4. Internal-ticket-system mirror interaction (§7).
5. Jira Server/DC vs Cloud availability and pagination behavior of `worklog/updated`, `worklog/list`, `worklog/deleted` on the instances in use.
