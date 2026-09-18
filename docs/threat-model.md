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
| The application secret | Signs CSRF tokens and, without a dedicated key, encrypts the Jira tokens. |
| The published container image | Runs in every deployment. Compromising the build reaches every installation at once. |

## Who the adversaries are

1. **An unauthenticated network attacker** who can reach the web interface. Cannot authenticate without a directory account.
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
**Controls** — every route except the login endpoints requires authentication; authentication runs against the corporate LDAP directory, so there is no local password store to attack; optional WebAuthn second factor (`REQUIRE_TWO_FACTOR`, `WEBAUTHN_RP_ID`).
**Residual risk** — the second factor is optional. A deployment that leaves `REQUIRE_TWO_FACTOR` off is as strong as the directory password alone.

### 2. LDAP injection during authentication

**Threat** — a crafted username altering the LDAP filter to bind as somebody else.
**Control** — username escaping in `LdapClientService` before the filter is built; see [`security.md`](security.md), *LdapAuthenticator*.

### 3. Horizontal privilege escalation between users

**Threat** — a `DEV` reading or editing another employee's entries by guessing an entry id.
**Controls** — ownership is checked in the service layer, not only in the UI; the role hierarchy `DEV < PL < CTL < ADMIN` is enforced on the controller actions; team membership limits which users a `PL` can see.
**Residual risk** — this is object-level authorisation, the class of bug that static analysis does not find. It is covered by integration tests per controller, and it is the first thing to check in review of any new endpoint that takes an id.

### 4. Vertical privilege escalation

**Threat** — a user reaching admin functionality, including the onboarding and sync tools.
**Controls** — role checks on every admin controller and every MCP tool; user impersonation is restricted to administrators and is logged.

### 5. Cross-site request forgery

**Threat** — a third-party page making an authenticated browser create or delete entries.
**Control** — stateless CSRF protection on every state-changing operation, tokens signed with `APP_SECRET`.
**Residual risk** — a deployment still running the committed placeholder `APP_SECRET` has forgeable tokens. See [`secrets-management.md`](secrets-management.md).

### 6. Cross-site scripting

**Threat** — script injected through a project name, a ticket summary pulled from Jira, or a free-text entry description.
**Controls** — the SolidJS frontend escapes interpolated values; a strict Content Security Policy; Twig autoescaping on the server-rendered shell.
**Residual risk** — data arriving from Jira is attacker-influenced whenever the customer's Jira is. It is rendered as text, never as markup.

### 7. Hostile data from Jira or Personio

**Threat** — a compromised integration endpoint returning oversized, malformed or malicious payloads.
**Controls** — responses are decoded into typed DTOs and validated rather than used as raw arrays; the sync services record conflicts instead of overwriting blindly; MCP outbound hosts are restricted by `MCP_ALLOWED_HOSTS`.
**Residual risk** — an integration that has been compromised can still write plausible but wrong time data. Detection is reconciliation, not prevention.

### 8. Theft of stored Jira tokens

**Threat** — database read access yielding usable tokens for the customer's Jira.
**Control** — AES-256-GCM encryption at rest (`TokenEncryptionService`), key from `JIRA_TOKEN_ENCRYPTION_KEY`.
**Residual risk** — where that key is unset it falls back to `APP_SECRET`, so one leaked value loses both CSRF integrity and token confidentiality. Setting a dedicated key is the mitigation and it is a deployment decision.

### 9. SQL injection

**Threat** — injection through a filter, a date range or an export parameter.
**Control** — Doctrine ORM and the query builder with bound parameters. Ten places in `src/` drop to native SQL or `executeQuery()`; each binds its parameters rather than concatenating them, and a new one is a review point.

### 10. Abuse of the MCP interface

**Threat** — an agent or an MCP client reaching tools beyond what its user may do. The MCP surface exposes 31 tools, including user onboarding, contract changes and Personio sync.
**Controls** — MCP requests authenticate as a user and inherit that user's role; `ScopeGuard` restricts which tools a token may call; admin-only tools resolve through `AdminEntityResolver`, which re-checks the role.
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
