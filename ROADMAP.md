# Roadmap

What the maintainers intend to work on next, and what they explicitly do not intend to work on. It is a statement of direction, not a commitment to a date: anything with a date carries the issue that tracks it, and anything without an issue is direction only.

Last reviewed: 2026-09-18. Current release line: 6.3.x — see [SECURITY.md](SECURITY.md) for what that means for support.

## Now — in progress

| Item | Tracked as |
|------|------------|
| CI wall-clock: bring the pipeline from ~254 s down to 150–190 s | [#624](https://github.com/netresearch/timetracker/issues/624) |
| Supply chain: SBOM per published image, Cosign signatures, build provenance | this repository's `docker-publish.yml`, see [`docs/dependency-policy.md`](docs/dependency-policy.md) |
| Static analysis for PHP in code scanning (CodeQL has no PHP support) | [`docs/vulnerability-management.md`](docs/vulnerability-management.md) |
| Governance, threat model and policy documentation (OpenSSF Silver, OSPS Baseline 2) | this document set |

## Next — agreed direction, not scheduled

- **SPDX headers on every source file.** 623 of 897 files carry one; the remainder are mostly `e2e/`, `frontend/` and `config/`. Required for the OpenSSF Gold criteria `copyright_per_file` and `license_per_file`.
- **Reproducible builds.** Every base image and every tool the container build consumes is already pinned by digest in `docker-bake.hcl`, and the Node.js and Symfony CLI binaries are copied from pinned image stages rather than downloaded by a piped installer. What is still missing for `build_reproducible` is the other half: a documented procedure by which somebody outside the project can rebuild a published image and compare it, and a build that is bit-for-bit stable when they do.
- **Content Security Policy** ([#739](https://github.com/netresearch/timetracker/issues/739)). `X-Frame-Options`, `X-Content-Type-Options` and `Referrer-Policy` are now set on every response; a policy is not. It needs nonces on eight inline `<script>` blocks and an allowance for the `#nrnavi` corporate-navigation iframe, so it ships report-only first, with an end-to-end check reading what it would have blocked.
- **Frontend test depth.** The SolidJS SPA is covered by Vitest and Playwright; coverage of the newer interpretation and reporting views is thinner than the backend's.
- **Personio and Jira synchronisation hardening.** Both integrations talk to systems outside our trust boundary; see [`docs/threat-model.md`](docs/threat-model.md) for the boundaries this affects.

## Not planned

- **A second maintainer as a merge gate.** Requiring an approving review with one maintainer is described in [GOVERNANCE.md](GOVERNANCE.md); it will be introduced when there is a second maintainer, not before.
- **Support for more than the current minor line.** There are no long-term-support branches, and security fixes are not backported.
- **A hosted, multi-tenant offering.** TimeTracker is deployed per organisation against that organisation's LDAP directory.

## How this document is maintained

The roadmap is reviewed when a minor version is released, and whenever an item here is finished or dropped. An item gains a tracking issue as soon as work starts on it; an item that has been in *Next* for two release cycles without anyone touching it moves to *Not planned* or gets an issue.

Proposals belong in [GitHub Discussions](https://github.com/netresearch/timetracker/discussions) or as an issue — see [CONTRIBUTING.md](CONTRIBUTING.md).
