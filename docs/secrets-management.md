# Secrets Management Policy

Where the secrets this project needs are kept, who can read them, and how they are rotated. It covers the repository and its CI, not a specific customer deployment — a deployment's own credentials are held by whoever operates it.

Scope: everything that grants access to something. Credentials, API tokens, signing material, the application secret, LDAP bind passwords, database passwords.

## The rule

**No secret is ever committed to this repository, in any branch, in any file, including test fixtures and documentation examples.** A secret that reaches a commit is compromised the moment it is pushed: the object stays reachable in forks, clones and caches even after a force push. The response to a leak is rotation, never deletion of the commit alone.

## Where secrets live

| Kind | Store | Who can read it |
|------|-------|-----------------|
| CI secrets used by workflows | GitHub Actions secrets, on the repository or on the `netresearch` organisation | GitHub Actions at run time; organisation owners and repository admins may set but not read them back |
| Container registry publishing | `GITHUB_TOKEN`, minted per workflow run | Only the job it is issued to |
| Image signing | Keyless, OIDC-issued short-lived certificates (Sigstore/Fulcio) | Nobody — there is no long-lived signing key to steal |
| Deployment credentials (LDAP bind, database, Jira, Personio) | The operator's own secret store; at Netresearch, HashiCorp Vault | The operations team of that deployment |
| Jira OAuth tokens of application users | Encrypted at rest in the application database with AES-256-GCM (`TokenEncryptionService`), key from `JIRA_TOKEN_ENCRYPTION_KEY`, falling back to `APP_SECRET` | The running application |
| Local development values | `.env.local`, git-ignored; never `.env` | The developer on their own machine |

`.env` is committed on purpose. It carries Symfony's development defaults — including the placeholder `APP_SECRET=ThisTokenIsNotSoSecretChangeIt` — and is not a secret store. Every deployment overrides these through real environment variables or `.env.local`. A deployment that still runs with the placeholder `APP_SECRET` is misconfigured: it weakens CSRF token signing and, where no dedicated key is set, the encryption of stored Jira tokens.

## Access

- Repository and organisation secrets are set by organisation owners and repository administrators. GitHub does not allow reading a secret back, so the authoritative copy lives in the operator's secret store, not in GitHub.
- A workflow reaches a secret only when the job it runs in is granted it. Fork pull requests do not receive secrets.
- Every secret has one owner. A secret nobody can name an owner or a consumer for is removed, not kept "just in case" — see *Inventory* below.

## Rotation

| Trigger | Action |
|---------|--------|
| Suspected or confirmed exposure | Rotate immediately, then investigate. Treat the old value as public. |
| A person with access leaves the project or the company | Rotate every secret they could read, within five working days. |
| Scheduled | At least once every twelve months for every long-lived credential. |
| Never needed | Keyless signing material and `GITHUB_TOKEN` — they are short-lived by construction and expire on their own. |

The application secret `APP_SECRET` is a special case: rotating it invalidates existing CSRF tokens and, where `JIRA_TOKEN_ENCRYPTION_KEY` is unset, makes stored Jira tokens undecryptable. Set a dedicated `JIRA_TOKEN_ENCRYPTION_KEY` so the two can be rotated independently.

## Inventory

The set of secrets is reviewed with every roadmap review (see [ROADMAP.md](../ROADMAP.md)). The review asks two questions per secret: which workflow or service consumes it, and when was it last rotated. A secret with no consumer is deleted in the same review — an unused credential is exposure without benefit.

## Reporting a leak

A secret found in the repository, in a log or in an artefact is a security vulnerability: report it through [private vulnerability reporting](https://github.com/netresearch/timetracker/security/advisories/new), never as a public issue. See [SECURITY.md](../SECURITY.md).

## Detection

- GitHub secret scanning with push protection is enabled for this repository; a push containing a recognised credential pattern is rejected.
- Dependabot and the audit jobs described in [`dependency-policy.md`](dependency-policy.md) do not detect secrets — they are a separate control and neither substitutes for the other.
