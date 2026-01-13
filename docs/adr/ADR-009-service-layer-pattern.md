# ADR-001: Service Layer Pattern Implementation

## Status
Accepted

## Context and Problem Statement
The original timetracker application had tightly coupled controllers directly accessing repositories, leading to:
- Business logic scattered across controllers
- Difficult unit testing due to direct repository dependencies
- Code duplication across similar operations
- Limited reusability of business operations
- Violation of Single Responsibility Principle in controllers

Analysis of the codebase reveals controllers were directly injecting and using multiple repositories, creating complex dependency chains and making the system difficult to maintain and test.

## Decision Drivers
- **Separation of Concerns**: Controllers should handle HTTP concerns only
- **Testability**: Business logic should be easily unit testable
- **Code Reuse**: Common operations should be centralized
- **Maintainability**: Changes to business rules should be localized
- **SOLID Principles**: Adherence to Single Responsibility and Dependency Inversion

## Considered Options

### Option 1: Keep Direct Repository Access (Rejected)
**Pros:**
- No additional abstraction layer
- Direct database access for simple operations
- Minimal refactoring required

**Cons:**
- Controllers become bloated with business logic
- Difficult to test business logic independently
- Code duplication across controllers
- Tight coupling to data access layer

### Option 2: Domain Services Pattern (Considered)
**Pros:**
- Domain-driven approach
- Clear business logic encapsulation
- Good testability

**Cons:**
- More complex for current application size
- Requires significant restructuring
- Over-engineering for current needs

### Option 3: Service Layer Pattern (Chosen)
**Pros:**
- Clean separation between controllers and data access
- Improved testability through dependency injection
- Centralized business logic
- Gradual implementation possible
- Aligns with Symfony best practices

**Cons:**
- Additional abstraction layer
- More classes to maintain

## Decision Outcome
We implement a Service Layer pattern with the following structure:

```
App\Service\
├── Entry\
│   └── EntryQueryService.php
├── Security\
│   └── TokenEncryptionService.php
├── Util\
│   ├── TimeCalculationService.php
│   └── LocalizationService.php
└── Integration\
    └── Jira\
        ├── JiraIntegrationService.php
        ├── JiraTicketService.php
        └── JiraWorkLogService.php
```

## Implementation Strategy

### Phase 1: Core Services (Completed)
- **TimeCalculationService**: Centralize duration calculations and time formatting
- **TokenEncryptionService**: Secure token handling for OAuth integrations
- **EntryQueryService**: Complex query operations for time entries

### Phase 2: Integration Services (Completed)
- **JiraIntegrationService**: JIRA API integration and OAuth handling
- **LocalizationService**: Multi-language support and formatting

### Phase 3: Controller Refactoring (In Progress)
- Controllers inject services instead of repositories directly
- Business logic moves from controllers to services
- Controllers focus on HTTP request/response handling

## Consequences

### Positive
- **Improved Testability**: Services can be unit tested independently
- **Code Reuse**: Business operations centralized and reusable
- **Cleaner Controllers**: Controllers focus on HTTP concerns only
- **Better Separation**: Clear boundaries between layers
- **Easier Maintenance**: Business logic changes isolated to services

### Negative
- **Additional Complexity**: More classes and interfaces to manage
- **Learning Curve**: Team needs to understand new architecture
- **Migration Effort**: Existing controllers need gradual refactoring

### Neutral
- **Performance Impact**: Minimal - dependency injection overhead negligible
- **Memory Usage**: Slight increase due to additional objects

## Implementation Guidelines

### Service Creation Rules
1. Services MUST be stateless
2. Services SHOULD have single responsibility
3. Services MUST use dependency injection for repositories
4. Services MUST return typed results (DTOs or Value Objects)
5. Services MUST handle exceptions appropriately

### Controller Integration
```php
class ExampleController extends AbstractController
{
    public function __construct(
        private readonly ExampleService $exampleService
    ) {}

    public function action(Request $request): Response
    {
        $result = $this->exampleService->performOperation($request->get('param'));
        return $this->json($result);
    }
}
```

### Testing Strategy
- Unit tests for each service
- Mock repositories in service tests
- Integration tests for service + repository combinations

## Monitoring and Review
- **Performance Metrics**: Response times and memory usage
- **Code Quality**: PHPStan level 9 compliance maintained
- **Test Coverage**: Aim for 80%+ coverage on services
- **Review Cycle**: Quarterly architecture review

## Related ADRs
- ADR-002: Repository Pattern Refactoring
- ADR-005: Testing Strategy

## References
- [Symfony Service Container Documentation](https://symfony.com/doc/current/service_container.html)
- [Service Layer Pattern - Martin Fowler](https://martinfowler.com/eaaCatalog/serviceLayer.html)
- [PHP Service Layer Best Practices](https://matthiasnoback.nl/2017/04/service-locator-is-an-anti-pattern/)