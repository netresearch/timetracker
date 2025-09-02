# Code Style and Conventions

## PHP Coding Standards

### General Rules
- **PHP Version**: 8.4 minimum, use modern PHP features
- **Indentation**: 4 spaces (no tabs)
- **Line endings**: LF (Unix-style)
- **Character encoding**: UTF-8
- **PSR-4 autoloading**: `App\` namespace maps to `src/`
- **Strict types**: Always declare `declare(strict_types=1);`

### Symfony Conventions
- **Attributes over Annotations**: Use PHP 8 attributes (#[Route], #[Assert], etc.)
- **Constructor property promotion**: Use PHP 8 constructor promotion
- **Service injection**: Constructor injection preferred, avoid container injection
- **Final by default**: Mark classes as `final` unless designed for inheritance

### Naming Conventions
- **Classes**: PascalCase (e.g., `SaveEntryAction`, `ValidationService`)
- **Methods/Functions**: camelCase (e.g., `validateTicket()`, `getUserData()`)
- **Properties**: camelCase for public/protected, camelCase for private
- **Constants**: UPPER_SNAKE_CASE
- **Database fields**: snake_case
- **Routes**: kebab-case URLs, snake_case route names

### Type Hints & Declarations
```php
declare(strict_types=1);

namespace App\Service;

final class ExampleService
{
    public function __construct(
        private readonly DependencyService $dependency,
    ) {
    }
    
    public function processData(string $input, ?int $limit = null): array
    {
        // Implementation
    }
}
```

### DocBlocks
- Required for complex logic or public APIs
- Not required if types are fully specified
- Use PHPDoc for array shapes when needed
```php
/**
 * @return array<string, mixed>
 */
public function toArray(): array
```

### Directory Structure
```
src/
├── Controller/     # HTTP controllers (Actions)
├── Entity/         # Doctrine entities
├── Repository/     # Data access layer
├── Service/        # Business logic services
├── Dto/           # Data Transfer Objects
├── Event/         # Event classes
├── EventSubscriber/# Event listeners
├── Security/      # Security-related code
├── Util/          # Utility classes
└── Exception/     # Custom exceptions
```

### Controller Pattern
- Single Action Controllers (one `__invoke` method)
- Suffix with `Action` (e.g., `SaveEntryAction`)
- Group by feature in subdirectories
- Return Response objects

### DTO Pattern
- Use DTOs for request/response data
- Integrate with Symfony Validator constraints
- Use `#[MapRequestPayload]` for automatic mapping
- Example:
```php
#[Map(acceptor: 'request.body')]
final class EntrySaveDto
{
    #[Assert\NotBlank]
    #[Map(transform: 'strtoupper')]
    public string $ticket = '';
}
```

### Service Layer
- Services should be stateless
- Mark as `final` and use `readonly` properties
- Use dependency injection
- Single responsibility principle

### Testing Conventions
- Test files mirror source structure in `tests/`
- Unit tests separate from controller tests
- Use descriptive test method names
- Follow AAA pattern (Arrange, Act, Assert)

### Error Handling
- Use specific exception types
- Validate input with DTOs and ValidationService
- Return appropriate HTTP status codes
- Log errors appropriately

### Git Commit Messages
- Conventional commits format preferred
- Types: feat, fix, docs, style, refactor, test, chore
- Example: `feat: add validation to entry saving`

## JavaScript/Frontend
- 2 spaces for JSON files
- Use npm with `--legacy-peer-deps` flag
- Webpack for bundling

## Quality Gates
Before committing code, ensure:
1. PHPStan passes at level 8
2. Psalm analysis passes
3. PHPCS standards are met
4. Tests pass
5. Twig templates are valid