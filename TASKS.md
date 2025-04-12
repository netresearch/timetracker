# Detailed Task List

This document breaks down the upgrade plan into specific, actionable tasks.

## Phase 1: Prepare for Symfony 5.4 (Working on Symfony 4.4)

### 1.0: Refactor Controllers (HIGH PRIORITY)
*   **Goal:** Improve adherence to the Single Responsibility Principle by breaking down large controllers and extracting business logic into dedicated services.
*   **Tasks:**
    *   `[x]` **Analyze Controllers:** Review existing controllers to identify responsibilities that should be extracted. (Estimate: 2h)
        *   `[x]` Create a mapping of controller methods to their responsibilities.
        *   `[x]` Identify business logic that should be moved to services.
        *   `[x]` Document dependencies between controller methods and external components.
    *   `[x]` **Create Service Layer for TimeEntry Operations:** Extract time entry operations from CrudController. (Estimate: 3h)
        *   `[x]` Create `src/Service/TimeEntry/TimeEntryService.php` for CRUD operations.
        *   `[x]` Create `src/Service/TimeEntry/ClassCalculationService.php` for class calculations.
        *   `[ ]` Write unit tests for each new service.
    *   `[x]` **Create Service Layer for Jira Integration:** Extract Jira-related operations. (Estimate: 3h)
        *   `[x]` Create `src/Service/Integration/Jira/WorklogService.php` for worklog operations.
        *   `[ ]` Refactor Jira API dependency injection.
        *   `[ ]` Write unit tests for Jira integration services.
    *   `[x]` **Create Service Layer for Ticket Validation:** Extract ticket validation logic. (Estimate: 2h)
        *   `[x]` Create `src/Service/Ticket/TicketValidationService.php`.
        *   `[ ]` Write unit tests for ticket validation.
    *   `[x]` **Refactor CrudController:** Split by responsibility. (Estimate: 3h)
        *   `[x]` Create `TimeEntryController` for time entry management.
        *   `[x]` Update routes and dependencies.
        *   `[x]` Implement `deleteAction` and `saveAction` in the TimeEntryController.
        *   `[x]` Implement `bulkentryAction` in the TimeEntryController.
        *   `[x]` Remove duplicate code from CrudController.
        *   `[ ]` Write functional tests for the new controller.
    *   `[x]` **Refactor AdminController:** Split by entity domain. (Estimate: 5h)
        *   `[x]` Create entity-specific controllers (ProjectController, CustomerController, etc).
            *   `[x]` Create ProjectController and ProjectService
            *   `[x]` Create CustomerController and CustomerService
            *   `[x]` Create UserController and UserService
            *   `[x]` Create TeamController and TeamService
            *   `[x]` Create TicketSystemController and TicketSystemService
            *   `[x]` Create ActivityController and ActivityService
            *   `[x]` Create PresetController and PresetService
            *   `[x]` Create ContractController and ContractService
            *   `[ ]` Create JiraSyncController and JiraSyncService
        *   `[x]` Extract business logic to services.
        *   `[x]` Update routes in `config/routes.yaml` or via annotations.
        *   `[x]` Remove duplicate code from AdminController.
        *   `[ ]` Update templates to point to new controller actions.
        *   `[x]` Write functional tests for each new controller.
            *   `[x]` Create ContractsControllerTest
            *   `[x]` Create PresetsControllerTest
            *   `[ ]` Create remaining controller tests
                *   `[x]` Create ProjectControllerTest
                *   `[ ]` Create CustomerControllerTest
                *   `[ ]` Create UserControllerTest
                *   `[ ]` Create TeamControllerTest
                *   `[ ]` Create TicketSystemControllerTest
                *   `[ ]` Create ActivityControllerTest
                *   `[ ]` Create JiraSyncControllerTest
    *   `[x]` **Update Routing Configuration:** Ensure all routes point to the new controllers. (Estimate: 2h)
        *   `[x]` Update route annotations or YAML configuration.
        *   `[ ]` Test each route to ensure proper mapping.
        *   `[ ]` Create route name aliases if needed for backward compatibility.
    *   `[ ]` **Update Frontend Integration:** Ensure JS/Ajax calls work with new endpoints. (Estimate: 3h)
        *   `[ ]` Update API endpoints in JavaScript files.
        *   `[ ]` Test all form submissions and AJAX requests.

### 1.1: Improve Test Coverage
*   **Goal:** Ensure critical parts of the application are covered by tests before refactoring and upgrading.
*   **Tasks:**
    *   `[ ]` **Analyze Test Coverage:** Run coverage reports (`docker compose run --rm -e APP_ENV=test app bin/phpunit --coverage-html var/coverage`) and identify key areas lacking tests (Controllers, Services, critical business logic). (Estimate: 0.5h)
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
    *   `[ ]` **(Updated Task 1.3.1) Move classes from `src/Services` to `src/Service`:**
        *   `[ ]` Move `src/Services/Export.php` to `src/Service/ExportService.php` (or similar appropriate name). (Estimate: 0.1h)
        *   `[ ]` Update namespace in the moved file (`App\Services` -> `App\Service`). (Estimate: 0.1h)
        *   `[ ]` Update any explicit references in `services.yaml` or elsewhere. (Estimate: 0.1h)
        *   `[ ]` Move `src/Services/SubticketSyncService.php` to `src/Service/SubticketSyncService.php`. (Estimate: 0.1h)
        *   `[ ]` Update namespace in the moved file. (Estimate: 0.1h)
        *   `[ ]` Update any explicit references. (Estimate: 0.1h)
        *   `[ ]` Delete the `src/Services` directory once empty. (Estimate: 0.1h)
        *   `[ ]` Clear cache (`docker compose run --rm app bin/console cache:clear`). (Estimate: 0.1h)
    *   `[ ]` **(Updated Task 1.3.2) Refactor Helper Classes to Services:**
        *   `[ ]` **Refactor `JiraOAuthApi.php`:**
            *   `[ ]` Move `src/Helper/JiraOAuthApi.php` to `src/Service/Integration/Jira/JiraOAuthApiService.php` (or similar). (Estimate: 0.1h)
            *   `[ ]` Update namespace. (Estimate: 0.1h)
            *   `[ ]` Ensure it's registered as a service (autowiring likely). (Estimate: 0.1h)
            *   `[ ]` Update usages to use Dependency Injection. (Estimate: 0.5-1h)
        *   `[ ]` **Refactor `LdapClient.php`:**
            *   `[ ]` Move `src/Helper/LdapClient.php` to `src/Service/Ldap/LdapClientService.php` (or similar). (Estimate: 0.1h)
            *   `[ ]` Update namespace. (Estimate: 0.1h)
            *   `[ ]` Ensure registered as a service. (Estimate: 0.1h)
            *   `[ ]` Update usages (likely in Security component) via DI. (Estimate: 0.5h)
        *   `[ ]` **Refactor `LocalizationHelper.php`:**
            *   `[ ]` Move `src/Helper/LocalizationHelper.php` to `src/Service/Util/LocalizationService.php` (or similar). (Estimate: 0.1h)
            *   `[ ]` Update namespace. (Estimate: 0.1h)
            *   `[ ]` Ensure registered as a service. (Estimate: 0.1h)
            *   `[ ]` Update usages via DI. (Estimate: 0.25h)
        *   `[ ]` **Refactor `TicketHelper.php`:**
            *   `[ ]` Move `src/Helper/TicketHelper.php` to `src/Service/Util/TicketService.php` (or similar). (Estimate: 0.1h)
            *   `[ ]` Update namespace. (Estimate: 0.1h)
            *   `[ ]` Ensure registered as a service. (Estimate: 0.1h)
            *   `[ ]` Update usages via DI. (Estimate: 0.25h)
        *   `[ ]` **Refactor `TimeHelper.php`:**
            *   `[ ]` Move `src/Helper/TimeHelper.php` to `src/Service/Util/TimeCalculationService.php` (or similar). (Estimate: 0.1h)
            *   `[ ]` Update namespace. (Estimate: 0.1h)
            *   `[ ]` Ensure registered as a service. (Estimate: 0.1h)
            *   `[ ]` Update usages via DI. (Estimate: 0.5h)
        *   `[ ]` **Handle remaining Helper files:** (`JiraApiException.php`, `JiraApiInvalidResourceException.php`, `JiraApiUnauthorizedException.php`, `LOReadFilter.php`)
            *   `[x]` Move `LOReadFilter.php` to `src/Util/PhpSpreadsheet/LOReadFilter.php` and update reference in `ControllingController.php`.
            *   `[x]` Move Exception classes to `src/Exception/` subdirectories (e.g., `src/Exception/Integration/Jira/`). (Estimate: 0.25h)
            *   `[ ]` Delete the `src/Helper` directory once empty. (Estimate: 0.1h)
    *   `[ ]` **(Renumbered Task 1.3.3) Use Annotations/Attributes for Routes:**
        *   `[ ]` Ensure `sensio/framework-extra-bundle` is installed (`docker compose run --rm app composer require sensio/framework-extra-bundle`). (Estimate: 0.25h)
        *   `[ ]` Configure annotation routing if not already done (check `config/routes/annotations.yaml`). (Estimate: 0.25h)
        *   `[ ]` Review existing routes defined via annotations in `src/Controller/`. (Estimate: 0.5h)
        *   `[ ]` **Migrate routes** defined in `config/legacy_bundle/routing.yml` to annotations/attributes within the relevant Controller classes. (Estimate: 0.5-1h+ depending on complexity)
        *   `[ ]` Remove the import for `legacy_bundle/routing.yml` from `config/routes.yaml` once migration is complete. (Estimate: 0.1h)
        *   `[ ]` Prefer PHP 8 Attributes (`#[Route(...)]`) over annotations (`@Route(...)`) if possible within 4.4 constraints (requires PHP 8+). *Note: Full attribute support is better in later Symfony versions.*

### 1.4: Static Analysis & Code Standards
*   **Goal:** Ensure code quality meets defined standards.
*   **Tasks:**
    *   `[ ]` **Run PHPStan:** Execute `docker compose run --rm app composer analyze` and fix reported issues. (Estimate: 1h+ - Highly variable, depends on initial state)
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
