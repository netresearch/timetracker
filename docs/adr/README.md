# Architecture Decision Records (ADRs)

This directory contains Architecture Decision Records (ADRs) that document key technical decisions made for the TimeTracker project. Each ADR follows a standard format and captures the context, decision rationale, and consequences of important architectural choices.

ADRs are historical records: bodies are not rewritten when reality moves on. Where an older ADR describes infrastructure that was never built, a dated **reality note** at the top of the file says so and links to the current state ("reality note" in the Status column below).

## ADR Index

| ADR | Title | Status | Date |
|-----|-------|--------|------|
| [ADR-001](ADR-001-php-8-4-symfony-7-3-selection.md) | PHP 8.4 and Symfony 7.3 Selection | Superseded by ADR-015 | 2024-09-15 |
| [ADR-002](ADR-002-doctrine-orm-vs-raw-sql.md) | Doctrine ORM vs Raw SQL Strategy | Accepted | 2024-09-15 |
| [ADR-003](ADR-003-jira-integration-architecture.md) | JIRA Integration Architecture | Amended by ADR-017 | 2024-09-15 |
| [ADR-004](ADR-004-authentication-strategy-ldap-local.md) | Authentication Strategy (LDAP + Local) | Accepted (reality note) | 2024-09-15 |
| [ADR-005](ADR-005-caching-strategy.md) | Caching Strategy | Accepted (reality note) | 2024-09-15 |
| [ADR-006](ADR-006-testing-philosophy.md) | Testing Philosophy | Superseded by ADR-013 | 2024-09-15 |
| [ADR-007](ADR-007-api-design-patterns.md) | API Design Patterns | Accepted (reality note) | 2024-09-15 |
| [ADR-008](ADR-008-database-performance-optimization.md) | Database Performance Optimization | Accepted (reality note) | 2024-09-15 |
| [ADR-009](ADR-009-service-layer-pattern.md) | Service Layer Pattern Implementation | Accepted | 2025-09-09 |
| [ADR-010](ADR-010-repository-pattern-refactoring.md) | Repository Pattern Refactoring | Accepted | 2025-09-09 |
| [ADR-011](ADR-011-security-architecture.md) | Security Architecture - LDAP Authentication and Token Encryption | Accepted | 2025-09-09 |
| [ADR-012](ADR-012-performance-optimization-strategy.md) | Performance Optimization Strategy | Accepted (reality note) | 2025-09-09 |
| [ADR-013](ADR-013-testing-strategy.md) | Testing Strategy | Accepted (reality note) | 2025-09-09 |
| [ADR-014](ADR-014-typography-and-font-preferences.md) | Typography and Font Preferences | Accepted | 2026-06-25 |
| [ADR-015](ADR-015-php-8-5-symfony-8-upgrade.md) | PHP 8.5 and Symfony 8 Upgrade | Accepted | 2026-01-17 |
| [ADR-016](ADR-016-solidjs-frontend-rewrite.md) | SolidJS Frontend Rewrite (ExtJS Replacement) | Accepted | 2026-06-12 |
| [ADR-017](ADR-017-jira-cloud-oauth2.md) | Jira Cloud Support via OAuth 2.0 (Dual-Mode Integration) | Accepted | 2026-06-22 |

## ADR Format

Each ADR follows this standard structure:

- **Status**: Current state (Proposed → Accepted → Deprecated → Superseded)
- **Date**: When the decision was made
- **Context**: Background information and requirements that led to the decision
- **Decision**: The architectural choice made and key implementation details
- **Consequences**: Positive and negative impacts of the decision

## Key Architectural Themes

### Platform
- **PHP 8.5 / Symfony 8.1** ([ADR-015](ADR-015-php-8-5-symfony-8-upgrade.md)) with Doctrine ORM ([ADR-002](ADR-002-doctrine-orm-vs-raw-sql.md))
- **MariaDB 12.1** as the database ([compose.yml](../../compose.yml))
- **APCu** application caching — single-layer, no Redis ([docs/apcu-setup.md](../apcu-setup.md))

### Frontend
- **SolidJS SPA** under `/ui` with Vite 8, Tailwind CSS 4 and bun ([ADR-016](ADR-016-solidjs-frontend-rewrite.md))
- Accessibility target: WCAG 2.2 AA plus a documented AAA subset ([frontend/README.md](../../frontend/README.md))

### Enterprise Integration
- **LDAP/Active Directory** session-based authentication via a custom `LdapAuthenticator` ([ADR-011](ADR-011-security-architecture.md))
- **Jira worklog synchronization**: OAuth 1.0a for Server/DC ([ADR-003](ADR-003-jira-integration-architecture.md)) plus OAuth 2.0 configuration for Cloud ([ADR-017](ADR-017-jira-cloud-oauth2.md))

### Security & Quality
- **AES-256-GCM encryption at rest** for Jira OAuth tokens ([ADR-011](ADR-011-security-architecture.md))
- CSRF-protected, session-based login and logout ([config/packages/security.yaml](../../config/packages/security.yaml))
- **PHPStan level 10**, PHPUnit 13, Playwright E2E with axe accessibility checks ([ADR-013](ADR-013-testing-strategy.md), [docs/testing.md](../testing.md))

## Reviewing and Updating ADRs

### When to Create an ADR
- Significant architectural decisions affecting multiple components
- Technology selection with long-term impact
- Design patterns that will be used across the codebase
- Security or performance decisions with system-wide implications

### ADR Review Process
1. **Draft**: Create ADR with "Proposed" status
2. **Team Review**: Architecture team reviews context and decision
3. **Stakeholder Input**: Relevant teams provide feedback
4. **Decision**: Mark as "Accepted" or return to draft
5. **Implementation**: Reference ADR during implementation
6. **Retrospective**: Review consequences after implementation

### Updating Existing ADRs

When architectural decisions change:

1. **Don't modify existing ADRs** - they are historical records
2. **Create new ADR** that references and supersedes the old one
3. **Update old ADR status** to "Superseded by ADR-XXX"
4. **Document migration path** from old to new approach

## Related Documentation

- [Technology Stack](../techstack.md) - High-level system design
- [Development Guide](../development.md) - Getting started
- [API Documentation](../api.md) - Complete API reference
- [Security Guide](../security.md) - Security patterns

## Contributing

When proposing new ADRs:

1. Use the ADR template format consistently
2. Provide clear context and alternatives considered
3. Include implementation examples where helpful
4. Consider long-term maintenance implications
5. Get review from architecture team before marking as accepted

For questions about existing ADRs or architectural decisions, please reach out to the architecture team or create a discussion in the project repository.
