<!-- Managed by agent: keep sections and order; edit content, not structure. -->

# AGENTS.md (root)

This file explains repo-wide conventions and where to find scoped rules.
**Precedence:** the **closest `AGENTS.md`** to the files you're changing wins. Root holds global defaults only.

## Global rules

- Keep diffs small; add tests for new code paths
- Ask first before: adding heavy deps, running full e2e suites, or repo-wide rewrites
- Never commit secrets or sensitive data to the repository
- PHP: PER-CS + Symfony style (PHP-CS-Fixer), strict types, typed parameters/returns
- Run commands via Docker Compose (`docker compose --profile dev exec app-dev ...`)

## Minimal pre-commit checks

- Typecheck: `composer analyze` (PHPStan level 10)
- Lint/format: `composer cs-check` / `composer cs-fix`
- Tests: `composer test`

The CI "Lint & Static Analysis" gate runs FOUR static tools, not two: phpstan, phpat (`bin/phpstan analyze -c config/quality/phpat.neon`), php-cs-fixer, and rector (`bin/rector process src --config=config/quality/rector.php --dry-run`). Run all four on changed PHP before pushing — rector is the one that gets forgotten. Tools live in `bin/` (composer bin-dir), not `vendor/bin`. When a change touches a base/parent method signature, run FULL-tree phpstan (`bin/phpstan analyze --no-progress`), not file-scoped — anonymous-class overrides in `tests/` break invisibly otherwise.

The captainhook pre-commit hook runs the unit suite on the HOST php. A host without `pdo_mysql` fails with "could not find driver" — never bypass with `--no-verify`; either run the suite in the container (`docker compose --profile dev exec -T -e APP_ENV=test -e DATABASE_URL=mysql://unittest:unittest@db_unittest:3306/unittest app-dev bin/phpunit …`) or point `DATABASE_URL` at the unittest DB via a real env var. If phpat aborts with a Nette `ContainerLoader … .lock` error after a container run, it is a uid-split on `var/phpstan-phpat/` — remove that cache dir, it is not an architecture violation. Container and host runs regenerate `config/reference.php`; restore it (`git checkout -- config/reference.php`) before committing, never stage it.

## Releases

No workflow creates releases. `docker-publish.yml` builds images on tag push; `slsa-provenance.yml` runs on `release: published`; treat the release as immutable once its provenance asset is uploaded (the tag/assets are locked and the provenance attests the published state — do not rely on editing anything after publish), so get the notes right before publishing. Order: verify main CI green → `git tag -s vX.Y.Z -m "vX.Y.Z"` → `git push origin vX.Y.Z` (Docker Publish runs) → `gh release create vX.Y.Z --title "vX.Y.Z" --notes-file <notes>` (SLSA runs). The metadata-action strips the leading `v`: images are `:6.0.0`/`:6.0`/`:6`. Credit contributors inline per change with `@mentions` (GitHub builds the Contributors row from body mentions); auto-generated notes miss direct pushes — cross-check via the compare API. Credit the human driving an agent-authored commit (committer / `Co-authored-by`), never a bot author.

## Index of scoped AGENTS.md

| Path | Purpose |
|------|---------|
| [`src/AGENTS.md`](src/AGENTS.md) | PHP backend code patterns, Symfony conventions |
| [`tests/AGENTS.md`](tests/AGENTS.md) | Testing patterns, PHPUnit, test database setup |
| [`frontend/AGENTS.md`](frontend/AGENTS.md) | SolidJS SPA: bun commands, Solid 1.9 conventions, i18n, a11y |
| [`e2e/AGENTS.md`](e2e/AGENTS.md) | Playwright e2e suite: stack, test users, frozen clock |

## When instructions conflict

- The nearest `AGENTS.md` wins. Explicit user prompts override files.
- For Symfony-specific patterns, defer to `src/AGENTS.md`.
