# ADR-017: Jira Cloud Support via OAuth 2.0 (Dual-Mode Integration)

**Status:** Accepted
**Date:** 2026-06-22
**Amends:** [ADR-003](ADR-003-jira-integration-architecture.md)

## Context

[ADR-003](ADR-003-jira-integration-architecture.md) chose OAuth 1.0a for Jira
worklog synchronization — correct for Jira Server/Data Center, whose
`/plugins/servlet/oauth/*` endpoints the integration still uses
([JiraAuthenticationService](../../src/Service/Integration/Jira/JiraAuthenticationService.php),
`guzzlehttp/oauth-subscriber`). Atlassian Cloud does not offer those endpoints:
Cloud requires OAuth 2.0 (3LO — three-legged OAuth) against `api.atlassian.com`.
Supporting customers on Jira Cloud therefore requires a second authentication mode.

## Decision

Implement a **dual-mode Jira integration**, discriminated by a deployment type on
each configured ticket system:

- [`DeploymentType`](../../src/Enum/DeploymentType.php) enum: `SERVER`
  (OAuth 1.0a / RSA, Server/DC — the default) or `CLOUD` (OAuth 2.0 / 3LO)
- [`TicketSystem`](../../src/Entity/TicketSystem.php) carries the discriminator and
  Cloud credentials: `deployment_type` (default `'SERVER'`), `oauth2_client_id`,
  `oauth2_client_secret`, and `cloud_id` (resolved once at first auth via
  Atlassian's `accessible-resources`; never admin-entered)
- [`UserTicketsystem`](../../src/Entity/UserTicketsystem.php) stores per-user
  OAuth2 state for Cloud: encrypted `refresh_token` (rotates on every refresh)
  and `token_expires_at`
- Client secrets never leave the server: `TicketSystem::SECRET_KEYS` strips
  `oauth2_client_secret` (and the OAuth 1.0a keys) from list and save responses
- OAuth 1.0a for Server/DC is **kept unchanged** — this ADR adds a mode, it does
  not replace one

Landed 2026-06-22 as Cloud support PR 1
([#416](https://github.com/netresearch/timetracker/pull/416), commits `7a507ae8`
data model, `428c7696` admin form): the deployment-type/OAuth2 configuration data
model and admin UI. The runtime landed 2026-07-02: `JiraCloudApiService`
implements the 3LO authorize redirect (state carries the ticket-system id,
encrypted, because Cloud redirect URIs must match the registered URL exactly),
the authorization-code exchange, rotating refresh tokens with recorded expiry,
automatic `cloudId` resolution via `accessible-resources`, Bearer-authenticated
REST through `api.atlassian.com/ex/jira/{cloudId}/rest/api/2/`, and the
Cloud-only `search/jql` endpoint. `JiraOAuthApiFactory` branches on the
deployment type; the shared callback route serves both flows.

## Corrections to the ADR-003 record

Two implementation claims in ADR-003 never matched reality:

- **No message queue**: worklog sync is synchronous; `symfony/messenger` is not
  installed and no queue exists
- **Encryption is AES-256-GCM**, not AES-256-CBC: OAuth tokens at rest are
  encrypted by
  [TokenEncryptionService](../../src/Service/Security/TokenEncryptionService.php)
  (`CIPHER_METHOD = 'aes-256-gcm'`), matching
  [ADR-011](ADR-011-security-architecture.md)

## Consequences

### Positive

- One timetracker instance can serve Server/DC and Cloud ticket systems side by side
- Admins select the deployment type per ticket system; each mode has a single,
  explicit code path
- Cloud secrets and rotating refresh tokens reuse the existing encryption-at-rest
  infrastructure

### Negative

- Two OAuth stacks to maintain (1.0a signing and 3LO token refresh)
- Cloud access tokens expire and must be refreshed server-side, adding token
  lifecycle handling that Server/DC never needed

## Related ADRs

- [ADR-003](ADR-003-jira-integration-architecture.md): JIRA Integration Architecture (amended)
- [ADR-011](ADR-011-security-architecture.md): Security Architecture (token encryption)
