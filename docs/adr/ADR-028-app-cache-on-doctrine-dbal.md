# ADR-028: App Cache on Doctrine DBAL instead of APCu

**Status:** Accepted — 2026-11-19
**Relates to:** [ADR-005](ADR-005-caching-strategy.md) (chose APCu for the app cache; its backend choice is superseded here), [ADR-019](ADR-019-session-storage-and-lock-contention.md) (lists the login-throttling counters among the node-local state)

## Context

The Symfony app cache (`cache.app`) used APCu. Its consumers in the code at this date:

- the login-throttling rate limiter of the `main` firewall (`login_throttling`, five attempts), whose storage falls back to `cache.app`;
- `doctrine.result_cache_pool` in the `prod` environment, which no query in `src/` enables, so it stores nothing;
- the admin status page, which reported APCu as a subsystem.

Moving to PHP 8.6 made the dependency visible. APCu 5.1.28 does not compile against PHP 8.6, the fixed 5.1.29 was tagged and withdrawn again, and the image cannot be built until a release exists. The extension also had a functional drawback for its one real use: APCu is wiped on every FPM reload and every deploy, which resets the brute-force counters, and it is per container, so a second app container would count attempts separately.

## Decision

**`cache.app` uses Symfony's Doctrine DBAL adapter on the application database.** Items live in the table `cache_items`, which `migrations/Version20261119_AppCacheTable.php` creates with the columns the adapter itself would create, and which `sql/full.sql` contains for fresh installs.

**APCu is removed** from `composer.json`, the image build and the status page; the status page reports the database cache and its row count instead.

**Expired rows are pruned on container start.** The adapter removes expired items only through `cache:pool:prune`, so `docker/php/docker-entrypoint.sh` runs it after the migrations. A failed prune only warns.

## Consequences

- Login-throttling counters survive FPM reloads and deploys, and would be shared by several app containers.
- Each login attempt costs a database round-trip for the limiter. Login is not a hot path.
- The image needs no PECL extension for caching, which removes the PHP 8.6 build blocker from APCu.
- Between two container starts, expired rows stay in `cache_items`. Each row is one limiter key (username or IP), so a username spray grows the table by one small row per name until the next start.
- Considered and rejected: the filesystem adapter (no extension either, but counters are lost when the container is replaced and are per container) and keeping APCu (blocks PHP 8.6 until an upstream release, keeps the reset-on-reload behaviour).
