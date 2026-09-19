# Threat Model

What TimeTracker protects, who could attack it, and which control answers each attack. It describes the system as it is in the 6.3.x line; where a control is missing or partial, this document says so rather than leaving it out.

The implementation detail behind every control named here is in [`security.md`](security.md). This document is the map; that one is the terrain.

## What is worth attacking

| Asset | Why it matters |
|-------|----------------|
| Time entries | Working hours of identifiable employees. Personal data under the GDPR, and the basis for customer invoicing — falsifying them is fraud, reading them is a privacy breach. |
| Contracts and time balances | Salary-adjacent data: contracted hours, overtime balances. |
| Customer, project and ticket structure | Reveals who a company works for and on what. Commercially sensitive. |
| Stored Jira OAuth tokens | Let the holder act in the customer's Jira as the user who granted them. |
| LDAP bind credentials | Read access to the corporate directory. |
| Local password hashes and TOTP secrets | On the user rows of accounts that do not authenticate against the directory. A database leak makes those hashes crackable offline. |
| The application secret | Signs CSRF tokens and, without a dedicated key, encrypts the Jira tokens. |
| The published container image | Runs in every deployment. Compromising the build reaches every installation at once. |

## Who the adversaries are

1. **An unauthenticated network attacker** who can reach the web interface. Needs a valid account — a directory account, a local one, or an enrolled passkey.
2. **An authenticated employee** (`DEV`) trying to see or change other people's entries, or to raise their own privileges.
3. **A team lead or controller** (`PL`, `CTL`) trying to reach data outside their teams or customers.
4. **A compromised or hostile external system** — the Jira instance, the Personio API, the LDAP directory — returning malicious data.
5. **A supply-chain attacker** who compromises a dependency, a GitHub Action or a base image.
6. **An operator of the deployment.** Has database and filesystem access by definition. Out of scope: the deployment's administrators are trusted.

## Trust boundaries

```
    Browser  ──HTTPS──▶  nginx  ──▶  PHP-FPM (Symfony)  ──▶  MariaDB
       │                                   │
   MCP client ──────────────────────────────┤
                                            ├──LDAPS──▶  Corporate directory
                                            ├──HTTPS──▶  Jira (customer-side)
                                            └──HTTPS──▶  Personio
```

Four boundaries carry untrusted data: the browser, the MCP client, and the two outbound integrations whose responses this application parses.

## Threats and the control that answers each

### 1. Unauthenticated access to the application

**Threat** — reaching time data without an account.
**Controls** — every route except the login endpoints requires authentication. There are three ways in, and `LoginFormAuthenticator` routes each user to exactly one of them: an LDAP bind against the corporate directory, a local password verified against an `auto` hash on the user row (ADR-018 D1), or a passkey over WebAuthn as a login of its own (ADR-018 D3), not as a second factor. `login_throttling` caps a username plus IP at five attempts. `UserChecker` refuses a deactivated account on every path. The second factor is TOTP (ADR-018 D2): it challenges only users with an enrolled secret.
**Residual risk** — two, and both are deployment decisions. Local accounts put offline-crackable password hashes in the database, which an LDAP-only deployment would not have; the firewall configuration says as much where it explains the throttling. And `REQUIRE_TWO_FACTOR` ships as `false`, so unless a deployment turns it on and users enrol, an account is as strong as its single factor.

### 2. LDAP injection during authentication

**Threat** — a crafted username altering the LDAP filter to bind as somebody else.
**Control** — username escaping in `LdapClientService` before the filter is built; see [`security.md`](security.md), *LdapAuthenticator*.

### 3. Horizontal privilege escalation between users

**Threat** — a `DEV` reading or editing another employee's entries by guessing an entry id.
**Controls** — ownership is checked in the service layer, not only in the UI; the role hierarchy `DEV < PL < CTL < ADMIN` is enforced on the controller actions; team membership limits which users a `PL` can see.
**Residual risk** — this is object-level authorisation, the class of bug that static analysis does not find. It is covered by integration tests per controller, and it is the first thing to check in review of any new endpoint that takes an id.

### 4. Vertical privilege escalation

**Threat** — a user reaching admin functionality, including the onboarding and sync tools.
**Controls** — role checks on every admin controller and every MCP tool. User switching (`simulateUserId`) is configured but unreachable: it needs `ROLE_ALLOWED_TO_SWITCH`, which only `ROLE_SUPER_ADMIN` grants, and no user type in `src/Enum/UserType.php` maps to it — `ROLE_ADMIN` is the ceiling.

### 5. Cross-site request forgery

**Threat** — a third-party page making an authenticated browser create or delete entries.
**Controls** — Symfony's stateless CSRF tokens on the `authenticate` and `logout` flows, validated from the `Sec-Fetch-Site`, `Origin` and `Referer` headers of a same-origin navigation rather than from server-side state; `cookie_samesite: lax` on the session cookie for everything else.
**Residual risk** — the CSRF token IDs cover login and logout only. Every other state-changing endpoint rests on `SameSite=Lax`, which stops cross-site POST but not a same-site subdomain attacker. A deployment that serves TimeTracker from a domain it shares with less trusted applications loses that control.

### 6. Cross-site scripting

**Threat** — script injected through a project name, a ticket summary pulled from Jira, or a free-text entry description.
**Controls** — the SolidJS frontend escapes interpolated values, and Twig autoescapes the server-rendered shell. `templates/login.html.twig` passes `JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT` to `|json_encode` so an encoded value cannot close a `<script>` element.
**Residual risk** — **there is no Content Security Policy, and no security response headers at all.** Neither the nginx configuration under `docker/nginx/` nor any Symfony listener sets `Content-Security-Policy`, `X-Frame-Options`, `X-Content-Type-Options` or `Referrer-Policy`; the only response header the application adds is a `Link` for discovery. Escaping is therefore the single layer between injected markup and execution, with nothing behind it. `docs/security.md` has carried "Add CSP for XSS prevention" as a recommendation since it was written. Tracked in [ROADMAP.md](../ROADMAP.md).

Data arriving from Jira is attacker-influenced whenever the customer's Jira is. It is rendered as text, never as markup.

### 7. Hostile data from Jira or Personio

**Threat** — a compromised integration endpoint returning oversized, malformed or malicious payloads.
**Controls** — responses are decoded into typed DTOs and validated rather than used as raw arrays; the sync services record conflicts instead of overwriting blindly.
**Residual risk** — an integration that has been compromised can still write plausible but wrong time data. Detection is reconciliation, not prevention.

### 8. Theft of stored Jira tokens

**Threat** — database read access yielding usable tokens for the customer's Jira.
**Control** — AES-256-GCM encryption at rest (`TokenEncryptionService`), key from `JIRA_TOKEN_ENCRYPTION_KEY`.
**Residual risk** — where that key is unset it falls back to `APP_SECRET`, so one leaked value loses both CSRF integrity and token confidentiality. Setting a dedicated key is the mitigation and it is a deployment decision.

### 9. SQL injection

**Threat** — injection through a filter, a date range or an export parameter.
**Control** — Doctrine ORM and the query builder with bound parameters. Ten call sites in `src/` drop to DBAL — eight in `EntryRepository`, one in `OptimizedEntryRepository`, one in `GetHolidaysAction` — and all ten pass their values through `executeQuery($sql, $params)` or `bindValue()` rather than concatenating them. A new one is a review point.

### 10. Abuse of the MCP interface

**Threat** — an agent or an MCP client reaching tools beyond what its user may do. The MCP surface exposes 31 tools, including user onboarding, contract changes and Personio sync.
**Controls** — MCP requests authenticate as a user and inherit that user's role; `ScopeGuard` checks the token's scopes per tool and narrows what the user may do, never widens it; admin tools additionally require `ROLE_ADMIN` on the owning user. The Streamable-HTTP transport accepts only the `Host`/`Origin` values listed in `MCP_ALLOWED_HOSTS`, which is a DNS-rebinding guard on the inbound request.
**Residual risk** — an API token is a bearer credential. Its blast radius is the role of the user who issued it, which is why admin tokens are issued narrowly.

### 11. Supply-chain compromise

**Threat** — a malicious dependency, action or base image reaching the published container.
**Controls** — lockfiles committed and installed frozen; third-party actions pinned by commit SHA; base images pinned by digest; blocking `composer audit`, `bun audit` and `npm audit` in CI; Trivy scanning of the produced images; Dependabot and Renovate; signed commits required on `main`.
**Residual risk** — nothing is fetched over the network by an unverified installer any more: Node.js, the Symfony CLI, Bun and Composer all arrive as `COPY --from` a digest-pinned image stage. What remains is that a rebuild is not independently verifiable — there is no documented procedure by which an outsider can reproduce a published image and compare digests. Tracked in [ROADMAP.md](../ROADMAP.md).

### 12. Compromise of the release path

**Threat** — publishing an image that nobody authorised.
**Controls** — `main` is protected: no direct pushes, no force pushes, required checks, required signed commits; images are built only by GitHub Actions with `GITHUB_TOKEN`; signing is keyless and leaves a transparency-log record.
**Residual risk** — `enforce_admins` is off, so a repository administrator can bypass branch protection. This is a deliberate trade-off for a single-maintainer project and is recorded in [GOVERNANCE.md](../GOVERNANCE.md).

### 13. Denial of service

**Threat** — exhausting the application through expensive exports or report queries.
**Controls** — none specific at the application layer. Rate limiting and request size limits are the reverse proxy's job in a deployment.
**Residual risk** — accepted. TimeTracker is deployed inside an organisation, behind that organisation's ingress, not on the open internet.

## What this model does not cover

- The security of the LDAP directory, the Jira instance and the Personio tenant themselves.
- The host, the container runtime and the reverse proxy of a deployment.
- Physical and organisational security of the operator.
- Malicious administrators of a deployment.

## Maintaining this document

Reviewed when a minor version is released, and whenever a change adds a trust boundary — a new outbound integration, a new authentication path, a new externally reachable interface. A pull request that adds one of those updates this file; that is what the *Security Considerations* section of the feature template in [CONTRIBUTING.md](../CONTRIBUTING.md) is for.
