# TimeTracker Service Layer Documentation

## Overview

The TimeTracker application follows a sophisticated service-oriented architecture with 22 specialized services organized across functional domains. The service layer implements pure business logic separated from controllers and provides a clean, testable interface for core application operations.

### Service Architecture Principles

- **Stateless Design**: All services are stateless and thread-safe
- **Constructor Injection**: Dependency injection pattern with readonly dependencies
- **Final/Readonly Classes**: Immutable service instances prevent state corruption
- **Single Responsibility**: Each service handles one specific domain
- **Type Safety**: Strict typing with PHPStan level 8 compliance

## Service Organization Structure

```
src/Service/
├── Cache/              # Caching and performance services (1)
├── Entry/              # Time entry business logic (1)
├── Integration/        # External system integrations
│   └── Jira/          # JIRA API integration services (7)
├── Ldap/              # LDAP/AD authentication services (2)
├── Response/          # HTTP response creation (2)
├── Security/          # Security and encryption services (1)
├── TypeSafety/        # Type-safe operations (1)
├── Util/              # Utility and helper services (3)
├── Core Services       # Application core services (4)
└── Domain Services     # Business domain services (1)
```

**Total Services**: 22 services across 8 functional categories

## Core Services

### SystemClock - Time Management Service
**File**: `src/Service/SystemClock.php`
**Interface**: `src/Service/ClockInterface.php`

Provides testable time operations with dependency injection for current time and date boundaries.

```php
class SystemClock implements ClockInterface
{
    public function now(): DateTimeImmutable;
    public function today(): DateTimeImmutable; // midnight start
}
```

**Usage**: Time calculations, date filtering, audit timestamps
**Pattern**: Interface-based implementation for test mocking

### ExportService - Data Export Operations
**File**: `src/Service/ExportService.php`

Handles time entry data export with filtering, JIRA URL enrichment, and memory-efficient batching.

```php
// Core export with filtering
public function getEntries(User $currentUser, ?array $arSort, string $strStart,
                          string $strEnd, ?array $arProjects, ?array $arUsers): array

// Memory-efficient batch export
public function exportEntriesBatched(int $userId, int $year, int $month,
                                   ?int $projectId, ?int $customerId,
                                   ?array $arSort, int $batchSize = 1000): Generator

// JIRA ticket enrichment
public function enrichEntriesWithTicketInformation(int $userId, array $entries,
                                                  bool $includeBillable,
                                                  bool $includeTicketTitle,
                                                  bool $searchTickets): array
```

**Features**:
- Filtered entry retrieval with user-specific security
- JIRA ticket URL generation and worklog linking
- Batch processing for memory efficiency
- Ticket metadata enrichment from JIRA API

### ResponseFactory - Standardized API Responses
**File**: `src/Service/Response/ResponseFactory.php`

Centralizes response creation logic with consistent error handling and i18n support.

```php
// Success responses
public function success(array $data = [], ?string $alert = null): JsonResponse
public function paginated(array $items, int $page, int $totalPages,
                         int $totalItems, int $itemsPerPage): JsonResponse

// Error responses with proper HTTP status codes
public function error(string $message, int $statusCode = 400, ?string $redirectUrl = null): Error
public function validationError(array $errors): Error  // 422 Unprocessable Entity
public function jiraApiError(Exception $exception, string $fallbackMessage): Error
```

**Error Types**: 400 Bad Request, 401 Unauthorized, 403 Forbidden, 404 Not Found, 409 Conflict, 422 Validation, 500 Server Error, 502 Bad Gateway

### LocalizationService - Internationalization
**File**: `src/Service/Util/LocalizationService.php`

Simple but effective i18n support for multi-language deployment.

```php
public function getAvailableLocales(): array; // de, en, es, fr, ru
public function normalizeLocale(string $locale): string; // Validates and defaults to 'en'
```

## Integration Services

### JIRA Integration Suite
**Directory**: `src/Service/Integration/Jira/` (7 services)

Complete JIRA API integration with OAuth authentication, worklog synchronization, and ticket management.

#### JiraIntegrationService - Main Integration Controller
**File**: `JiraIntegrationService.php`

High-level orchestration service for JIRA operations.

```php
// Worklog operations
public function saveWorklog(Entry $entry): bool
public function deleteWorklog(Entry $entry): bool
public function batchSyncWorkLogs(array $entries): array

// Sync management
public function getEntriesNeedingSync(?User $user, ?DateTime $since): array
public function validateJiraConnection(TicketSystem $ticketSystem, User $user): bool
```

#### JiraAuthenticationService - OAuth Token Management
**File**: `JiraAuthenticationService.php`

Handles JIRA OAuth 1.0a authentication flow with secure token storage.

```php
// OAuth flow
public function fetchOAuthRequestToken(JiraHttpClientService $clientService): string
public function fetchOAuthAccessToken(JiraHttpClientService $clientService,
                                    string $oAuthRequestToken, string $oAuthVerifier): void

// Token management with encryption
public function getTokens(User $user, TicketSystem $ticketSystem): array
public function deleteTokens(User $user, TicketSystem $ticketSystem): void
```

**Security Features**:
- Encrypted token storage using `TokenEncryptionService`
- Legacy token compatibility for migration
- OAuth problem detection and error handling

#### Other JIRA Services
- **JiraHttpClientService**: HTTP client configuration and request handling
- **JiraOAuthApiService**: Core JIRA API operations (search, create, update tickets)
- **JiraOAuthApiFactory**: Service factory for API client creation
- **JiraWorkLogService**: Worklog-specific operations
- **JiraTicketService**: Ticket metadata and search operations

### LDAP Authentication Services
**Directory**: `src/Service/Ldap/` (2 services)

#### ModernLdapService - LDAP/Active Directory Integration
**File**: `ModernLdapService.php`

Modern replacement for legacy LDAP service with improved security and type safety.

```php
// Authentication
public function authenticate(string $username, string $password): bool

// User management
public function findUser(string $username): ?array
public function searchUsers(array $criteria, int $limit = 100): array
public function getUserGroups(string $username): array

// Connection testing
public function testConnection(): bool
```

**Security Features**:
- LDAP injection prevention through input sanitization
- Service account binding for searches
- SSL/TLS support configuration
- Connection cleanup in finally blocks

**Configuration**: Uses ParameterBag for LDAP server configuration (host, port, baseDN, credentials)

## Utility Services

### TimeCalculationService - Time Format Processing
**File**: `src/Service/Util/TimeCalculationService.php`

Handles human-readable time format parsing and conversion.

```php
// Constants
public const int DAYS_PER_WEEK = 5;
public const int HOURS_PER_DAY = 8;

// Format parsing: "2h 30m", "1d 4h", "1w 2d"
public function readableToMinutes(string $readable): int|float
public function readableToFullMinutes(string $readable): int

// Format generation
public function minutesToReadable(int|float $minutes, bool $useWeeks = true): string
public function formatDuration(int|float $duration, bool $inDays = false): string
public function formatQuota(int|float $amount, int|float $sum): string
```

**Supported Formats**: weeks (w), days (d), hours (h), minutes (m)
**Pattern**: Regex-based parsing with flexible input handling

### TicketService - Ticket Format Validation
**File**: `src/Service/Util/TicketService.php`

Validates and processes JIRA ticket identifiers.

```php
public const string TICKET_REGEXP = '/^([A-Z]+[0-9A-Z]*)-([0-9]+)$/i';

public function checkFormat(string $ticket): bool          // Validates PROJ-123 format
public function getPrefix(string $ticket): ?string        // Extracts "PROJ" from "PROJ-123"
public function extractJiraId(string $ticket): string     // Alias for getPrefix()
```

## Specialized Services

### Security Services

#### TokenEncryptionService - Secure Token Storage
**File**: `src/Service/Security/TokenEncryptionService.php`

Provides AES-256-GCM authenticated encryption for sensitive tokens (OAuth, API keys).

```php
public function encryptToken(string $token): string        // Base64 encoded encrypted token
public function decryptToken(string $encryptedToken): string
public function rotateToken(string $encryptedToken): string // Re-encrypt with new IV
```

**Security Features**:
- AES-256-GCM authenticated encryption
- Random IV generation per encryption
- Tamper detection through authentication tags
- Key derivation from application secret

### Cache Services

#### QueryCacheService - Application Caching
**File**: `src/Service/Cache/QueryCacheService.php`

PSR-6 compatible caching service with tagging and invalidation support.

```php
// Cache operations
public function remember(string $key, callable $callback, int $ttl = 300): mixed
public function get(string $key): mixed
public function set(string $key, mixed $value, int $ttl = 300): void

// Tag-based invalidation
public function tag(string $key, string ...$tags): void
public function invalidateTag(string $tag): void
public function invalidateEntity(string $entityClass, int $entityId): void

// Performance features
public function warmUp(array $callbacks): void
public function getStats(): array
```

**Features**:
- Template-based type safety with generics
- Group invalidation through tagging
- Entity-based cache invalidation
- Cache statistics and monitoring

### Data Access Services

#### EntryQueryService - Entry Data Access
**File**: `src/Service/Entry/EntryQueryService.php`

Type-safe entry querying with pagination support.

```php
public function findPaginatedEntries(InterpretationFiltersDto $filters): PaginatedEntryCollection
```

**Features**:
- DTO-based filter validation
- Type-safe pagination through value objects
- Legacy field alias support (`project_id` → `project`)
- Input sanitization and validation

### Response Services

#### PaginationLinkService - REST API Pagination
**File**: `src/Service/Response/PaginationLinkService.php`

Generates HATEOAS-compliant pagination links for REST APIs.

```php
public function generateLinks(Request $request, PaginatedEntryCollection $collection): array
```

**Generated Links**: `self`, `next`, `prev`, `last` with query parameter preservation

### Type Safety Services

#### ArrayTypeHelper - Safe Array Operations
**File**: `src/Service/TypeSafety/ArrayTypeHelper.php`

Utility class for type-safe array value extraction.

```php
public static function getInt(array $array, string $key, ?int $default = null): ?int
public static function getString(array $array, string $key, ?string $default = null): ?string
public static function hasValue(array $array, string $key): bool
```

**Safety Features**: Null-safe operations, type casting, default value handling

### Business Domain Services

#### SubticketSyncService - JIRA Subticket Management
**File**: `src/Service/SubticketSyncService.php`

Synchronizes JIRA subticket data for project management.

```php
public function syncProjectSubtickets(int|Project $projectOrProjectId): array
```

**Features**:
- Project lead authentication for JIRA access
- Hierarchical ticket relationship management
- Automatic subticket discovery and storage

## Service Patterns and Best Practices

### Dependency Injection Pattern
All services use constructor injection with readonly properties:

```php
public function __construct(
    private readonly ManagerRegistry $managerRegistry,
    private readonly SomeService $someService,
    private readonly ?LoggerInterface $logger = null,
) {}
```

### Error Handling Strategy
Services implement consistent error handling:

- **Domain Exceptions**: Custom exceptions for business logic errors
- **Integration Exceptions**: Specific exceptions for external service failures
- **Validation Exceptions**: Input validation with detailed error messages
- **Logging Integration**: Structured logging with contextual information

### Security Patterns
- **Input Sanitization**: All external input is validated and sanitized
- **Token Encryption**: Sensitive data encrypted before storage
- **Authentication Checks**: User authorization verified before operations
- **LDAP Injection Prevention**: Special character escaping in LDAP queries

### Performance Patterns
- **Batch Processing**: Memory-efficient processing for large datasets
- **Lazy Loading**: Services instantiated only when needed
- **Caching Support**: Integrated caching for expensive operations
- **Connection Pooling**: Efficient resource management for external services

## Dependencies and Interactions

### Service Dependencies
```
Controllers → Services → Repositories → Entities
          ↘ External APIs (JIRA, LDAP)
          ↘ Framework Services (Translation, Router, Logger)
```

### Integration with Controllers
Services are injected into controllers through Symfony's dependency injection container. Controllers handle HTTP concerns while services handle business logic.

### Repository Usage
Services interact with Doctrine repositories for data persistence:

```php
/** @var EntryRepository $entryRepo */
$entryRepo = $this->managerRegistry->getRepository(Entry::class);
$entries = $entryRepo->findByUser($user);
```

### Event System Integration
Some services trigger domain events for loose coupling:

- Entry creation/update events for JIRA synchronization
- User authentication events for audit logging
- Cache invalidation events for data consistency

## Testing Considerations

### Service Testing Strategy
- **Unit Tests**: Mock dependencies for isolated testing
- **Integration Tests**: Test service interactions with real dependencies
- **Contract Tests**: Verify interface implementations
- **Performance Tests**: Validate caching and batch processing

### Mock-Friendly Design
Services use interfaces where possible to enable easy mocking:

- `ClockInterface` for time-dependent operations
- `LoggerInterface` for logging operations
- Repository interfaces for data access

## Configuration and Environment

### Service Configuration
Services are configured through:

- **Environment Variables**: Database connections, API keys, LDAP settings
- **Symfony Parameters**: Application-specific configuration
- **Service Configuration**: Dependency injection container setup

### Environment-Specific Behavior
- **Development**: Enhanced logging, debug information
- **Production**: Optimized caching, error handling
- **Testing**: Mock services, in-memory databases

---

**Last Updated**: September 2024
**Documentation Coverage**: 22/22 services documented
**Architecture Level**: Service Layer (Business Logic)

For controller documentation, see [CONTROLLER_INDEX.md](CONTROLLER_INDEX.md).
For API documentation, see [API_DOCUMENTATION.md](API_DOCUMENTATION.md).