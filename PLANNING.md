# Upgrade Plan: Symfony 4.4 to 7.3

This document outlines the high-level plan for upgrading the application from Symfony 4.4 to Symfony 7.3.

## Overall Strategy

The upgrade proceeds incrementally through major versions to minimize risk and manage complexity:

1.  **Symfony 4.4 -> Symfony 5.4** (Completed)
2.  **Symfony 5.4 -> Symfony 6.4** (Completed)
3.  **Symfony 6.4 -> Symfony 7.3** (Completed)

Doctrine has been updated to ORM 3 and PHPUnit to 12.3; Symfony is at 7.3; deprecations have been resolved in tests and phpunit.xml migrated to 12.3 schema. Static analysis (Psalm) is clean; PHPStan issues remain tracked in TASKS.md. CI for tests and static analysis is configured in GitHub Actions.

## Phases

Each major version upgrade will follow a similar pattern:

1.  **Preparation Phase (Current Version):**
    *   **Improve Test Coverage:** Add missing unit, integration, and functional tests.
    *   **Address Deprecations:** Identify and fix deprecations reported for the *next* major version using tools like the Symfony Deprecation Detector.
    *   **Refactor to Best Practices:** Ensure the codebase adheres to the best practices of the *current* Symfony version (e.g., directory structure, service configuration, annotations/attributes, removing legacy code).
    *   **Static Analysis & Code Standards:** Ensure code passes configured PHPStan/Psalm levels and adheres to PSR-12.

2.  **Upgrade Phase:**
    *   Update `composer.json` to the target Symfony version and related dependencies.
    *   Run `composer update`.
    *   Address immediate compatibility issues and errors arising from the update.
    *   Update configuration files (`services.yaml`, `routes.yaml`, `packages/`, etc.) as required by the new version.
    *   Consult the official Symfony Upgrade Guide for the target version.

3.  **Post-Upgrade Phase (New Version):**
    *   **Testing:** Run all tests (unit, integration, functional) to ensure application stability.
    *   **Manual Testing:** Perform thorough manual testing of critical application flows.
    *   **Documentation Update:** Update internal documentation (README, development setup, etc.) to reflect changes.

## Detailed Tasks

Specific, actionable tasks for each phase are detailed in `TASKS.md`.
