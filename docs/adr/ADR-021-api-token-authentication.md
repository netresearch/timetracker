# ADR-021: API Token Authentication with Fine-Grained Scopes

**Status:** Accepted — implementation phased (see end); Phase 1 (schema + token service + CLI) in progress.
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

`read` covers the `GET`/`getAll*` reads of a resource; `write` covers create/update/delete.
Admin-only resources (`users`, `ticketsystems`, …) additionally require the user to hold
the admin role — the scope alone is insufficient. A wildcard `*` (all scopes the user can
grant) is allowed for convenience but discouraged in the UI.

### 3. Opaque, hashed tokens

A token is an opaque high-entropy string with a recognizable prefix: `tt_pat_<32+ bytes
base62>`. Only a **SHA-256 hash** is stored; the plaintext is shown **once** at creation.
The prefix enables secret-scanning and safe logging (log the prefix + id, never the token).
No JWT — opaque tokens are trivially **revocable** and carry no self-asserted claims.

### 4. Stateless Bearer firewall + scope enforcement

- A dedicated **stateless firewall** matches the data-API paths and accepts
  `Authorization: Bearer tt_pat_…`. The SPA/login/2FA firewalls are unchanged; humans keep
  cookies + MFA, tokens never touch the login or 2FA routes.
- A custom **authenticator** hashes the presented token, looks it up (constant-time by
  hash), rejects if missing/expired/revoked, stamps `last_used_at`, and authenticates as
  the owning user with the token's scopes attached to the security token.
- **Enforcement** is a `#[RequireScope('entries:write')]` controller attribute backed by a
  voter: the request is denied `403` unless the token grants the scope. Session (cookie)
  requests bypass the scope check (a human in the SPA is not scope-limited) — scopes gate
  *token* auth only.

### 5. Storage

New table `user_api_tokens`: `id`, `user_id` (FK), `name`, `token_hash` (unique),
`scopes` (JSON array), `expires_at` (nullable), `last_used_at` (nullable), `created_at`,
`revoked_at` (nullable). One additive migration; no change to existing tables.

### 6. Management surface

- **Settings UI** (ADR-016 SPA): a "Personal access tokens" section — create (name,
  scope checkboxes grouped by resource, optional expiry), list (name, scopes, last used,
  expiry; never the token), and revoke. The plaintext is shown once with a copy button.
- **CLI**: `app:user:token:create <user> --scope … [--expires …]` for bootstrap/cron.

### 7. Security

Hashed at rest; recognizable prefix for secret-scanning; `last_used_at` for audit and
stale-token cleanup; optional expiry (recommend a default, e.g. 90 days, overridable);
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

- **Unlocks the deferred agent work**: `/.well-known/agent-skills.json` can ship *callable*
  skills (a scoped "log time" token), and an MCP server becomes a thin wrapper over the
  token API.
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
3. Settings UI (create/list/revoke) + i18n.
4. OpenAPI `securitySchemes` + per-endpoint scopes; docs/agent-readiness.md update.
5. (Then, separately) agent-skills.json with real skills; optional MCP wrapper.
