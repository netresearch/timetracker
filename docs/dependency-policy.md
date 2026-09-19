# Dependency Policy

How third-party code enters this project, how it is kept current, and what the automation is allowed to do without a human.

## Adding a dependency

A new runtime dependency is a decision, not a convenience. Before it is added, the pull request that adds it states:

- **What it replaces.** Standard library, framework component, or twenty lines of our own code — if the last one, the dependency usually loses.
- **Its licence**, and that it is compatible with AGPL-3.0. `composer.json` and `package.json` carry a `license` field; a dependency without a clear licence is not added.
- **Its maintenance state.** Last release, open issue count, whether it has more than one maintainer. An unmaintained package is a future migration we have agreed to pay for.
- **Its transitive weight.** A package that pulls in thirty others is thirty packages, not one.

Development-only dependencies are held to a lower bar, because they do not reach a deployment — but they do reach the CI runner, and a compromised build tool is a compromised build.

Adding a heavy dependency is one of the things [AGENTS.md](../AGENTS.md) says to ask about first.

## Pinning

| Ecosystem | How it is pinned |
|-----------|------------------|
| PHP (Composer) | `composer.lock`, committed. CI installs from the lockfile. |
| Frontend (bun) | `frontend/bun.lock`, committed. |
| E2E tooling (npm) | `package-lock.json`, committed. |
| GitHub Actions | Third-party actions pinned to a full commit SHA with the version in a trailing comment. Reusable workflows from `netresearch/.github` use `@main` by convention — they are inside our own trust boundary. |
| Container base images | Pinned by digest in `compose.yml`, and in `docker-bake.hcl` for everything the image build consumes. The `Dockerfile` itself carries no digest: it takes each base as an `ARG` (`PHP_BASE_IMAGE`, `NODE_BASE_IMAGE`, `SYMFONY_CLI_IMAGE`, `BUN_IMAGE`, `COMPOSER_IMAGE`), and the bake file supplies the pinned value. |

An `overrides` or `resolutions` entry that exists to force a vulnerable transitive package upwards names the advisory in a comment or in the commit message, and its floor is the **first patched version**, not the version that happened to be current when it was written. A floor below the first patched version is not a fix: it merely happens to resolve to something safe today.

## Keeping dependencies current

Two bots run against this repository:

- **Dependabot** ([`.github/dependabot.yml`](../.github/dependabot.yml)) — weekly, for `github-actions`, `bun`, `npm`, `composer` and `docker-compose`, with updates grouped per ecosystem so one pull request carries the whole week.
- **Renovate** ([`renovate.json`](../renovate.json)), extending the shared `netresearch/renovate-config`, which keeps the dependency dashboard issue.
- **Dependabot security updates** are enabled on the repository, so an advisory against a locked dependency produces a pull request without waiting for the weekly run.

Dependency pull requests pass exactly the same required checks as a human's. There is no fast lane.

## What the automation may merge on its own

The shared `auto-merge-deps` workflow ([`.github/workflows/auto-merge-deps.yml`](../.github/workflows/auto-merge-deps.yml)) merges a dependency pull request once every required check is green:

- **Patch and minor updates** — approved and merged automatically.
- **Major updates** — approved, but **not** merged. A maintainer looks at the changelog and merges by hand.
- **Anything that changes `.github/workflows/`** — not merged automatically; the token deliberately has no `workflows` scope.

## Vulnerable dependencies

Thresholds, scanners and remediation timelines are in [`vulnerability-management.md`](vulnerability-management.md).

## Removing a dependency

A dependency that is no longer used is removed in the same pull request that stops using it. An unused dependency still appears in the lockfile, still appears in the SBOM, and still generates advisories somebody has to triage.
