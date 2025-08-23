# Detailed Task List

This document breaks down the upgrade plan into specific, actionable tasks.

## Completed Phases (historical)

*   `[x]` Symfony 4.4 -> Symfony 5.4
*   `[x]` Symfony 5.4 -> Symfony 6.4

## Phase 1: Prepare for Symfony 7.3 (Working on Symfony 6.4)

### 1.1: Improve Test Coverage
*   **Goal:** Ensure critical parts of the application are covered by tests before refactoring and upgrading.
*   **Tasks:**
    *   `[x]` **Analyze Test Coverage:** Run coverage reports (`docker compose run --rm -e APP_ENV=test app bin/phpunit --coverage-html var/coverage`) and identify key areas lacking tests (Controllers, Services, critical business logic). (Estimate: 0.5h)
    *   `[ ]` **Write Unit Tests for Core Services:** Identify core services in `/src/Service` (formerly `/src/Services`) and `/src/Helper` (to be refactored) and add unit tests. (Estimate: Task per service, 0.5-1h each)
        *   `[ ]` Task: Test Service A
        *   `[ ]` Task: Test Service B
        *   `[ ]` ... (add specific services as identified)
        *   `[x]` Task: Test `TicketService` (format/prefix)
    *   `[ ]` **Write Integration Tests:** Add integration tests for interactions between key components (e.g., Service + Repository). (Estimate: Task per interaction, 0.5-1h each)
    *   `[ ]` **Write Functional Tests for Critical Routes:** Identify critical user-facing routes and add functional tests to ensure basic functionality. (Estimate: Task per route/feature, 0.5-1h each)
        *   `[ ]` Task: Test Route X
        *   `[ ]` Task: Test Feature Y
        *   `[ ]` ... (add specific routes/features as identified)

### 1.2: Address Deprecations for Symfony 7.3
*   **Goal:** Remove usage of APIs deprecated in Symfony 6.4 and scheduled for removal in 7.x/7.3.
*   **Tasks:**
    *   `[ ]` **Enable deprecation tracking in tests:** Use `symfony/phpunit-bridge` and set `SYMFONY_DEPRECATIONS_HELPER=weak` for local runs. (0.25h)
    *   `[ ]` **Run test suite with deprecations enabled:** `docker compose run --rm -e APP_ENV=test -e SYMFONY_DEPRECATIONS_HELPER=weak app bin/phpunit` and export report. (0.5h)
    *   `[ ]` **Translations API audit:** Ensure only `Symfony\\Contracts\\Translation\\TranslatorInterface` is used; remove `Symfony\\Component\\Translation\\TranslatorInterface` alias from `config/services.yaml` if unused. (0.25h)
    *   `[ ]` **Routing audit:** Confirm all controllers use PHP attributes; remove any leftover annotation/YAML routes. (0.25h)
    *   `[ ]` **Twig & PHP audit:** Verify `twig/twig` constraint supports PHP 8.4/Symfony 7.3; plan bump if required. (0.25h)
    *   `[ ]` **Fix remaining deprecations:** Triage and resolve notices from the report. (variable)

### 1.3: Finalize Symfony 6.4 Best Practices
*   **Goal:** Align the codebase fully with Symfony 6.4 conventions before upgrading.
*   **Tasks:**
    *   `[ ]` **Analyze Controller Structure:**
        *   `[ ]` Review major controllers (e.g., `EntryController`, `AdminController`, potentially others) for size and responsibilities. (Estimate: 1h)
        *   `[ ]` Identify methods or logic within controllers that could be extracted into dedicated services (e.g., complex data manipulation, external API calls, business rules). (Estimate: 1h)
        *   `[ ]` Document potential refactoring opportunities (create sub-tasks below or separate issues). (Estimate: 0.5h)
    *   `[x]` **Move classes from `src/Services` to `src/Service`:**
        *   `[x]` Move `src/Services/Export.php` to `src/Service/ExportService.php` (or similar appropriate name). (Estimate: 0.1h)
        *   `[x]` Update namespace in the moved file (`App\Services` -> `App\Service`). (Estimate: 0.1h)
        *   `[x]` Update any explicit references in `services.yaml` or elsewhere. (Estimate: 0.1h)
        *   `[x]` Move `src/Services/SubticketSyncService.php` to `src/Service/SubticketSyncService.php`. (Estimate: 0.1h)
        *   `[x]` Update namespace in the moved file. (Estimate: 0.1h)
        *   `[x]` Update any explicit references. (Estimate: 0.1h)
        *   `[x]` Delete the `src/Services` directory once empty. (Estimate: 0.1h)
        *   `[ ]` Clear cache (`docker compose run --rm app bin/console cache:clear`). (Estimate: 0.1h)
    *   `[ ]` **Refactor `src/Helper` Classes to Services (state-aware):**
        *   `[ ]` JiraOAuthApi: Update factory to return `JiraOAuthApiService` directly; remove `App\\Helper\\JiraOAuthApi`. (0.5-1h)
        *   `[x]` LdapClient: `App\\Service\\Ldap\\LdapClientService` in use; delete unused helper `App\\Helper\\LdapClient`. (0.25h)
        *   `[ ]` LocalizationHelper: Not used; delete `src/Helper/LocalizationHelper.php`. (0.25h)
        *   `[ ]` LoginHelper: Replace usages (e.g., in `BaseController`) with Security APIs if any remain; then delete helper. (0.5-1h)
        *   `[ ]` TicketHelper: Replace static calls in `CrudController` with DI of `App\\Service\\Util\\TicketService`; remove helper after migration. (0.5-1h)
        *   `[ ]` TimeHelper: Replace static calls with `App\\Service\\Util\\TimeCalculationService` across controllers/repositories; review entity usage; remove helper after migration. (1-2h)
        *   `[ ]` Cleanup: Delete `src/Helper/` once all helpers are removed. (0.1h)
    *   `[x]` **Routing via PHP Attributes:** Controllers already use attributes; legacy YAML removed. (Done)
    *   `[ ]` **Review Service Configuration:**
        *   `[ ]` Examine `config/services.yaml`. (Estimate: 0.5h)
        *   `[ ]` Ensure autowiring and autoconfiguration are enabled and used effectively (`_defaults`, `App\`). (Estimate: 0.5h)
        *   `[ ]` Remove unnecessary explicit service definitions and legacy aliases (e.g., translator, annotations reader) if unused. (0.5h)
    *   `[ ]` **Ensure Strict Types and Type Hints:**
        *   `[ ]` Add `declare(strict_types=1);` to all PHP files. (Estimate: 0.5h - Use automated tooling if possible)
        *   `[ ]` Add parameter and return type hints wherever missing, replacing `@param`/`@return` phpdoc tags. (Estimate: 1h+ - Highly variable, do incrementally)

### 1.4: Static Analysis & Code Standards
*   **Goal:** Ensure code quality meets defined standards.
*   **Tasks:**
    *   `[x]` **Run PHPStan:** Execute `docker compose run --rm app composer analyze` and fix reported issues. (Estimate: 1h+ - Highly variable, depends on initial state)
    *   `[ ]` **Run Psalm:** Execute `docker compose run --rm app composer psalm` and fix reported issues. (Estimate: 1h+ - Highly variable, depends on initial state)
        *   `[x]` Reduce issues in controllers (admin/crud/default) and integration layer (JiraOAuthApiService)
        *   `[ ]` Tidy repository return types and static signatures (remaining)
    *   `[x]` **Run CS Check/Fix:** Execute `docker compose run --rm app composer cs-check` and `docker compose run --rm app composer cs-fix` to ensure PSR-12 compliance. (Estimate: 0.5h)

## Phase 2: Upgrade to Symfony 7.3

*   `[ ]` **Update `composer.json`:** Change Symfony dependencies to `^7.3`.
*   `[ ]` **Run `composer update`:** Resolve dependency conflicts.
*   `[ ]` **Address Compatibility Issues:** Fix errors based on Symfony 7 upgrade guides.
*   `[ ]` **Update Configuration Files:** Adapt `services.yaml`, `routes.yaml`, etc.
*   `[ ]` **Adjust Tests where needed:** Ensure compatibility with updated kernel and testing tools.

## Phase 3: Post-Upgrade (Symfony 7.3)

*   `[ ]` **Run Full Test Suite:** Ensure all tests pass.
*   `[ ]` **Manual Testing:** Verify critical application flows.
*   ...

*(Historical phases retained above. Current focus: 6.4 -> 7.3.)*
