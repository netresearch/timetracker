# Security Policy

## Supported Versions

Only the current minor line receives fixes. A new minor release ends support for the previous one; upgrade to the latest patch of the current minor.

| Version         | Supported          | End of life                                   |
|-----------------|--------------------|-----------------------------------------------|
| 6.3.x           | :white_check_mark: | when 6.4.0 is released                        |
| 6.0.x – 6.2.x   | :x:                | superseded by the following minor release     |
| 5.x             | :x:                | 2026-07-04, with the release of v6.0.0        |
| 4.x             | :x:                | 2026-07-05, final release [`v4.5.0`](https://github.com/netresearch/timetracker/releases/tag/v4.5.0) |
| < 4.0           | :x:                | unsupported                                   |

There are no long-term-support branches. Security fixes are released as a patch on the current minor line and are not backported.

## Reporting a Vulnerability

We take security vulnerabilities seriously. If you discover a security issue, please report it responsibly.

### How to Report

**Do NOT report security vulnerabilities through public GitHub issues.**

Instead, please use [GitHub's private vulnerability reporting](https://github.com/netresearch/timetracker/security/advisories/new).

Include the following information:
- Description of the vulnerability
- Steps to reproduce
- Potential impact
- Suggested fix (if any)

### What to Expect

- **Acknowledgment**: We will acknowledge receipt within 48 hours
- **Initial Assessment**: We will provide an initial assessment within 5 business days
- **Resolution Timeline**: a fix for a Critical finding is released within 7 days of the report, for a High finding within 30 days. The full table, including dependency findings, is in [docs/vulnerability-management.md](docs/vulnerability-management.md).
- **Credit**: We will credit reporters in our release notes (unless you prefer to remain anonymous)

### Security Measures

This project implements several security measures:

- LDAP-based authentication with LDAP injection prevention
- Role-based access control (DEV, PL, CTL, ADMIN)
- Stateless CSRF tokens on the login and logout flows, and `SameSite=Lax` on the session cookie
- `X-Frame-Options`, `X-Content-Type-Options` and `Referrer-Policy` on every response ([`SecurityHeadersSubscriber`](src/EventSubscriber/SecurityHeadersSubscriber.php))
- A nonce-based Content Security Policy, currently in report-only mode, on main-request HTML responses that do not already carry one ([`ContentSecurityPolicySubscriber`](src/EventSubscriber/ContentSecurityPolicySubscriber.php), [#739](https://github.com/netresearch/timetracker/issues/739))
- AES-256-GCM encryption for sensitive tokens
- Automated dependency updates via GitHub Dependabot ([.github/dependabot.yml](.github/dependabot.yml))
- CodeQL code scanning ([codeql.yml](.github/workflows/codeql.yml)) and npm dependency audits ([security.yml](.github/workflows/security.yml)) in CI; `composer audit` via `make audit`
- OpenSSF Scorecard and Best Practices compliance

### Related policies

| Document | What it answers |
|----------|-----------------|
| [docs/security.md](docs/security.md) | How authentication, authorisation, CSRF and token encryption are implemented |
| [docs/threat-model.md](docs/threat-model.md) | What is worth attacking, which control answers each threat, and what risk is accepted |
| [docs/vulnerability-management.md](docs/vulnerability-management.md) | Which scanners run, what blocks a build, and how long a finding may stay open |
| [docs/dependency-policy.md](docs/dependency-policy.md) | How third-party code enters the project and what the bots may merge |
| [docs/secrets-management.md](docs/secrets-management.md) | Where secrets live, who can read them, when they are rotated |
| [GOVERNANCE.md](GOVERNANCE.md) | Who decides, and why no approving review is required |

## Security Updates

Security updates are released as patch versions. We recommend:

1. Subscribe to GitHub releases for notifications
2. Keep your installation up to date
3. Review the [GitHub release notes](https://github.com/netresearch/timetracker/releases) for security-related changes

## Scope

This security policy covers the TimeTracker application code. Third-party dependencies are managed through Composer (PHP), bun (frontend), and npm (e2e tooling), with automated security scanning enabled.
