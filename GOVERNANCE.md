# Governance

This document describes how netresearch/timetracker is run: who decides, how a change gets in, and what happens if the current maintainer stops. It records the arrangement that exists today, not an aspiration.

## Project scope and ownership

TimeTracker is developed and used by [Netresearch DTT GmbH](https://www.netresearch.de/) and published under the [AGPL-3.0](LICENSE). The repository lives in the [netresearch](https://github.com/netresearch) GitHub organisation; the organisation owners hold repository administration (settings, branch protection, secrets, releases infrastructure).

## Roles

| Role | Who | What they may do |
|------|-----|------------------|
| Maintainer | listed in [MAINTAINERS.md](MAINTAINERS.md) | Merge pull requests, cut releases, handle security reports, change CI and repository configuration |
| Collaborator | users with `push` on the repository | Push branches, open pull requests, review, triage issues |
| Contributor | anyone | Open issues and pull requests from a fork, take part in discussions |

The current maintainer count is one. This is stated plainly rather than hidden: it is the single largest risk to the project and it shapes every rule below.

## How decisions are made

- **Ordinary changes** — bug fixes, dependency bumps, documentation, refactorings without a behaviour change: the maintainer decides, in the pull request.
- **Behaviour and API changes** — anything that changes what an existing installation does, including database migrations and changes to the REST or MCP surface: discussed in an issue before the pull request, and the reasoning ends up either in the pull request body or in an ADR under [`docs/adr/`](docs/adr).
- **Architecture decisions** — decisions with a lifetime longer than one release: recorded as an ADR. An ADR is the artefact of record; a decision that only exists in a merged pull request will be re-argued.
- **Breaking changes** — agreed with a maintainer before merge, announced in [`docs/BREAKING-CHANGES.md`](docs/BREAKING-CHANGES.md) and in the release notes.

There is no vote and no committee. Disagreement is resolved in the issue or pull request thread; if it stays unresolved, the maintainer decides and says why.

## Review policy

The full, read-back state of what branch protection enforces is in [CONTRIBUTING.md](CONTRIBUTING.md), section *Merge Requirements*. The part that is policy rather than machinery:

- **Every change goes through a pull request.** Nobody pushes to `main`, maintainer included — branch protection rejects it.
- **No approving review is required** (`required_approving_review_count: 0`). With one maintainer, a mandatory second approval would either block every change or be satisfied by a rubber stamp; neither is a control. This is a deliberate decision and the reason [OpenSSF two_person_review and OSPS-QA-07.01 are recorded as unmet](https://www.bestpractices.dev/projects/11719) rather than worked around.
- **What replaces it:** the aggregate `CI Success` check (frontend build, lint, four static analysers, unit, integration and E2E tests, schema-drift), a required `DCO` check, mandatory commit signing, mandatory resolution of every review thread, and an automatically requested Copilot review on every pull request that leaves draft. None of these is a human second pair of eyes, and we do not claim otherwise.
- **A pull request is reviewed until a round turns up no findings.** A review round that changes code also updates the pull request body.
- **Automated dependency updates** are merged by the shared `auto-merge-deps` workflow once the same required checks pass. Major bumps are approved but not auto-merged; a maintainer looks at them.

If a second maintainer joins, the first change is to require one approving review, and this section is rewritten in the same pull request.

## Becoming a maintainer

There is no fixed commit count. An invitation follows from sustained, reviewed contribution: several merged non-trivial pull requests, useful review of other people's changes, and familiarity with the release and security process. The invitation is issued by a current maintainer with the agreement of the organisation owners, and results in a commit to [MAINTAINERS.md](MAINTAINERS.md) and [`.github/CODEOWNERS`](.github/CODEOWNERS).

A maintainer who has been inactive for twelve months moves to the emeritus section of MAINTAINERS.md and loses merge rights; they may return by asking.

## Succession

If the current maintainer becomes unavailable, the Netresearch organisation owners retain full administrative access to the repository, the `ghcr.io/netresearch/timetracker` package namespace and the GitHub Actions configuration, and can appoint a new maintainer without any handover from the previous one. Nothing needed to run, release or fix this project depends on a personal account: the release path runs on the `GITHUB_TOKEN` that GitHub Actions mints per run, and deployment credentials are held by Netresearch IT, not by an individual — see [`docs/secrets-management.md`](docs/secrets-management.md).

The project is AGPL-3.0 licensed, so a fork is always available as a last resort.

## Changing this document

Through a pull request, like everything else. A change that loosens a control (fewer required checks, a wider auto-merge scope) states in the pull request body what it stops detecting.
