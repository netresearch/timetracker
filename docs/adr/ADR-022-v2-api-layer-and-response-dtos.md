# ADR-022: v2 API layer with typed response DTOs

**Status:** Accepted
**Date:** 2026-07-06
**Relates to:** [ADR-016](ADR-016-solidjs-frontend-rewrite.md) (the SolidJS SPA that consumes these endpoints and whose rewrite was done against a deliberately *stable* v1 API), [ADR-021](ADR-021-api-token-authentication.md) (scoped PATs + the MCP tools that now share logic with the UI), issue [#573](https://github.com/netresearch/timetracker/issues/573) (MCP list tools broken by top-level JSON arrays), and the agent-readiness work ([docs/agent-readiness.md](../agent-readiness.md)).

## Context

ADR-021 Phase 5 added two services — `TimeBalanceService` (today/week/month IST-vs-SOLL) and `EntrySummaryService` (an entry's per-scope "Info" totals) — extracted so the new MCP tools would not reimplement existing logic. That extraction surfaced a latent problem: the logic now lives in **two** places.

- `TimeBalanceService` and `GetTimeSummaryAction::targetMinutes()` (`GET /getTimeSummary`) compute the same thing from the same sources (`EntryRepository::getWorkByUser`, `ExpectedWorkTimeCalculator::minutesForRange`, contracts minus holidays, SOLL "through today").
- `EntrySummaryService` and `GetSummaryAction` (`POST /getSummary`) both build the 4-scope skeleton and call `EntryRepository::getEntrySummary`. `EntrySummaryService` additionally scopes the summary to the caller's own entry (an IDOR fix, #570); `GetSummaryAction` does **not** — the same cross-user disclosure is live on the web endpoint and reachable by any session user or PAT with `reporting:read`.

Three further constraints shape the decision:

1. **The v1 API is intentionally frozen.** ADR-016 rebuilt the frontend against a stable API surface; the existing endpoints return ad-hoc arrays serialized with `new JsonResponse($array)`, mixing camelCase (`durationMinutes`) and snake_case keys. There is **no response-DTO convention** — even `DatabaseResultDto` returns `array<string,mixed>`. Retrofitting DTOs onto v1 would break that stability contract.
2. **The absence of wire-format rules already caused a production bug.** #573: three MCP tools return top-level JSON **arrays**; MCP requires `structuredContent` to be a JSON **object**, so strict clients reject every call — the tools are unusable. A stated "top-level object, always" rule would have prevented it.
3. **New endpoints are coming anyway.** ADR-021 anticipated `/api/v2/*` endpoints "where BC blocks", and the pending admin on/offboarding tools need write endpoints. New endpoints are the place to fix the shape, not the frozen v1 surface.

## Decision

### 1. A versioned `/api/v2` layer for new and reworked endpoints

New endpoints — and reworked equivalents of frozen v1 endpoints — live under an `/api/v2` route prefix (version in the path; resource names in kebab-case). v1 stays as-is for backward compatibility and is deprecated per §5. v2 is additive; it is not a big-bang rewrite of all ~54 endpoints, only of the surfaces we actively touch.

No firewall or routing changes are needed: the ADR-021 token firewall is **header-based** (it claims any request carrying `Authorization: Bearer tt_pat_…`, regardless of path), sessions fall through to the `main` firewall, and the `access_control` catch-all (`^/` → `IS_AUTHENTICATED_FULLY`) already covers `/api/v2`.

### 2. Services are the single source of truth; v2, MCP, and the UI all consume them

Business logic lives in a service exactly once. A v2 controller is a thin adapter: authenticate/authorize, call the service, serialize the result. An MCP tool is another thin adapter over the same service. This removes the duplication rather than adding a third copy.

The two existing services are **refactored to return typed DTOs** (see §3). Both consumers (v2 REST and MCP tools) serialize the same DTO, so REST and MCP cannot drift.

### 3. Typed response DTOs for every v2 endpoint

Every v2 response is a `final readonly` DTO in `src/Dto/Response/`, implementing `JsonSerializable` with an explicit `jsonSerialize()` (no serializer configuration, deterministic output, fully type-checked — this also removes the PHPStan sealed-array-shape juggling the array style forces). Request bodies continue to use the established `*SaveDto` request-DTO pattern.

Arguments against (more classes, explicit serialization) are accepted: the v1 "arrays everywhere" style was a *frozen-API concession*, not a preference to preserve.

### 4. v2 wire-format conventions

- **JSON keys are `snake_case`.** The canonical field names are the ones the MCP tools already shipped (ADR-021 Phase 5) — the DTOs adopt them verbatim, so introducing DTOs changes **no** wire output on the MCP side:
  - time balance: `today` / `week` / `month`, each `{ist, soll_total, soll_so_far, diff, status}`, plus `warnings`;
  - entry summary: `customer` / `project` / `activity` / `ticket`, each `{scope, name, entries, total, own, estimation}`, plus `estimate {estimation, booked_total, percent, status}` and `warnings`.
- **Every response body is a top-level JSON object — never a bare array** (the #573 rule; MCP `structuredContent` requires it, and it keeps every payload extensible). Lists are wrapped: `{"projects": […]}`, `{"entries": […]}`.
- **Errors** are `{"message": string}` with a proper HTTP status (401/403/404/422/…), matching the existing `App\Response\Error` shape the SPA client already understands.
- **Not-owned resources read as not-found (404), not forbidden (403)** — no existence disclosure through status differences.
- Aggregates within an owned entry's summary intentionally span all users (`total` vs the caller's `own`) — that is the feature, scoped to entries the caller owns.

### 5. Deprecate superseded v1 endpoints

When a v2 endpoint fully supersedes a v1 one, the v1 controller gets an `@deprecated` docblock pointing to the v2 route, `deprecated: true` in `public/api.yml`, and a `Deprecation: true` response header (RFC 9745 style) so API consumers see it at call time. Superseded v1 endpoints are **removed in the next major release (v7.0)**. The SPA is migrated to v2 in the same change that introduces the superseding endpoint, so no endpoint is deprecated while still in use by our own frontend.

**Security exception to the v1 freeze:** the v1 freeze protects consumers from *shape* changes; it does not protect vulnerabilities. The `/getSummary` ownership check (IDOR) is backported to v1 in Phase 1 — a foreign entry id answers 404 exactly like v2. This is a deliberate behavior change on a deprecated endpoint.

### 6. Scope + role enforcement unchanged

v2 endpoints carry `#[RequireScope('resource:action')]` (ADR-021) and, for admin resources, `#[IsGranted('ROLE_ADMIN')]` — both gates, exactly as v1. `RequireScopeCoverageTest` scans all of `src/Controller` recursively, so v2 controllers are covered automatically; the fail-closed subscriber denies tokens on any unannotated controller.

## Initial v2 surface (implemented incrementally)

| v2 endpoint | Backed by | Supersedes |
|---|---|---|
| `GET /api/v2/time-balance` | `TimeBalanceService` | `GET /getTimeSummary` |
| `GET /api/v2/entries/{id}/summary` | `EntrySummaryService` (owner-scoped) | `POST /getSummary` |
| `GET /api/v2/day` | new day-summary service | — (new: today's entries + budgets) |
| `POST /api/v2/projects`, `/customers`, `/users` + activate/deactivate | admin services | new (on/offboarding) |

## Alternatives considered

- **Retrofit DTOs onto v1 in place.** Rejected: breaks the ADR-016 stable-API contract the SPA was built against, and mixes a shape change into endpoints that don't otherwise need to change.
- **Keep services array-based; wrap in DTOs only at the v2 controller.** Rejected: leaves two shapes (array from the service, DTO from the controller) and lets the MCP path diverge from REST. Refactoring the service to return the DTO gives one contract.
- **Invent new v2 field names and re-key the MCP tools to match.** Rejected: the MCP wire surface shipped days ago; re-keying it is a gratuitous break. The DTOs adopt the existing MCP keys instead (§4).
- **No versioning — just add new unprefixed endpoints.** Rejected: no clean deprecation story and no signal to consumers about the shape/coverage difference.
- **GraphQL / a full API redesign.** Out of scope; this is an incremental, per-endpoint migration, not a platform change.

## Consequences

- **Duplication removed:** the balance and entry-summary logic exists once, in the services.
- **IDOR closed everywhere:** v2 `GET /api/v2/entries/{id}/summary` is owner-scoped, and the check is backported to v1 `/getSummary` (§5) — the disclosure does not survive Phase 1 on either surface.
- **Two response conventions coexist during migration:** v1 arrays (frozen, deprecated) and v2 DTOs. Intentional and bounded to the endpoints we migrate.
- **MCP wire output is unchanged** by the DTO refactor (§4); the #573 fix (wrapping list-tool results in objects) is the only MCP-visible change, and it is a bug fix strict clients require.
- **Known consumers to migrate/update in Phase 1** (verified by repo sweep): `frontend/src/header.ts`, `frontend/src/pages/Tracking.tsx` (+ `Tracking.test.tsx`), `e2e/helpers/api.ts`, `e2e/interpretation.spec.ts`, `tests/Controller/DefaultControllerSummaryTest.php` (uses `findOneBy([])` — must pin entry ownership once v1 is owner-scoped), `tests/Api/Functional/EndpointAvailabilityTest.php`, `tests/Api/Functional/ResponseFormatTest.php`, `tests/Controller/ApiTokenAuthTest.php` (keep probing v1 while it exists), `public/api.yml`, `docs/api.md`.
- **Each v2 endpoint owns a response DTO** and an OpenAPI entry; the frozen v1 spec stays accurate for un-migrated endpoints.

## Implementation phases

0. **#573 hotfix** (standalone PR, precedes the rest): wrap the three MCP list-tool results in objects (`{"projects": […]}`, `{"activities": […]}`, `{"entries": […]}`) + a regression test that every registered MCP tool returns a top-level object.
1. **v2 read endpoints + DTO refactor + UI migration** (one PR, per the sequencing decision): `GET /api/v2/time-balance` and `GET /api/v2/entries/{id}/summary` with response DTOs (wire keys per §4); refactor `TimeBalanceService`/`EntrySummaryService` to return those DTOs; MCP tools serialize the DTOs (output unchanged); backport the ownership check to v1 `/getSummary`; migrate the SPA and the consumers listed above; deprecate `/getTimeSummary` + `/getSummary` per §5.
2. **Day-so-far**: `GET /api/v2/day` + a `get_day` MCP tool + `log_time` returning the day's entries and budgets after a booking (all top-level objects per §4).
3. **Admin on/offboarding**: v2 write endpoints for project/customer/user create + activate/deactivate, with request/response DTOs, `ROLE_ADMIN` + scope (both gates), and MCP admin tools.
4. **Edit, bulk, list, manage** (closing the agent-capability gaps): `PATCH /api/v2/entries/{id}` + `update_entry` (partial merge, owner-scoped), `POST /api/v2/bulk-entries` + `bulk_log_time` (preset-based, delegating to the v1 bulk action), read tools `list_customers/users/teams/presets/ticketsystems/contracts` (admin resources double-gated; ticket-system credentials never returned), and admin upsert tools `save_team/save_contract/save_ticketsystem` (create + update only — deletion is deliberately not exposed; retiring a record means deactivating it where an active flag exists).
