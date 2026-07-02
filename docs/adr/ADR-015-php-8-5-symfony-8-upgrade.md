# ADR-015: PHP 8.5 and Symfony 8 Upgrade

**Status:** Accepted
**Date:** 2026-01-17
**Supersedes:** [ADR-001](ADR-001-php-8-4-symfony-7-3-selection.md)

## Context

[ADR-001](ADR-001-php-8-4-symfony-7-3-selection.md) selected PHP 8.4 with Symfony 7.3
as the core stack. To stay on currently supported PHP and Symfony releases and avoid
accumulating upgrade debt, the stack was upgraded as soon as the new major versions
were usable (commit `d2e3c912`, "feat: upgrade to PHP 8.5 and Symfony 8.0",
2026-01-17). Symfony has since moved to 8.1 through routine dependency bumps.

## Decision

Run the application on **PHP 8.5** and the **Symfony 8** release line.

Current, verified state ([composer.json](../../composer.json)):

- `require.php`: `^8.5`, with a composer platform pin of `php: 8.5` so dependency
  resolution always targets PHP 8.5
- Symfony packages: `^8.1` across the stack (`symfony/framework-bundle`,
  `security-bundle`, `console`, …), enforced by `extra.symfony.require: ^8.1`;
  `composer.lock` resolves `symfony/framework-bundle` to `v8.1.0`
- Doctrine ORM `^3.5` with `doctrine/doctrine-bundle` `^3.2`

## Consequences

### Positive

- Supported PHP/Symfony versions with active security fixes
- Access to current language and framework features without a future big-bang migration
- Symfony minor upgrades (8.0 → 8.1) arrive as routine dependency bumps

### Negative

- Bleeding-edge platform requires bleeding-edge tooling: PHPStan 2.x at
  **level 10** with `bleedingEdge` and strict rules ([phpstan.neon](../../phpstan.neon)),
  **PHPUnit 13** (`phpunit/phpunit ^13.0`), Rector 2.x
- Third-party packages occasionally lag behind new PHP/Symfony majors; upgrades of
  this kind need a compatibility check across the dependency tree

## Related ADRs

- [ADR-001](ADR-001-php-8-4-symfony-7-3-selection.md): PHP 8.4 and Symfony 7.3 Selection (superseded)
