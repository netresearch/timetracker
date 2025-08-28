# Detailed Task List

This document breaks down the upgrade plan into specific, actionable tasks.

## Completed Phases (historical)

-   `[x]` Symfony 4.4 -> Symfony 5.4
-   `[x]` Symfony 5.4 -> Symfony 6.4

## Phase 1: Prepare for Symfony 7.3 (Working on Symfony 6.4)

### 1.1: Improve Test Coverage

-   **Goal:** Ensure critical parts of the application are covered by tests before refactoring and upgrading.
-   **Tasks:**
    -   `[x]` **Analyze Test Coverage:** Run coverage reports (`docker compose run --rm -e APP_ENV=test app bin/phpunit --coverage-html var/coverage`) and identify key areas lacking tests (Controllers, Services, critical business logic). (Estimate: 0.5h)
    -   `[x]` **Write Unit Tests for Core Services:** Identify core services in `/src/Service` (formerly `/src/Services`) and `/src/Helper` (to be refactored) and add unit tests. (Estimate: Task per service, 0.5-1h each)
        -   `[x]` `App\\Service\\ExportService`
        -   `[x]` `App\\Service\\SubticketSyncService`
        -   `[x]` `App\\Service\\Util\\TimeCalculationService`
        -   `[x]` `App\\Service\\Util\\TicketService`
        -   `[x]` `App\\Service\\Ldap\\LdapClientService`
        -   `[x]` `App\\Service\\LocalizationService`
        -   `[x]` `App\\Service\\SystemClock`
    -   `[x]` **Write Integration Tests:** Add integration tests for interactions between key components (e.g., Service + Repository). (Estimate: Task per interaction, 0.5-1h each)
        -   `[x]` `Tests\\Repository\\EntryRepositoryIntegrationTest`
    -   `[x]` **Write Functional Tests for Critical Routes:** Identify critical user-facing routes and add functional tests to ensure basic functionality. (Estimate: Task per route/feature, 0.5-1h each)
        -   `[x]` Controllers covered: Default, Admin, Controlling, Interpretation, Security

### 1.2: Address Deprecations for Symfony 7.3

-   **Goal:** Remove usage of APIs deprecated in Symfony 6.4 and scheduled for removal in 7.x/7.3.
-   **Tasks:**
    -   `[x]` **Enable deprecation tracking in tests:** Use `symfony/phpunit-bridge` and set `SYMFONY_DEPRECATIONS_HELPER=weak` for local runs. (0.25h)
    -   `[x]` **Run test suite with deprecations enabled:** `docker compose run --rm -e APP_ENV=test -e SYMFONY_DEPRECATIONS_HELPER=weak app bin/phpunit` and export report. (0.5h)
    -   `[x]` **Translations API audit:** Ensure only `Symfony\\Contracts\\Translation\\TranslatorInterface` is used; remove `Symfony\\Component\\Translation\\TranslatorInterface` alias from `config/services.yaml` if unused. (0.25h)
    -   `[x]` **Routing audit:** Confirm all controllers use PHP attributes; remove any leftover annotation/YAML routes. (0.25h)
    -   `[x]` **Twig & PHP audit:** Verify `twig/twig` constraint supports PHP 8.4/Symfony 7.3; plan bump if required. (0.25h)
    -   `[x]` **Fix remaining deprecations:** Triage and resolve notices from the report. (variable)

### 1.3: Finalize Symfony 7.3 Best Practices (historical)

-   **Goal:** Align the codebase fully with Symfony 6.4 conventions before upgrading.
-   **Tasks:**
    -   `[x]` **Analyze Controller Structure:**
        -   `[x]` Review major controllers (legacy `DefaultController`, `ControllingController`) – most endpoints migrated to invokable actions under `App\Controller\Default\*`, `App\Controller\Controlling\ExportAction`. Remaining legacy classes contain only comments/BC wrappers.
        -   `[x]` Identify extractions: Export spreadsheet helpers (`setCellDate`, `setCellHours`) are candidates for a small `ExportSpreadsheetFormatter` service if we want thinner controllers; optional.
        -   `[x]` Documented notes here; no blocking refactors required pre-7.3.
    -   `[x]` **Move classes from `src/Services` to `src/Service`:**
        -   `[x]` Move `src/Services/Export.php` to `src/Service/ExportService.php` (or similar appropriate name). (Estimate: 0.1h)
        -   `[x]` Update namespace in the moved file (`App\Services` -> `App\Service`). (Estimate: 0.1h)
        -   `[x]` Update any explicit references in `services.yaml` or elsewhere. (Estimate: 0.1h)
        -   `[x]` Move `src/Services/SubticketSyncService.php` to `src/Service/SubticketSyncService.php`. (Estimate: 0.1h)
        -   `[x]` Update namespace in the moved file. (Estimate: 0.1h)
        -   `[x]` Update any explicit references. (Estimate: 0.1h)
        -   `[x]` Delete the `src/Services` directory once empty. (Estimate: 0.1h)
        -   `[x]` Clear cache (`docker compose run --rm app bin/console cache:clear`). (Estimate: 0.1h)
    -   `[x]` **Refactor `src/Helper` Classes to Services (state-aware):**
        -   `[x]` JiraOAuthApi: Update factory to return `JiraOAuthApiService` directly; remove `App\\Helper\\JiraOAuthApi`. (0.5-1h)
        -   `[x]` LdapClient: `App\\Service\\Ldap\\LdapClientService` in use; delete unused helper `App\\Helper\\LdapClient`. (0.25h)
        -   `[x]` LocalizationHelper: Not used; delete `src/Helper/LocalizationHelper.php`. (0.25h)
        -   `[x]` LoginHelper: Replace usages (e.g., in `BaseController`) with Security APIs if any remain; then delete helper. (0.5-1h)
        -   `[x]` TicketHelper: Replace static calls in `CrudController` with DI of `App\\Service\\Util\\TicketService`; remove helper after migration. (0.5-1h)
        -   `[x]` TimeHelper: Replace static calls with `App\\Service\\Util\\TimeCalculationService` across controllers/repositories; review entity usage; remove helper after migration. (1-2h)
            -   Status: Controllers and repository use `TimeCalculationService`; entity `Project::toArray()` still instantiates service directly for `minutesToReadable`. Consider injecting formatter via DTO or a static utility; acceptable for now.
        -   `[x]` Cleanup: Delete `src/Helper/` once all helpers are removed. (0.1h)
    -   `[x]` **Routing via PHP Attributes:** Controllers already use attributes; legacy YAML removed. (Done)
    -   `[x]` **Review Service Configuration:**
        -   `[x]` Examine `config/services.yaml`. (Estimate: 0.5h)
        -   `[x]` Ensure autowiring and autoconfiguration are enabled and used effectively (`_defaults`, `App\\`). (Estimate: 0.5h)
        -   `[x]` Remove unnecessary explicit service definitions and legacy aliases (e.g., translator, annotations reader) if unused. (0.5h)
            -   Note: Kept aliases for `TranslatorInterface`, `SessionInterface`, and `LoggerInterface` (in active use). Explicit `session` service retained for prod/dev; no legacy annotations reader present.
    -   `[ ]` **Ensure Strict Types and Type Hints:**
        -   `[x]` Add `declare(strict_types=1);` to all PHP files. (Estimate: 0.5h - Use automated tooling if possible)
        -   `[ ]` Add parameter and return type hints wherever missing, replacing `@param`/`@return` phpdoc tags. (Estimate: 1h+ - Highly variable, do incrementally)

### 1.4: Static Analysis & Code Standards

-   **Goal:** Ensure code quality meets defined standards.
-   **Tasks:**
    -   `[x]` **Run PHPStan:** Execute `docker compose run --rm app composer analyze` and fix reported issues. (Estimate: 1h+ - Highly variable, depends on initial state)
    -   `[x]` **Run Psalm:** Execute `docker compose run --rm app composer psalm` and fix reported issues. (Estimate: 1h+ - Highly variable, depends on initial state)
        -   `[x]` Reduce issues in controllers (admin/crud/default) and integration layer (JiraOAuthApiService)
        -   `[x]` Tidy repository return types and static signatures (remaining)
    -   `[x]` **Run CS Check/Fix:** Execute `docker compose run --rm app composer cs-check` and `docker compose run --rm app composer cs-fix` to ensure PSR-12 compliance. (Estimate: 0.5h)

### 1.5: Request DTOs and Request Mapping

-   **Goal:** Replace ad-hoc request parsing with typed DTOs and Symfony request mapping attributes while preserving existing semantics and tests.
-   **Tasks:**
    -   `[x]` Add `App\Dto\InterpretationFiltersDto` and refactor `InterpretationController` to use it
    -   `[x]` Introduce invokable controller `App\Controller\Interpretation\GetAllEntriesAction` using `#[MapQueryString]`
    -   `[x]` Add `App\Dto\ExportQueryDto` and invokable `App\Controller\Controlling\ExportAction` using `#[MapQueryString]`
    -   `[x]` Add `App\Dto\AdminSyncDto` and invokable `App\Controller\Admin\SyncProjectSubticketsAction` using `#[MapQueryString]`
    -   `[x]` Wire routes to new invokables and deprecate legacy actions incrementally
    -   `[x]` Refactor Admin controller into invokable actions; remove legacy `AdminController`
    -   `[x]` Refactor Tracking actions into invokables (`SaveEntry`, `DeleteEntry`, `BulkEntry`); remove legacy `CrudController`
    -   `[x]` Refactor Settings save into invokable; remove legacy `SettingsController`
    -   `[x]` Refactor Interpretation endpoints into invokables; remove legacy `InterpretationController`
    -   `[x]` Refactor Default endpoints into invokables (index, summaries, data, exports, JIRA callback, scripts)
    -   `[x]` Follow-up: consider `#[MapRequestPayload]` for POST endpoints (project/customer/preset save)

## Phase 2: Upgrade to Symfony 7.3 (Completed)

-   `[x]` **Update `composer.json`:** Change Symfony dependencies to `^7.3`.
-   `[x]` **Doctrine ORM 3 compatibility:** Replaced deprecated DBAL APIs in repositories/tests; mock updates in tests.
-   `[x]` **PHPUnit 12 migration:** phpunit.xml migrated to 12.3 schema; deprecated annotations replaced.
-   `[x]` **Run `composer update`:** Resolve dependency conflicts.
-   `[x]` **Address Compatibility Issues:** Fix errors based on Symfony 7 upgrade guides (e.g., Response::send signature).
-   `[x]` **Update Configuration Files:** Adapt `packages/` updates from recipes, verify test framework config.
-   `[x]` **Adjust Tests where needed:** Ensure compatibility; full suite green.

## Phase 3: Post-Upgrade (Symfony 7.3)

-   `[x]` **Run Full Test Suite:** Ensure all tests pass.
-   `[x]` **Add CI:** GitHub Actions workflow `ci.yml` runs static analysis and tests.
-   `[ ]` **Manual Testing:** Verify critical application flows.
-   ...

_(Historical phases retained above. Current focus: 6.4 -> 7.3.)_
