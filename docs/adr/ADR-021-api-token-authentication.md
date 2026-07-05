# ADR-021: API Token Authentication with Fine-Grained Scopes

**Status:** Accepted — implementation phased (see end). Phases 1 (schema + token service + CLI), 2 (Bearer firewall + authenticator + #[RequireScope] voter, fail-closed), 3 (Settings token-management UI + i18n), and 4 (read endpoints annotated with #[RequireScope]; OpenAPI bearer securityScheme + scope model; coverage test) done; Phase 5 (native MCP server; agent-skills.json dropped) in progress.
**Date:** 2026-07-04
**Relates to:** [ADR-011](ADR-011-security-architecture.md) (session-based auth this
extends), [ADR-018](ADR-018-authentication-extension.md) (the auth stack — local
passwords, MFA, passkeys — that tokens sit beside), [ADR-017](ADR-017-jira-cloud-oauth2.md)
(existing OAuth2 *client* pattern for Jira), and the agent-readiness work
([docs/agent-readiness.md](../agent-readiness.md), PR #549) that exposed the API for
*discovery* but not programmatic *use*.

## Context

The HTTP API (`public/api.yml`, ~54 endpoints) is authenticated **only** by the login
session cookie. An agent or script can discover and read the OpenAPI (via the well-known
`api-catalog`), but it cannot **authenticate** without driving a browser login — so it
cannot actually call the API. This is the blocker for "coding-agent / LLM support": an
agent that logs the time it spent on a ticket, a reporting agent, a CI job.

We want programmatic auth that (a) acts on behalf of a specific user, (b) is revocable,
(c) can be **narrowed** to least privilege — the maintainer chose **fine-grained,
OAuth2-style scopes** over a coarse "token = full user access" model — and (d) does not
weaken the existing session/MFA story for humans.

## Decision

### 1. Personal Access Tokens, bounded by the owning user

Tokens are **user-bound PATs**. A request authenticated by a token acts as that user. A
token's **effective permission is the intersection of the user's roles and the token's
scopes** — scopes can only *narrow*, never *expand*, what the user may already do. A
token minted by a non-admin can never gain admin power by carrying an admin scope; the
existing `#[IsGranted(ROLE_*)]` checks still run (defense in depth).

### 2. Scope taxonomy: `resource:action`

Scopes are `resource:action`, `action ∈ {read, write}`, over the API's resource areas:

| Resource | Endpoints (examples) |
|---|---|
| `entries` | `/tracking/save`, `/tracking/delete`, `/tracking/bulkentry`, `/getData` |
| `projects`, `customers`, `activities`, `presets`, `teams`, `users`, `contracts`, `ticketsystems` | the admin CRUD + `/getAll*` reads |
| `reporting` | `/interpretation/*`, `/getSummary`, `/controlling/export` |
| `settings` | the caller's own `/settings` |
| `sync` | `/syncentries/jira`, project subticket sync |

`read` covers a resource's read/query operations and `write` its create/update/delete —
by operation **semantics**, not HTTP method (some reads, e.g. `/getSummary`, are `POST`).
Admin-only resources (`users`, `ticketsystems`, …) additionally require the user to hold
the admin role — the scope alone is insufficient. A wildcard `*` (all scopes the user can
grant) is allowed for convenience but discouraged in the UI.

### 3. Opaque, hashed tokens

A token is `tt_pat_` followed by 64 hex characters (32 random bytes) — opaque and
high-entropy. Only a **SHA-256 hash** of the whole string is stored; the plaintext is shown
**once** at creation. The `tt_pat_` prefix enables secret-scanning; logs may carry the token
row's database id but never the token itself. No JWT — opaque tokens are trivially
**revocable** and carry no self-asserted claims.

### 4. Stateless Bearer firewall + scope enforcement

- A dedicated **stateless firewall** matches the data-API paths (an **anchored** path
  pattern — never a loose prefix that a look-alike route could slip through) and accepts
  `Authorization: Bearer tt_pat_…`. The SPA/login/2FA firewalls are unchanged; humans keep
  cookies + MFA, tokens never touch the login or 2FA routes.
- A custom **authenticator** hashes the presented token and looks up that SHA-256 hash
  (the plaintext is never compared directly, so there is no per-request secret to
  time-attack), rejects if missing/expired/revoked, records use (see §7), and authenticates
  as the owning user with the token's scopes attached to the security token.
- **Enforcement** is a `#[RequireScope('entries:write')]` controller attribute backed by a
  voter: the request is denied `403` unless the token grants the scope. Session (cookie)
  requests bypass the scope check (a human in the SPA is not scope-limited) — scopes gate
  *token* auth only.

### 5. Storage

New table `api_tokens`: `id`, `user_id` (FK, `ON DELETE CASCADE`), `name`, `token_hash`
(unique), `scopes` (JSON array), `expires_at` (nullable), `last_used_at` (nullable),
`created_at`, `revoked_at` (nullable). One additive migration; no change to existing tables.

### 6. Management surface

- **Settings UI** (ADR-016 SPA): a "Personal access tokens" section — create (name,
  scope checkboxes grouped by resource, optional expiry), list (name, scopes, last used,
  expiry; never the token), and revoke. The plaintext is shown once with a copy button.
- **CLI**: `app:api-token:create <user> <name> --scope … [--expires …]` for bootstrap/cron.

### 7. Security

Hashed at rest; recognizable prefix for secret-scanning; `last_used_at` for audit and
stale-token cleanup, written **coarsely** (at most once per few minutes) so authentication
does not incur a row write on every request; optional expiry (recommend a default, e.g.
90 days, overridable);
per-token revocation; rate limiting on the Bearer firewall; tokens excluded from logs and
from every API response. A token cannot be used to change auth state (password, MFA,
passkeys) — those stay session+re-auth only, out of the token firewall.

## Alternatives considered

- **Coarse "token = full user access"** (the recommended-but-not-chosen v1): simplest, but
  the maintainer wants least-privilege from the start. Adopted the scoped model instead.
- **JWT / self-contained tokens:** stateless validation, but **not revocable** before
  expiry and claims are self-asserted — rejected for a credential that must be killable.
- **Full OAuth2 authorization-code / client-credentials:** warranted if third-party apps
  needed delegated access; overkill while the consumers are the user's own agents/scripts.
  The PAT model is a subset we can grow into OAuth2 later without breaking tokens.
- **Status quo (session only):** blocks every programmatic/agent use case — the reason for
  this ADR.

## Consequences

- **Unlocks the deferred agent work**: a native **MCP server** (Phase 5) exposes curated,
  scoped, *callable* skills (flagship: "log time on a ticket") to coding agents over the
  token auth. (The originally-planned `agent-skills.json` manifest was dropped — see Phase 5.)
- **New attack surface** (a bearer credential): mitigated by hashing, scopes, expiry,
  revocation, rate limiting, and keeping auth-state changes off the token firewall.
- **Scope maintenance**: every new API endpoint must declare its required scope; a missing
  declaration must **fail closed** (deny token access) — enforced by a test that every
  data route under the Bearer firewall has a `#[RequireScope]`.
- **OpenAPI**: `securitySchemes` gains a `bearer` scheme and per-operation scope docs, so
  the published spec is accurate for agents.

## Implementation phases (post-acceptance)

1. Schema + entity + token service (generate, hash, verify, revoke) + CLI.
2. Bearer firewall + authenticator + `#[RequireScope]` voter + the fail-closed route test.
3. Settings UI (create/list/revoke) + i18n. **Done** — session-only endpoints
   under `/settings/api-tokens` (fail-closed against Bearer) + the SPA section.
4. OpenAPI `securitySchemes` + per-endpoint scopes; docs/agent-readiness.md update.
   **Done** — read endpoints (customers/projects/users/teams/presets/contracts/
   ticketsystems/reporting/entry) annotated `#[RequireScope('resource:read')]`;
   OpenAPI gained the `bearerAuth` scheme + scope model in the description; a
   coverage test validates every declared scope and guards the count. Per-operation
   scope tags are intentionally NOT duplicated into the static YAML — the code's
   `#[RequireScope]` is the single source of truth. Holidays/admin-status/jira-sync
   left fail-closed (not token-facing).
5. **Native MCP server** (see "Phase 5" below). The originally-planned
   `/.well-known/agent-skills.json` is **dropped** — 2026 research found no
   client-consumed standard for a callable-skill manifest (`ai-plugin.json` is
   dead, `llms.txt` is a docs pointer, Anthropic "Agent Skills" is a *local*
   SKILL.md packaging format, the Cloudflare RFC never converged). The one
   convergent standard for callable actions is **MCP**, whose auth spec
   explicitly sanctions static scoped Bearer tokens — our PATs drop straight in.

## Phase 5: Native MCP server

**Decision (2026-07-05):** expose the API to coding agents (Claude Code / Cursor)
as a **native Symfony MCP server** over **Streamable HTTP at `/mcp`**, reusing the
Phase-1/2 PAT auth and scopes. Chosen over a Python/FastMCP sidecar for
single-codebase auth reuse and one deployment. Deps: `symfony/mcp-bundle`
(v0.10.0) + `mcp/sdk` (v0.6.0) — tagged (pre-1.0) releases, resolve cleanly on
PHP 8.5 / Symfony 8.1.

- **Transport/endpoint:** Streamable HTTP at `/mcp` (stdio is for local
  processes; a hosted app serves HTTP). Agents connect with their PAT as Bearer.
- **Auth:** `/mcp` behind the existing stateless Bearer firewall (extend its path
  pattern). Sessions never reach it. Each tool declares a required scope, enforced
  via `ApiScope::grants()` ∩ the user's roles — the same fail-closed rule as
  `#[RequireScope]`.
- **Curated toolset** (thin wrappers over existing services — no reimplementation;
  research shows per-endpoint auto-generation underperforms): `log_time`
  (`entries:write`, flagship), `list_recent_entries` (`entries:read`),
  `list_projects` (`projects:read`), `list_activities` (`activities:read`),
  `delete_entry` (`entries:write`). Optional `get_summary` (`reporting:read`).
- **New v2 endpoints where BC blocks:** if an existing endpoint's shape can't serve
  a clean tool without breaking the SPA, add a `/api/v2/*` endpoint for the tool to
  call rather than mutate the BC surface.
- **Discovery + doc-drift:** add `/.well-known/mcp/server.json` (emerging
  server-card) pointing at `/mcp`; fix the stale `llms.txt` (it still claims "no
  API token yet"); update `docs/agent-readiness.md`.
- **Tests:** `/mcp` rejects missing/invalid PAT; each tool happy-path + a
  scope-denied path; a coverage test that every registered MCP tool declares a
  scope (fail-closed), mirroring `RequireScopeCoverageTest`.
