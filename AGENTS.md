<!-- Managed by agent: keep sections and order; edit content, not structure. -->

# AGENTS.md (root)

This file explains repo-wide conventions and where to find scoped rules.
**Precedence:** the **closest `AGENTS.md`** to the files you're changing wins. Root holds global defaults only.

## Global rules

- Keep diffs small; add tests for new code paths
- Ask first before: adding heavy deps, running full e2e suites, or repo-wide rewrites
- Never commit secrets or sensitive data to the repository
- PHP: PSR-12, strict types, typed parameters/returns
- Run commands via Docker Compose (`docker compose --profile dev exec app-dev ...`)

## Minimal pre-commit checks

- Typecheck: `composer analyze` (PHPStan level 9)
- Lint/format: `composer cs-check` / `composer cs-fix`
- Tests: `composer test`

## Index of scoped AGENTS.md

| Path | Purpose |
|------|---------|
| [`src/AGENTS.md`](src/AGENTS.md) | PHP backend code patterns, Symfony conventions |
| [`tests/AGENTS.md`](tests/AGENTS.md) | Testing patterns, PHPUnit, test database setup |

## When instructions conflict

- The nearest `AGENTS.md` wins. Explicit user prompts override files.
- For Symfony-specific patterns, defer to `src/AGENTS.md`.
