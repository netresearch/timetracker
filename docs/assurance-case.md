# Assurance case

Why we believe this application's security requirements are met.

[`threat-model.md`](threat-model.md) says what is worth attacking and which control answers each threat. This document asks the harder question: for each requirement, *why should a reader believe it holds?* The answer is always the same shape — a control that implements it, a test that would fail if it stopped holding, and an honest statement of what the argument leaves uncovered.

Every test named below exists in the repository and runs in the `CI Success` gate — the PHPUnit suites under `tests/`, and the Playwright specs under `e2e/`, which the `e2e` job runs and `CI Success` depends on. Where a requirement rests on a deployment decision rather than on code, it says so: that part of the argument is not ours to make.

## R1 — An unauthenticated request cannot read time data

**Control.** Every route but the login endpoints requires authentication. `LoginFormAuthenticator` sends each user down exactly one path — LDAP bind, local password, or a passkey as a login of its own — and `UserChecker` refuses a deactivated account on all three. `login_throttling` caps a username plus IP at five attempts. See [threat 1](threat-model.md#1-unauthenticated-access-to-the-application).

**Evidence.** `tests/Security/LoginFormAuthenticatorTest.php` covers the routing between the three paths; `tests/Controller/SecurityControllerTest.php` covers the login and logout endpoints themselves.

**If the control fails**, the application is open to anyone who can reach it on the network. There is no second line behind it.

**Not covered.** `REQUIRE_TWO_FACTOR` ships as `false`. An account is then as strong as its single factor, and whether that is acceptable is the deploying organisation's decision, not this project's.

## R2 — A user sees only the entries they are entitled to

**Control.** Ownership is checked in the service layer rather than in the UI, and the role hierarchy `DEV < PL < CTL < ADMIN` is enforced on the controller actions. Team membership limits which users a `PL` can see. See [threat 3](threat-model.md#3-horizontal-privilege-escalation-between-users).

**Evidence.** `tests/Controller/DeleteEntryActionTest.php::testCannotDeleteAnotherUsersEntry` is the direct one: it asserts the refusal rather than the happy path, and its neighbours (`testDeletesOwnEntryFromJsonBody`, `testAdminCanDeleteAnotherUsersEntry`) pin both directions, so the suite cannot be satisfied by an endpoint that refuses everyone. `tests/Api/Functional/ProjectLastBookedByUserTest.php::testLastBookedByUserIgnoresOtherUsersEntries` covers the read side.

**If the control fails**, any authenticated employee reads and edits any other employee's hours by guessing an id.

**Not covered.** This is object-level authorisation — the bug class static analysis does not find. The tests above pin the endpoints that exist today; a new endpoint taking an id gets no protection from them. That is why it is the first thing to check in review.

## R3 — A non-admin cannot reach administrative functions

**Control.** Role checks on every admin controller and every MCP tool. User switching (`simulateUserId`) is configured but unreachable: it needs `ROLE_ALLOWED_TO_SWITCH`, which only `ROLE_SUPER_ADMIN` grants, and no user type in `src/Enum/UserType.php` maps to it. See [threat 4](threat-model.md#4-vertical-privilege-escalation).

**Evidence.** `tests/Controller/AuthorizationSecurityTest.php` asserts, per admin delete action, that both `PL` and `DEV` are refused — eight actions, two roles each.

**If the control fails**, an ordinary user deletes customers, projects and users, or onboards accounts.

**Not covered.** The tests enumerate the delete actions. An admin action added without a matching case inherits nothing from them.

## R4 — A Jira token in the database is not usable if the database leaks

**Control.** AES-256-GCM at rest through `TokenEncryptionService`, keyed from `JIRA_TOKEN_ENCRYPTION_KEY`. See [threat 8](threat-model.md#8-theft-of-stored-jira-tokens).

**Evidence.** `tests/Service/Security/TokenEncryptionServiceTest.php` — the load-bearing case is `testEncryptTokenProducesDifferentOutputEachTime`, which fails if the implementation degrades to something deterministic, and the constructor cases refuse an empty or non-string key rather than silently encrypting with one.

**If the control fails**, a database read yields tokens that act in the customer's Jira as the user who granted them.

**Not covered.** Where `JIRA_TOKEN_ENCRYPTION_KEY` is unset, the service falls back to `APP_SECRET`, so one leaked value costs both CSRF integrity and token confidentiality. Setting a dedicated key is a deployment decision.

## R5 — A third-party page cannot act as a logged-in user

**Control.** Stateless CSRF tokens on the `authenticate` and `logout` flows, validated from `Sec-Fetch-Site`, `Origin` and `Referer`; `cookie_samesite: lax` for everything else. See [threat 5](threat-model.md#5-cross-site-request-forgery).

**Evidence.** `tests/Controller/SecurityControllerTest.php` and `tests/Security/RememberMeFlowTest.php` cover the login and logout flows; `tests/Controller/TwoFactorLoginFlowTest.php` covers the second factor in the same flow.

**If the control fails**, an authenticated browser visiting a hostile page creates or deletes entries.

**Not covered — and this is the weakest requirement in the document.** The CSRF token IDs cover login and logout only. Every other state-changing endpoint rests on `SameSite=Lax`, which stops a cross-site POST but not a same-site subdomain attacker. A deployment that serves TimeTracker from a domain shared with less trusted applications loses this control, and no test here would notice.

## R6 — Injected markup does not execute in a user's browser

**Control.** An enforced, nonce-based Content Security Policy on main-request HTML responses (`ContentSecurityPolicySubscriber`), plus `X-Frame-Options`, `X-Content-Type-Options` and `Referrer-Policy` from `SecurityHeadersSubscriber` and from nginx for files that never reach PHP. See [threat 6](threat-model.md#6-cross-site-scripting).

**Evidence.** `tests/Controller/ContentSecurityPolicyTest.php` and `tests/Controller/SecurityHeadersTest.php` assert the headers on real responses; `e2e/csp.spec.ts` observes the browser refusing a nonce-less inline script, with `disposition: "enforce"`.

**If the control fails**, a stored value containing markup runs as script in every viewer's session.

**Not covered.** `style-src` still allows inline styles in debug builds. The policy is a second line behind escaping, not a replacement for it.

## R7 — An API or MCP token cannot exceed the rights of the user who issued it

**Control.** MCP requests authenticate as a user and inherit that user's role; `ScopeGuard` narrows per tool and never widens; admin tools additionally require `ROLE_ADMIN`. See [threat 10](threat-model.md#10-abuse-of-the-mcp-interface).

**Evidence.** `tests/Mcp/ScopeGuardTest.php` pins both directions — `testRejectsWhenTokenLacksScope` and `testRequireAdminScopeRejectsNonAdmins` against `testReturnsUserWhenScopeGranted` and `testRequireAdminScopeHonorsRoleHierarchy` — so a guard that refused everything would not pass.

**If the control fails**, a token issued for one narrow purpose reaches all 31 MCP tools, including onboarding and Personio sync.

**Not covered.** An API token is a bearer credential: anyone holding it is its user. Its blast radius is that user's role, which is why admin tokens are issued narrowly rather than by default.

## R8 — A released artefact is the one this repository built

**Control.** Signed annotated tags; Cosign keyless signatures and an SBOM attestation on every published image; for the source archive, a build in `netresearch/.github`'s `release-source-archive.yml` — a reusable workflow this repository cannot edit — that also signs it, attests its provenance and SBOM, and creates the release. A rebuild on every release and weekly checks the archive is reproducible. See [threat 12](threat-model.md#12-compromise-of-the-release-path).

**Evidence.** Verifiable by anyone, which is stronger than a test: the commands are in [SECURITY.md](../SECURITY.md#verifying-a-release), and the identity patterns there name the signing workflows rather than accepting any workflow in the organisation.

**If the control fails**, a substituted image or archive is indistinguishable from ours to a deployer who checks nothing.

**Not covered.** Releases up to and including v6.4.0 were built in this repository, so their provenance is SLSA Build Level 2. The reusable is referenced by `@main`, so the build instructions are whatever that branch holds when the tag is pushed; the provenance records the exact commit, which is what a reviewer checks after the fact.

## Secure design principles, and where they show

The requirements above argue single properties. This section argues the shape of the design that produces them, because a property that holds by accident holds only until the next change.

**Deny by default.** `config/packages/security.yaml` ends on `- { path: ^/, roles: IS_AUTHENTICATED_REMEMBERED }`, so a route is protected unless something above that line names it. The public entries are anchored for that reason — `^/\.well-known/` and `^/llms\.txt$` rather than a prefix — so a look-alike path such as `/.well-known-x` or `/llms.txt.bak` cannot inherit public access. API tokens follow the same rule one layer down: `RequireScopeSubscriber` refuses a token request to any controller that has not declared a scope, which makes a newly added endpoint unreachable by tokens until someone opts it in. `tests/Security/RequireScopeCoverageTest.php` guards the opt-in itself: it reads every declared scope by reflection and fails on one outside the `ApiScope` taxonomy — a typo such as `reporting:reed` would otherwise disable an endpoint silently — and a floor count fails if annotations are removed wholesale.

**Least privilege.** A token never exceeds the person who issued it: `ApiTokenAuthenticator` builds the `ApiAccessToken` from the owning user's roles *and* the token's scopes, so the scope can only narrow. The release path is split the same way — `release.yml` declares `permissions: {}` at workflow level and grants each job what it alone needs, and inside `release-source-archive.yml` the job that builds and signs holds `contents: read` while only the job that creates the release holds `contents: write`.

**Complete mediation of half-finished logins.** The states between "anonymous" and "logged in" are named rather than left to fall through the catch-all: `^/2fa` requires `IS_AUTHENTICATED_2FA_IN_PROGRESS`, and passkey registration requires `IS_AUTHENTICATED_FULLY`, so a session resumed from the 30-day remember-me cookie cannot mint a permanent credential from a stolen cookie.

**Defence in depth where one control is known to be insufficient.** R4 assumes the database leaks and still argues the tokens in it are unusable. R7 assumes a token is stolen and argues its blast radius is the issuing user's role.

**Not covered.** These are arguments about the code as it stands, reconstructed from it. No formal design review precedes a change; what enforces the principles between reviews is the tests named above and the `CI Success` gate.

## What this assurance case does not argue

Three gaps, named so that nobody mistakes silence for coverage:

- **No second reviewer.** One maintainer merges. Bot review is the only independent read, and it is best-effort — through this project's own experience, a monthly quota or a rate limit can remove it for days at a time.
- **Semgrep does not block.** It reports to code scanning. [`vulnerability-management.md`](vulnerability-management.md#what-blocks-and-by-when) states the threshold at which it should; until that is enforced in the workflow, the threshold is a policy and not a control.
- **No fuzzing, no penetration test.** Nothing here argues about input the tests did not imagine. The suites pin the cases someone thought of.
