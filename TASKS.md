# Detailed Task List

This document breaks down the upgrade plan into specific, actionable tasks.

## Phase 1: Prepare for Symfony 5.4 (Working on Symfony 4.4)

### 1.1: Improve Test Coverage
*   **Goal:** Ensure critical parts of the application are covered by tests before refactoring and upgrading.
*   **Tasks:**
    *   `[x]` **Analyze Test Coverage:** Run coverage reports (`docker compose run --rm -e APP_ENV=test app bin/phpunit --coverage-html var/coverage`) and identify key areas lacking tests (Controllers, Services, critical business logic). (Estimate: 0.5h)
    *   `[ ]` **Write Unit Tests for Core Services:** Identify core services in `/src/Service` (formerly `/src/Services`) and `/src/Helper` (to be refactored) and add unit tests. (Estimate: Task per service, 0.5-1h each)
        *   `[ ]` Task: Test Service A
        *   `[ ]` Task: Test Service B
        *   `[ ]` ... (add specific services as identified)
    *   `[ ]` **Write Integration Tests:** Add integration tests for interactions between key components (e.g., Service + Repository). (Estimate: Task per interaction, 0.5-1h each)
    *   `[ ]` **Write Functional Tests for Critical Routes:** Identify critical user-facing routes and add functional tests to ensure basic functionality. (Estimate: Task per route/feature, 0.5-1h each)
        *   `[ ]` Task: Test Route X
        *   `[ ]` Task: Test Feature Y
        *   `[ ]` ... (add specific routes/features as identified)

### 1.2: Address Deprecations for Symfony 5.4
*   **Goal:** Remove usage of APIs deprecated in Symfony 4.4 and scheduled for removal in 5.0.
*   **Tasks:**
    *   `[ ]` **Install Deprecation Detector:** Add `symfony/deprecation-contracts` if missing and potentially use `symfony/phpunit-bridge` or other tools to log deprecations during test runs. (Estimate: 0.5h)
    *   `[ ]` **Run Tests and Collect Deprecations:** Execute the test suite and collect all `@trigger_error('...', E_USER_DEPRECATED)` logs. (Estimate: 0.5h)
    *   `[ ]` **Fix Deprecations:** Go through the deprecation list and refactor code to use the recommended alternatives. (Estimate: Task per deprecation type, 0.25-1h each)
        *   `[ ]` Task: Fix Deprecation Type 1
        *   `[ ]` Task: Fix Deprecation Type 2
        *   `[ ]` ... (add specific deprecations as identified)

### 1.3: Refactor to Symfony 4.4 Best Practices
*   **Goal:** Align the codebase with standard Symfony 4.4 conventions.
*   **Tasks:**
    *   `[ ]` **(NEW TASK 1.3.0) Analyze Controller Structure:**
        *   `[ ]` Review major controllers (e.g., `EntryController`, `AdminController`, potentially others) for size and responsibilities. (Estimate: 1h)
        *   `[ ]` Identify methods or logic within controllers that could be extracted into dedicated services (e.g., complex data manipulation, external API calls, business rules). (Estimate: 1h)
        *   `[ ]` Document potential refactoring opportunities (create sub-tasks below or separate issues). (Estimate: 0.5h)
    *   `[x]` **(Updated Task 1.3.1) Move classes from `src/Services` to `src/Service`:**
        *   `[x]` Move `src/Services/Export.php` to `src/Service/ExportService.php` (or similar appropriate name). (Estimate: 0.1h)
        *   `[x]` Update namespace in the moved file (`App\Services` -> `App\Service`). (Estimate: 0.1h)
        *   `[x]` Update any explicit references in `services.yaml` or elsewhere. (Estimate: 0.1h)
        *   `[x]` Move `src/Services/SubticketSyncService.php` to `src/Service/SubticketSyncService.php`. (Estimate: 0.1h)
        *   `[x]` Update namespace in the moved file. (Estimate: 0.1h)
        *   `[x]` Update any explicit references. (Estimate: 0.1h)
        *   `[x]` Delete the `src/Services` directory once empty. (Estimate: 0.1h)
        *   `[ ]` Clear cache (`docker compose run --rm app bin/console cache:clear`). (Estimate: 0.1h)
    *   `[ ]` **(Updated Task 1.3.2) Refactor `src/Helper` Classes to Services:**
        *   `[ ]` **Refactor `JiraOAuthApi.php`:**
            *   `[x]` Move `src/Helper/JiraOAuthApi.php` to `src/Service/Integration/Jira/JiraOAuthApiService.php` (or similar). (Implemented as BC shim delegating to new service)
            *   `[x]` Update factory to create new service. Usages rely on factory DI.
            *   `[x]` Update usages to use Dependency Injection where needed (removed direct instantiation in `CrudController`).
        *   `[x]` **Refactor `LdapClient.php`:**
            *   `[x]` Move `src/Helper/LdapClient.php` to `src/Service/Ldap/LdapClientService.php` with BC shim. (Estimate: 0.1h)
            *   `[x]` Update namespace and register as a service. (Estimate: 0.1h)
            *   `[x]` Update usages via DI in Security component. (Estimate: 0.5h)
        *   `[ ]` **Refactor `LocalizationHelper.php`:**
            *   `[ ]` Move `src/Helper/LocalizationHelper.php` to `src/Service/Util/LocalizationService.php` (or similar). (Estimate: 0.1h)
            *   `[ ]` Update namespace. (Estimate: 0.1h)
            *   `[x]` Ensure registered as a service. (Estimate: 0.1h)
            *   `[ ]` Update usages via DI. (Estimate: 0.25h)
        *   `[ ]` **Refactor `TicketHelper.php`:**
            *   `[x]` Introduce `src/Service/Util/TicketService.php` and keep `TicketHelper` as BC facade. No DI changes required.
        *   `[ ]` **Refactor `TimeHelper.php`:**
            *   `[x]` Introduce `src/Service/Util/TimeCalculationService.php` and keep `TimeHelper` as BC facade. No DI changes required.
        *   `[ ]` **Handle remaining Helper files:** (`JiraApiException.php`, `JiraApiInvalidResourceException.php`, `JiraApiUnauthorizedException.php`, `LOReadFilter.php`)
            *   `[x]` Move `LOReadFilter.php` to `src/Util/PhpSpreadsheet/LOReadFilter.php` and update reference in `ControllingController.php`.
            *   `[x]` Move Exception classes to `src/Exception/` subdirectories (e.g., `src/Exception/Integration/Jira/`). (Estimate: 0.25h)
            *   `[ ]` Delete the `src/Helper` directory once empty. (pending; still contains facades used for BC)
    *   `[ ]` **(Renumbered Task 1.3.3) Use Annotations/Attributes for Routes:**
        *   `[ ]` Ensure `sensio/framework-extra-bundle` is installed (`docker compose run --rm app composer require sensio/framework-extra-bundle`). (Estimate: 0.25h)
        *   `[ ]` Configure annotation routing if not already done (check `config/routes/annotations.yaml`). (Estimate: 0.25h)
        *   `[ ]` Review existing routes defined via annotations in `src/Controller/`. (Estimate: 0.5h)
        *   `[x]` **Migrate routes** defined in `config/legacy_bundle/routing.yml` to annotations/attributes within the relevant Controller classes. (Estimate: 0.5-1h+ depending on complexity)
        *   `[x]` Remove the import for `legacy_bundle/routing.yml` from `config/routes.yaml` once migration is complete. (Estimate: 0.1h)
        *   `[ ]` Prefer PHP 8 Attributes (`#[Route(...)]`) over annotations (`@Route(...)`) if possible within 4.4 constraints (requires PHP 8+). *Note: Full attribute support is better in later Symfony versions.*
    *   `[ ]` **(Renumbered Task 1.3.4) Review Service Configuration:**
        *   `[ ]` Examine `config/services.yaml`. (Estimate: 0.5h)
        *   `[ ]` Ensure autowiring and autoconfiguration are enabled and used effectively (`_defaults`, `App\`). (Estimate: 0.5h)
        *   `[ ]` Remove unnecessary explicit service definitions where autowiring suffices. (Estimate: 0.5h)
    *   `[ ]` **(Renumbered Task 1.3.5) Ensure Strict Types and Type Hints:**
        *   `[ ]` Add `declare(strict_types=1);` to all PHP files. (Estimate: 0.5h - Use automated tooling if possible)
        *   `[ ]` Add parameter and return type hints wherever missing, replacing `@param`/`@return` phpdoc tags. (Estimate: 1h+ - Highly variable, do incrementally)

### 1.4: Static Analysis & Code Standards
*   **Goal:** Ensure code quality meets defined standards.
*   **Tasks:**
    *   `[x]` **Run PHPStan:** Execute `docker compose run --rm app composer analyze` and fix reported issues. (Estimate: 1h+ - Highly variable, depends on initial state)
    *   `[ ]` **Run Psalm:** Execute `docker compose run --rm app composer psalm` and fix reported issues. (Estimate: 1h+ - Highly variable, depends on initial state)
    *   `[ ]` **Run CS Check/Fix:** Execute `docker compose run --rm app composer cs-check` and `docker compose run --rm app composer cs-fix` to ensure PSR-12 compliance. (Estimate: 0.5h)

## Phase 2: Upgrade to Symfony 5.4

*   `[ ]` **Update `composer.json`:** Change Symfony dependencies to `^5.4`.
*   `[ ]` **Run `composer update`:** Resolve dependency conflicts.
*   `[ ]` **Address Compatibility Issues:** Fix errors based on Symfony 5 upgrade guides.
*   `[ ]` **Update Configuration Files:** Adapt `services.yaml`, `routes.yaml`, etc.
*   ...

## Phase 3: Post-Upgrade (Symfony 5.4)

*   `[ ]` **Run Full Test Suite:** Ensure all tests pass.
*   `[ ]` **Manual Testing:** Verify critical application flows.
*   ...

*(Further phases and tasks for 5.4 -> 6.4 and 6.4 -> 7.2 will be detailed later)*
