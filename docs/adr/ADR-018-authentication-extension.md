# ADR-018: Authentication Extension — Local Passwords, MFA (TOTP) and Passkeys

**Status:** Proposed
**Date:** 2026-07-02
**Relates to:** [ADR-004](ADR-004-authentication-strategy-ldap-local.md) (designs, in a
narrower and safer form, the local-account capability ADR-004 sketched but never built),
[ADR-011](ADR-011-security-architecture.md) (extends the security architecture),
[ADR-016](ADR-016-solidjs-frontend-rewrite.md) (login/Settings UX lives in the SolidJS SPA)

## Context

### Current state (verified in code, 2026-07-02)

- **LDAP is the only login path.** The `main` firewall registers one custom
  authenticator, [`LdapAuthenticator`](../../src/Security/LdapAuthenticator.php)
  ([config/packages/security.yaml](../../config/packages/security.yaml)). It handles
  every POST to `_login`/`login_check`, binds against LDAP via the laminas-based
  [`LdapClientService`](../../src/Service/Ldap/LdapClientService.php), and passes
  `CustomCredentials` that are always "true" locally — the credential check *is* the
  LDAP bind. `form_login` is also configured on the firewall, but in practice only
  its entry-point role (redirect to `_login`) is in effect: Symfony sorts
  authenticators by factory priority (custom authenticators `0`, form login `-30` —
  verified in `symfony/security-bundle` `CustomAuthenticatorFactory`/`FormLoginFactory`),
  so `LdapAuthenticator` runs first and always sets a response for login POSTs; the
  form-login authenticator never executes (its `enable_csrf` and last-username
  handling are dead config — the CSRF check that actually runs is
  `LdapAuthenticator`'s own `CsrfTokenBadge`).
- **The `users` table has no password column** ([sql/full.sql](../../sql/full.sql),
  [`User`](../../src/Entity/User.php)). `User` implements only `UserInterface` — not
  `PasswordAuthenticatedUserInterface`. `User::getPassword()` returns a synthetic
  `sha256(username . '_ldap_user_' . id)` value whose sole purpose is the
  remember-me cookie signature (Symfony's `SignatureRememberMeHandler`; the
  `signature_properties` default is `['password']`, verified in
  `RememberMeFactory`). The `password_hashers: App\Entity\User: 'auto'` entry in
  security.yaml is dormant — no hashed password exists anywhere.
- **Provisioning:** with `LDAP_CREATE_USER=true` (default), a user who authenticates
  against LDAP but has no `users` row is auto-created with type `DEV`, locale `de`,
  and team memberships mapped from LDAP
  (`LdapAuthenticator::createUserFromLdap()`).
- **Roles** derive from the [`UserType`](../../src/Enum/UserType.php) enum column
  (`USER`/`DEV` → `ROLE_USER`; `PL` → `ROLE_USER`, `ROLE_PL`, `ROLE_ADMIN` for v4
  compatibility; `ADMIN` → `ROLE_USER`, `ROLE_ADMIN`), and
  [`UserChecker`](../../src/Security/UserChecker.php) rejects deactivated accounts
  (`users.active = 0`) before any credential check.
- **SPA login:** [`LoginForm`](../../frontend/src/components/LoginForm.tsx) renders a
  real `<form>` posting `_username`/`_password`/`_csrf_token`/`_remember_me` to
  `_login` (works without JS); with JS it submits via `fetch` +
  `X-Requested-With` and `LdapAuthenticator` answers XHR with JSON
  (`{ok, redirect}` / 401 `{ok:false, error}`).
- **Sessions:** session-based auth with signature remember-me (30 days,
  `secure: auto`), `switch_user` impersonation, stateless CSRF.
- **E2E:** Playwright logs in against a seeded dev LDAP container (`ldap-dev`,
  `osixia/openldap`, [docker/ldap/users-only.ldif](../../docker/ldap/users-only.ldif))
  — see [e2e/helpers/auth.ts](../../e2e/helpers/auth.ts) and
  [compose.yml](../../compose.yml).

### Gaps

1. **LDAP is mandatory.** A deployment without an LDAP server cannot log anyone in;
   an LDAP outage locks out every user including admins. There is no local
   credential of any kind (task #14).
2. **No second factor.** A phished or leaked LDAP password grants full access; there
   is no TOTP, no passkeys, no WebAuthn (task #13).
3. **No login throttling.** Neither `login_throttling` nor `symfony/rate-limiter`
   is configured/installed (verified in composer.lock) — tolerable while LDAP
   enforces its own lockout policies, unacceptable once local passwords exist.
4. **No mailer.** `symfony/mailer` is not installed, so email-based self-service
   password reset is out of reach without new infrastructure.

## Decision

Extend authentication in four increments, keeping the existing LDAP flow intact and
the dev-LDAP e2e stack as CI default.

### D1: Local password accounts, LDAP optional

**Account model — one auth source per account, no silent fallback.**
A `users` row authenticates against exactly one source:

- `users.password IS NULL` (default) → LDAP account: credential check is the LDAP bind.
- `users.password` set (Symfony `auto` hash) → local account: credential check is the
  password hash; LDAP is never consulted for this user.

ADR-004's original "LDAP first, local fallback on LDAP failure *for the same user*"
is deliberately **not** revived: a stale local password that works whenever LDAP is
down would bypass central account control (a user disabled in the directory could
still log in), and failure-triggered fallback makes login behaviour dependent on
LDAP availability. Emergency access is instead covered by a dedicated local admin
account.

**Schema.** New Doctrine migration (pattern:
[migrations/Version20260624_AddUserActive.php](../../migrations/Version20260624_AddUserActive.php)):

```sql
ALTER TABLE users ADD password VARCHAR(255) DEFAULT NULL;
```

`User` implements `PasswordAuthenticatedUserInterface`; `getPassword()` returns the
stored hash or `null`. The already-configured `password_hashers: 'auto'` entry
becomes live; hashing/verification go through `UserPasswordHasherInterface` only.
The hash must never leave the server: excluded from `getSettings()`, from
`GetUsersAction` responses and from every DTO (same rule as
`TicketSystem::SECRET_KEYS` in [ADR-017](ADR-017-jira-cloud-oauth2.md)).

**Authenticator design — why not two chained authenticators.** Symfony's
`AuthenticatorManager` offers no fallback semantics: the first supporting
authenticator that throws an `AuthenticationException` produces its
`onAuthenticationFailure()` response, and any non-null response terminates the
chain (verified in `symfony/security-http` `AuthenticatorManager::executeAuthenticators()`
/ `handleAuthenticationFailure()`). `AbstractLoginFormAuthenticator` always returns
a redirect on failure, so registering a second authenticator after
`LdapAuthenticator` would be dead code — exactly what today's inert `form_login`
authenticator demonstrates. Falling through by returning `null` from
`onAuthenticationFailure()` is possible but rejected: it doubles CSRF validation
and failure events and makes the surfaced error message depend on chain order.

Instead, `LdapAuthenticator` is renamed/refactored to a single
`LoginFormAuthenticator` that routes per user:

```
POST /login
  └─ load users row by username
       ├─ row has password hash        → PasswordCredentials (hasher check)
       ├─ row is LDAP account          → LDAP bind (unchanged behaviour)
       │    └─ no row + LDAP_CREATE_USER → LDAP bind, then provision (unchanged)
       └─ LDAP not configured, no hash → fail (generic bad-credentials message)
```

The `form_login` key stays as entry point; behaviour of existing LDAP deployments
is unchanged.

**LDAP becomes optional.** Empty `LDAP_HOST` (already the compose default for the
prod service: `LDAP_HOST=${LDAP_HOST:-}`) switches the instance to **local-only
mode**: the authenticator skips the LDAP branch entirely, and LDAP-account rows
(password `NULL`) cannot authenticate. A startup-time warning (log) makes a
misconfigured empty host visible.

**Bootstrap.** Fresh local-only installs need a first admin: new console command
`app:user:create <username> --type=ADMIN` (prompts for the password, hashes it,
sets `active=1`). Also usable to (re)set any user's password from the CLI —
the LDAP-outage escape hatch.

**Admin UX.** The SPA admin "Users" entity form
([frontend/src/admin/entities.ts](../../frontend/src/admin/entities.ts) →
`/user/save`, [`SaveUserAction`](../../src/Controller/Admin/SaveUserAction.php))
gains an optional password block: *set/replace password* (input, hashed
server-side in the DTO handler) and *clear password* (reverts the account to
LDAP). No self-service password change/reset in this stage (no mailer; see Gaps) —
password resets are an admin/CLI action.

**Login throttling.** With offline-crackable local hashes in play, add Symfony's
`login_throttling` to the `main` firewall (requires adding `symfony/rate-limiter`;
backed by the default cache — APCu per [ADR-005](ADR-005-caching-strategy.md)).
This throttles LDAP-account logins too, which is a strict improvement.

### D2: MFA via scheb/2fa-bundle (TOTP + backup codes)

**Evaluation — bundle vs hand-rolled.**

| Criterion | `scheb/2fa-bundle` + `2fa-totp` + `2fa-backup-code` | Hand-rolled (otphp + custom listener) |
|---|---|---|
| Symfony 8 / PHP 8.5 compatibility | **Verified on Packagist 2026-07-02:** v8.6.0 (2026-06-12) requires `php ~8.4.0 \|\| ~8.5.0`, `symfony/* ^7.4 \|\| ^8.0` — matches this project (PHP 8.5, Symfony 8.1) | n/a |
| Partially-authenticated state | Battle-tested two-factor token + firewall listener; access control via `IS_AUTHENTICATED_2FA_IN_PROGRESS` | Must reimplement the token state machine — the highest-risk part |
| Backup codes, brute-force protection, trusted devices | Included / optional sub-packages | All custom |
| Maintenance | Widely used, actively maintained | We own security-critical code forever |

**Decision: use `scheb/2fa-bundle` v8.x with `scheb/2fa-totp` and
`scheb/2fa-backup-code`.** Hand-rolling a partially-authenticated session state
machine is exactly the kind of security-critical wheel this project should not
reinvent.

**Data model.** On `users`: `totp_secret VARCHAR(255) NULL` — encrypted at rest
with the existing AES-256-GCM
[`TokenEncryptionService`](../../src/Service/Security/TokenEncryptionService.php)
([ADR-011](ADR-011-security-architecture.md)) — and `backup_codes` storing
**hashed** one-time codes (JSON array). `User` implements the bundle's
`TwoFactorInterface`/`BackupCodeInterface`. MFA applies to both LDAP and local
accounts: the bundle intercepts at token level, independent of which primary
credential check ran.

**SPA integration.** The bundle's stock 2FA form is Twig; our login is a
fetch-based SPA form. The login page ([frontend/src/login.tsx](../../frontend/src/login.tsx))
gains a second step: when 2FA is pending, the login response directs the SPA to
the challenge view, which posts the TOTP/backup code to the bundle's check path
(JSON success/failure handlers for XHR, Twig fallback without JS — same
progressive-enhancement pattern as `LoginForm`). Exact bundle configuration keys
for JSON handlers are to be validated against the scheb v8.6 docs in the
implementing PR.

**Enrollment UX (Settings).** New "Security" section on the SPA Settings page
([frontend/src/pages/Settings.tsx](../../frontend/src/pages/Settings.tsx)):
enable TOTP (server generates secret → QR/otpauth URI rendered client-side →
confirm with a first valid code), show freshly generated backup codes exactly
once, disable TOTP (re-authentication required). Enrollment is opt-in per user in
this stage; policy enforcement ("admins must enroll") is future work.

### D3: Passkeys via web-auth/webauthn-symfony-bundle

**Evaluation — bundle vs `webauthn-lib` direct.**

| Criterion | `web-auth/webauthn-symfony-bundle` | `web-auth/webauthn-lib` direct |
|---|---|---|
| Symfony 8 / PHP 8.5 compatibility | **Verified on Packagist 2026-07-02:** v5.3.5 (2026-05-24) requires `php >=8.2`, `symfony/* ^6.4 \|^7.0 \|^8.0` | Same (v5.3.5, 2026-05-31) |
| Firewall integration | Ships a `webauthn` authenticator + ceremony endpoints (creation/assertion options and response validation) | Hand-write controllers, authenticator, (de)serialization of every ceremony message |
| Cost of ownership | Config + one credential-store entity | Re-implements what the bundle already does, with more attack surface |

**Decision: use the bundle.** Direct `webauthn-lib` use buys flexibility this
project does not need and costs bespoke security-critical glue.

**Credential storage.** New table `users_webauthn_credentials` (Doctrine entity;
one row per registered passkey): credential id, public key, AAGUID, sign counter,
transports, backup-eligible/backed-up flags, `user_id` FK, label ("YubiKey 5",
"MacBook Touch ID"), `created_at`/`last_used_at`. Plus
`users.webauthn_user_handle` — a random, stable, non-PII 32-byte handle used as
the WebAuthn user entity id (never the username or numeric id). The entity/
repository implement the bundle's credential-source repository contract (exact
interface names to be confirmed against the bundle 5.3 docs during the
implementing PR).

**Coexistence with LDAP accounts.** A passkey is an *alternative first factor*
bound to the local `users` row — it works identically for LDAP and local
accounts, and a passkey login performs **no LDAP bind** (that is the point:
phishing-resistant, works during LDAP outages). Two consequences, made explicit:

- `UserChecker` still runs, so `users.active = 0` blocks passkey logins.
  **Disabling a user in LDAP alone does not block their passkeys** — offboarding
  must set `users.active = 0` (already the documented deactivation switch).
- A passkey with user verification (UV required) is inherently two-factor, so
  passkey logins skip the TOTP challenge (the scheb bundle only intercepts the
  token types it is configured for; the WebAuthn token is not among them).

**Usernameless flow.** Registrations request discoverable credentials
(`residentKey: required`, UV required), enabling a "Sign in with a passkey"
button without a username field (the user handle comes back in the assertion).
Username+password/LDAP stays the primary visible flow; usernameless is
progressive enhancement, since not every authenticator/browser supports
discoverable credentials.

**Enrollment UX.** Same Settings "Security" section: register passkey (browser
`navigator.credentials.create()` via `@simplewebauthn/browser` — v13.3.0,
verified on npm 2026-07-02 — or the bare WebAuthn API), list with labels and
last-used, rename, revoke. Registration requires a fully authenticated session.

### D4: Session and remember-me interaction

- Remember-me stays signature-based. The signature covers the `password` property,
  so **deploying the `getPassword()` change (synthetic value → real hash/`null`)
  invalidates every outstanding remember-me cookie once** — users log in again
  one time. Acceptable; called out in the PR-1 release notes.
- After that, a password change invalidates exactly that user's remember-me
  cookies — desired behaviour we get for free.
- With 2FA pending, the scheb bundle defers remember-me until the challenge is
  completed; passkey and LDAP/password logins keep attaching `RememberMeBadge`.
- Session lifecycle, `switch_user`, logout CSRF: unchanged.

### D5: Testing / e2e strategy

- **Dev LDAP stays the CI default.** All existing e2e suites keep authenticating
  against `ldap-dev`; nothing about the current stack changes.
- **Unit/functional:** authenticator routing matrix (LDAP account / local account /
  unknown user / empty `LDAP_HOST` / deactivated user), hasher round-trip, console
  command, DTO never exposing hashes, TOTP verify + backup-code single-use,
  credential-store CRUD.
- **Local-only e2e:** a small Playwright project running the app with `LDAP_HOST=`
  (empty) and a seeded local admin, proving login, password set/clear and the
  bootstrap command without any LDAP container.
- **TOTP e2e:** enrollment + challenge, generating codes in the test from the
  enrolled secret (any RFC-6238 JS lib in devDependencies).
- **Passkey e2e:** Playwright drives a **CDP virtual authenticator**
  (`WebAuthn.enable`/`WebAuthn.addVirtualAuthenticator`) — Chromium-only, so these
  specs are tagged to skip on WebKit/Firefox projects.

## Rollout plan

Four reviewable PRs, each independently shippable and gated by the standard
pipeline (PHPStan level 10, PHPUnit, Playwright; new UI strings land in
`frontend/messages/en.json` **and** `de.json`):

| PR | Scope | Key deliverables | Estimate |
|----|-------|------------------|----------|
| 1 | Local passwords + LDAP optional | Migration (`users.password`), `PasswordAuthenticatedUserInterface`, unified `LoginFormAuthenticator`, local-only mode (empty `LDAP_HOST`), `app:user:create`, admin set/clear password UX, `login_throttling` (+`symfony/rate-limiter`), local-only e2e project, docs (`docs/security.md`, `.env` reference) | 3–4 dev-days |
| 2 | MFA: TOTP + backup codes | scheb bundles, migration (`totp_secret`, `backup_codes`), encrypted secret storage, SPA challenge step + Twig fallback, Settings enrollment UI, TOTP e2e | 4–5 dev-days |
| 3 | Passkeys | webauthn bundle, migration (`users_webauthn_credentials`, `webauthn_user_handle`), login button + usernameless flow, Settings passkey management, virtual-authenticator e2e | 5–6 dev-days |
| 4 | Hardening + polish | 2FA-skip verification for passkey tokens, audit logging of auth events, operational docs (offboarding: `users.active`), translation sweep, threat-model note in `docs/security.md` | 1–2 dev-days |

PR 1 delivers task #14 completely; PRs 2–4 deliver task #13. Order matters: the
Settings "Security" section and the challenge-step plumbing from PR 2 are reused
by PR 3.

## Consequences

### Positive

- Deployments without LDAP become first-class (empty `LDAP_HOST`); LDAP outages no
  longer lock out local/passkey users.
- Phishing-resistant login (passkeys) and a second factor (TOTP) for both account
  types, built on maintained, verified-compatible bundles instead of bespoke
  security code.
- One authenticator with explicit per-user routing replaces today's
  half-configured two-authenticator setup (inert `form_login`), making the
  security.yaml match reality.
- Login throttling closes a gap that predates this ADR.

### Negative

- Three new dependencies (scheb 2fa, webauthn bundle, rate-limiter) enter the
  security-critical path and must be tracked for advisories.
- Two credential stores (LDAP and local hashes) mean password policy for local
  accounts is now this project's problem (throttling mitigates; complexity rules
  are out of scope here).
- Passkeys and local passwords bypass central directory control; offboarding
  **must** use `users.active` (documented, but an operational risk if ignored).
- One-time remember-me invalidation for all users when PR 1 deploys.
- No self-service password reset until a mailer exists — admin/CLI resets only.

### To verify during implementation (flagged, not assumed)

- scheb v8.6 JSON/SPA handler configuration keys (PR 2).
- webauthn-bundle 5.3 credential-repository contract and Doctrine mapping (PR 3).
- Browser support matrix for usernameless/discoverable credentials at the time
  PR 3 lands.

## Alternatives considered

- **Two chained authenticators (LDAP → form_login fallback):** rejected — Symfony's
  authenticator manager stops at the first non-null failure response (verified in
  vendor code); fall-through via `null` failure responses is fragile and doubles
  failure handling.
- **`form_login_ldap` / symfony/ldap component:** would replace the working
  laminas-based client and its team-sync provisioning wholesale — a rewrite
  orthogonal to the actual goals; rejected for scope.
- **Same-user LDAP-with-local-fallback (ADR-004's sketch):** rejected — stale local
  passwords would survive directory disablement and login behaviour would flip
  with LDAP availability.
- **Hand-rolled TOTP flow:** rejected — reimplements a partially-authenticated
  token state machine, brute-force protection and backup codes that scheb provides.
- **`webauthn-lib` without the bundle:** rejected — hand-written ceremony
  endpoints and authenticator glue for no flexibility we need.
- **External IdP (Keycloak/OIDC):** would outsource MFA/passkeys entirely, but
  forces every deployment to operate an IdP; oversized for this application's
  self-contained deployment profile. Revisit if SSO across multiple apps becomes a
  requirement.

## Related ADRs

- [ADR-004](ADR-004-authentication-strategy-ldap-local.md): Authentication Strategy (LDAP + Local) — the never-built local fallback this ADR replaces with an explicit per-account model
- [ADR-011](ADR-011-security-architecture.md): Security Architecture — LDAP authentication and AES-256-GCM token encryption reused for TOTP secrets
- [ADR-016](ADR-016-solidjs-frontend-rewrite.md): SolidJS Frontend Rewrite — login and Settings surfaces extended here
- [ADR-017](ADR-017-jira-cloud-oauth2.md): Jira Cloud OAuth 2.0 — the secret-handling pattern (`SECRET_KEYS`) mirrored for password hashes
