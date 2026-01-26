# Security Policy

## Supported Versions

| Version | Supported          |
|---------|--------------------|
| 5.x.x   | :white_check_mark: |
| 4.x.x   | :white_check_mark: |
| < 4.0   | :x:                |

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
- **Resolution Timeline**: Critical issues will be addressed as quickly as possible
- **Credit**: We will credit reporters in our release notes (unless you prefer to remain anonymous)

### Security Measures

This project implements several security measures:

- LDAP-based authentication with LDAP injection prevention
- Role-based access control (DEV, PL, CTL, ADMIN)
- CSRF protection on all state-changing operations
- AES-256-GCM encryption for sensitive tokens
- Strict Content Security Policy
- Regular dependency security audits via GitHub Dependabot and Snyk
- OpenSSF Scorecard and Best Practices compliance

For detailed security documentation, see [docs/security.md](docs/security.md).

## Security Updates

Security updates are released as patch versions. We recommend:

1. Subscribe to GitHub releases for notifications
2. Keep your installation up to date
3. Review the CHANGELOG for security-related changes

## Scope

This security policy covers the TimeTracker application code. Third-party dependencies are managed through Composer and npm, with automated security scanning enabled.
