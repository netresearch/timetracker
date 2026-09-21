# Security Policy

## Supported Versions

Only the current minor line receives fixes. A new minor release ends support for the previous one; upgrade to the latest patch of the current minor.

| Version         | Supported          | End of life                                   |
|-----------------|--------------------|-----------------------------------------------|
| 6.4.x           | :white_check_mark: | when 6.5.0 is released                        |
| 6.0.x – 6.3.x   | :x:                | superseded by the following minor release     |
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
- An enforced, nonce-based Content Security Policy on main-request HTML responses that do not already carry one ([`ContentSecurityPolicySubscriber`](src/EventSubscriber/ContentSecurityPolicySubscriber.php), [#739](https://github.com/netresearch/timetracker/issues/739))
- AES-256-GCM encryption for sensitive tokens
- Automated dependency updates via GitHub Dependabot ([.github/dependabot.yml](.github/dependabot.yml))
- CodeQL code scanning ([codeql.yml](.github/workflows/codeql.yml)) and npm dependency audits ([security.yml](.github/workflows/security.yml)) in CI; `composer audit` via `make audit`
- OpenSSF Scorecard and Best Practices compliance

### Related policies

| Document | What it answers |
|----------|-----------------|
| [docs/security.md](docs/security.md) | How authentication, authorisation, CSRF and token encryption are implemented |
| [docs/threat-model.md](docs/threat-model.md) | What is worth attacking, which control answers each threat, and what risk is accepted |
| [docs/assurance-case.md](docs/assurance-case.md) | Why each security requirement is believed to hold: the control, the test that would fail without it, and what the argument leaves uncovered |
| [docs/vulnerability-management.md](docs/vulnerability-management.md) | Which scanners run, what blocks a build, and how long a finding may stay open |
| [docs/dependency-policy.md](docs/dependency-policy.md) | How third-party code enters the project and what the bots may merge |
| [docs/secrets-management.md](docs/secrets-management.md) | Where secrets live, who can read them, when they are rotated |
| [GOVERNANCE.md](GOVERNANCE.md) | Who decides, and why no approving review is required |

## Verifying a release

Every release carries a source archive, SPDX and CycloneDX SBOMs, `checksums.txt`, a Cosign signature bundle per file and a build-provenance attestation; every published image carries a Cosign signature and an SBOM attestation. Check them before you deploy.

The release files are built, signed and published by `netresearch/.github`'s `release-source-archive.yml`, a reusable workflow this repository cannot edit. That is what SLSA Build Level 3 asks of the build, and it is why the commands below name that workflow as the signer.

```bash
TAG=v6.5.0   # any release from v6.5.0 on; v6.4.0 is covered below
ARCHIVE="timetracker-$TAG-source.tar.gz"
SIGNER=netresearch/.github/.github/workflows/release-source-archive.yml

gh release download "$TAG" --repo netresearch/timetracker

# 1. the checksum list was signed by that reusable workflow, and every file it names matches.
#    Only files named in checksums.txt belong to the release; ignore anything else.
cosign verify-blob checksums.txt --bundle checksums.txt.sigstore.json \
  --certificate-identity-regexp '^https://github\.com/netresearch/\.github/\.github/workflows/release-source-archive\.yml@refs/heads/main$' \
  --certificate-oidc-issuer https://token.actions.githubusercontent.com
sha256sum -c checksums.txt

# 2. the archive was built by that reusable workflow from this repository
gh attestation verify "$ARCHIVE" --repo netresearch/timetracker --signer-workflow "$SIGNER"

# 3. the SBOM was attested to that same archive
gh attestation verify "$ARCHIVE" --repo netresearch/timetracker --signer-workflow "$SIGNER" \
  --predicate-type https://spdx.dev/Document/v2.3

# 4. the image is ours, signed without a long-lived key
cosign verify "ghcr.io/netresearch/timetracker:${TAG#v}" \
  --certificate-identity-regexp '^https://github\.com/netresearch/\.github/\.github/workflows/build-container-bake\.yml@refs/heads/main$' \
  --certificate-oidc-issuer https://token.actions.githubusercontent.com

# 5. the image's SBOM is the one that was attested to it
cosign verify-attestation --type cyclonedx \
  "ghcr.io/netresearch/timetracker:${TAG#v}" \
  --certificate-identity-regexp '^https://github\.com/netresearch/\.github/\.github/workflows/(build-container-bake|attest-sbom)\.yml@refs/heads/main$' \
  --certificate-oidc-issuer https://token.actions.githubusercontent.com
```

The identity patterns name the workflows that actually sign — all of them reusables in `netresearch/.github`, not workflows in this repository. Do not loosen them to `^https://github.com/netresearch/`: that accepts a certificate from **any** workflow in the organisation, so a signature produced somewhere else entirely would satisfy the check.

These commands check what `checksums.txt` names; they do not reject an extra file on the release. That check runs on every release instead: the `verify` job in `release.yml` calls `netresearch/.github`'s `verify-release.yml`, which fails when the release carries any file that `checksums.txt` does not list, or any file without a valid Cosign bundle from `release-source-archive.yml`.

Each command exits non-zero when the check fails. Read the exit status rather than the output: `gh attestation verify` at the version used here (gh 2.100.0) prints nothing at all on success, and other versions print a summary instead.

Without `--signer-workflow`, `gh attestation verify` checks the signer against this repository and rejects the attestation, because the signer is the reusable workflow.

**v6.4.0 has a different layout.** Its archive was built in this repository, so its provenance is SLSA Build Level 2. It carries `timetracker-v6.4.0-source.tar.gz.sha256` instead of `checksums.txt`, no SBOM and no Cosign bundle, and it holds two provenance attestations: one signed by this repository's former `slsa-provenance.yml`, one by `netresearch/.github`'s `attest-release-files.yml`. Either check passes:

```bash
gh release download v6.4.0 --repo netresearch/timetracker --pattern 'timetracker-v6.4.0-source.tar.gz*'
sha256sum -c timetracker-v6.4.0-source.tar.gz.sha256
gh attestation verify timetracker-v6.4.0-source.tar.gz --repo netresearch/timetracker
gh attestation verify timetracker-v6.4.0-source.tar.gz --repo netresearch/timetracker \
  --signer-workflow netresearch/.github/.github/workflows/attest-release-files.yml
```

## Security Updates

Security updates are released as patch versions. We recommend:

1. Subscribe to GitHub releases for notifications
2. Keep your installation up to date
3. Review the [GitHub release notes](https://github.com/netresearch/timetracker/releases) for security-related changes

## Scope

This security policy covers the TimeTracker application code. Third-party dependencies are managed through Composer (PHP), bun (frontend), and npm (e2e tooling), with automated security scanning enabled.
