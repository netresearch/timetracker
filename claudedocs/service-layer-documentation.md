# TimeTracker Service Layer Documentation

## Overview

The TimeTracker application employs a sophisticated service layer architecture that implements comprehensive business logic for time tracking, JIRA integration, user management, and data processing. The service layer is organized into distinct functional domains with clear separation of concerns and dependency injection patterns.

## Service Architecture

### Core Service Categories

1. **Business Logic Services**: Handle core time tracking operations
2. **Integration Services**: Manage external system integrations (JIRA, LDAP)
3. **Utility Services**: Provide common functionality and calculations
4. **Security Services**: Handle authentication, encryption, and authorization
5. **Response Services**: Standardize API responses and error handling
6. **Cache Services**: Optimize performance through intelligent caching

## Service Dependency Map

```
Entry Processing Flow:
EntryQueryService → EntryRepository → Database
        ↓
TimeCalculationService → Core time algorithms
        ↓
JiraIntegrationService → JiraWorkLogService → JiraHttpClientService
        ↓
ExportService → Data transformation and JIRA enrichment
```

```
Authentication Flow:
ModernLdapService → LDAP Directory
        ↓
JiraAuthenticationService → TokenEncryptionService → UserTicketsystem
        ↓
JiraOAuthApiService → OAuth token management
```

## Core Business Services

### 1. TimeCalculationService

**Purpose**: Centralized time calculation algorithms and duration formatting

**Key Algorithms**:
- **Human-readable time parsing**: Converts strings like "2h 30m", "1d 4h" to minutes
- **Duration formatting**: Converts minutes to human-readable format (HH:MM or days)
- **Worktime standards**: 5-day week, 8-hour day constants

**Business Rules**:
```php
// Time unit conversions
'w' => 5 * 8 * 60 minutes (2400 minutes)
'd' => 8 * 60 minutes (480 minutes)  
'h' => 60 minutes
'm' => 1 minute

// Format patterns: "1w 2d 3h 45m" → 4365 minutes
// Quota calculations: (amount / sum) * 100 for percentage reporting
```

**Key Methods**:
- `readableToMinutes()`: Parses human input to minutes
- `minutesToReadable()`: Converts minutes to display format
- `formatDuration()`: Creates HH:MM display with optional day conversion
- `formatQuota()`: Calculates percentage quotas for reporting

### 2. EntryQueryService

**Purpose**: Type-safe entry querying with advanced filtering and pagination

**Business Logic Patterns**:
- **Filter normalization**: Handles legacy `*_id` aliases for backward compatibility
- **Pagination safety**: Validates page numbers and enforces result limits
- **Query optimization**: Builds efficient database queries through repository layer

**Key Features**:
```php
// Filter validation and defaults
$page = max(0, $page); // Prevent negative pages
$maxResults = $maxResults > 0 ? $maxResults : 50; // Default pagination

// Legacy compatibility
$project = $dto->project ?? $dto->project_id ?? 0;
$customer = $dto->customer ?? $dto->customer_id ?? 0;
```

**Return Type**: `PaginatedEntryCollection` with type-safe entry arrays

### 3. ExportService

**Purpose**: Complex data export with JIRA integration and batch processing

**Core Algorithms**:

#### Entry Export Algorithm
1. **Filter Construction**: Build database filters from user criteria
2. **Data Retrieval**: Fetch entries through repository with user scoping
3. **URL Generation**: Create ticket and worklog URLs using ticket system templates
4. **Data Transformation**: Convert entities to export-ready arrays

#### JIRA Enrichment Algorithm
```php
// Batch optimization strategy
1. Group entries by ticket system to minimize API calls
2. Build JQL queries for multiple tickets: "key in (PROJ-1,PROJ-2,PROJ-3)"
3. Fetch ticket metadata (billable status, titles) in single request
4. Apply enrichment data to corresponding entries
5. Handle API failures gracefully without blocking export
```

#### Memory-Efficient Batch Processing
```php
// Generator pattern for large datasets
function exportEntriesBatched($userId, $year, $month, $batchSize = 1000): Generator
{
    $offset = 0;
    do {
        $batch = $repository->findByDatePaginated(..., $offset, $batchSize);
        if (!empty($batch)) {
            yield $batch; // Memory-efficient streaming
        }
        $offset += $batchSize;
    } while (count($batch) === $batchSize);
}
```

### 4. SubticketSyncService

**Purpose**: Automated synchronization of JIRA project subtickets

**Business Process**:
1. **Project Validation**: Ensure project exists with ticket system configuration
2. **Authentication Check**: Verify project lead has JIRA access tokens  
3. **Subticket Discovery**: Fetch all subtickets for main project tickets
4. **Data Persistence**: Store comma-separated subticket list in project entity

**Error Handling Strategy**:
- Missing project → HTTP 404
- No ticket system → HTTP 400  
- No project lead → HTTP 400
- Missing tokens → HTTP 400 with user identification

## Integration Services

### 1. JiraIntegrationService

**Purpose**: High-level JIRA integration orchestration and business logic

**Core Business Operations**:

#### Worklog Synchronization Logic
```php
// Sync decision matrix
shouldSyncWithJira(ticketSystem, entry) {
    return ticketSystem.isJira() 
        && ticketSystem.bookTime 
        && entry.hasValidTicket() 
        && entry.hasValidTimeData()
        && entry.duration > 0;
}
```

#### Batch Processing Strategy
- **Fault Isolation**: Continue processing if individual entries fail
- **Result Tracking**: Return success/failure map indexed by entry ID
- **Comprehensive Logging**: Log all sync attempts with detailed context

#### Smart Ticket System Selection
```php
// Priority: Internal JIRA project override → Default project ticket system
$ticketSystem = $project->hasInternalJiraProjectKey() 
    ? $repository->find($project->getInternalJiraTicketSystem())
    : $project->getTicketSystem();
```

### 2. JiraWorkLogService

**Purpose**: Direct JIRA worklog API operations with comprehensive error handling

**Key Algorithms**:

#### Worklog Data Preparation
```php
function prepareWorkLogData(Entry $entry): array {
    return [
        'comment' => buildComment($entry), // Customer | Project | Activity | Description
        'started' => formatJiraDateTime($entry), // ISO 8601 with timezone
        'timeSpentSeconds' => $entry->getDuration() * 60 // Convert minutes to seconds
    ];
}
```

#### Sync State Management
- **Validation**: Verify ticket exists before creating worklog
- **State Consistency**: Handle orphaned worklog IDs from deleted JIRA entries
- **Zero Duration**: Delete worklog when entry duration becomes zero

#### Error Recovery Patterns
```php
// Graceful worklog validation
if ($entry->getWorklogId() && !$this->doesWorkLogExist($ticket, $worklogId)) {
    $entry->setWorklogId(null); // Reset orphaned reference
}

// API error isolation
try {
    $this->updateEntryWorkLog($entry);
} catch (Exception $e) {
    error_log("Failed sync for entry {$entry->getId()}: {$e->getMessage()}");
    // Continue with other entries
}
```

### 3. JiraAuthenticationService

**Purpose**: OAuth token management with secure encryption

**OAuth Flow Implementation**:
1. **Request Token**: Initiate OAuth with callback URL
2. **User Authorization**: Redirect to JIRA for approval  
3. **Access Token**: Exchange verifier for permanent tokens
4. **Secure Storage**: Encrypt tokens using AES-256-GCM

**Token Security Features**:
```php
// Encrypted storage with fallback for legacy tokens
try {
    return [
        'token' => $this->tokenEncryption->decryptToken($userTicketSystem->getAccessToken()),
        'secret' => $this->tokenEncryption->decryptToken($userTicketSystem->getTokenSecret())
    ];
} catch (Exception) {
    // Handle legacy unencrypted tokens during migration
    return [
        'token' => $userTicketSystem->getAccessToken(),
        'secret' => $userTicketSystem->getTokenSecret()
    ];
}
```

### 4. JiraTicketService

**Purpose**: Comprehensive JIRA ticket operations and metadata management

**Intelligent Issue Type Mapping**:
```php
function getIssueType(Entry $entry): string {
    $activityName = strtolower($entry->getActivity()->getName());
    
    if (str_contains($activityName, 'bug') || str_contains($activityName, 'fix')) {
        return 'Bug';
    }
    if (str_contains($activityName, 'feature') || str_contains($activityName, 'development')) {
        return 'Story';  
    }
    if (str_contains($activityName, 'support') || str_contains($activityName, 'maintenance')) {
        return 'Task';
    }
    return 'Task'; // Safe default
}
```

**Advanced Search Capabilities**:
- **JQL Generation**: Build complex queries with field filtering
- **Subticket Discovery**: Navigate JIRA hierarchy relationships
- **Ticket Validation**: Efficient existence checking
- **Transition Management**: State change orchestration

### 5. ModernLdapService

**Purpose**: Enterprise LDAP integration with security-first design

**Security Implementation**:
```php
// LDAP injection prevention
function sanitizeLdapInput(string $input): string {
    $metaChars = [
        '\\' => '\5c', '*' => '\2a', '(' => '\28', ')' => '\29',
        "\x00" => '\00', '/' => '\2f'
    ];
    return str_replace(array_keys($metaChars), array_values($metaChars), $input);
}
```

**Connection Management**:
- **Service Account**: Dedicated read-only account for searches
- **Connection Pooling**: Efficient resource management
- **SSL/TLS Support**: Encrypted communications
- **Graceful Disconnection**: Proper resource cleanup

**User Data Normalization**:
```php
// Standardized user data format
return [
    'dn' => $entry['dn'],
    'username' => $entry[$userNameField][0] ?? '',
    'email' => $entry['mail'][0] ?? '',
    'firstName' => $entry['givenName'][0] ?? '',
    'lastName' => $entry['sn'][0] ?? '',
    'displayName' => $entry['displayName'][0] ?? '',
    'department' => $entry['department'][0] ?? '',
    'title' => $entry['title'][0] ?? ''
];
```

## Security Services

### 1. TokenEncryptionService

**Purpose**: Military-grade token encryption using AES-256-GCM

**Encryption Algorithm**:
```php
// AES-256-GCM with authenticated encryption
- Cipher: AES-256-GCM (provides both confidentiality and integrity)
- Key Derivation: SHA-256 hash of application secret
- IV: Random 16-byte initialization vector per encryption
- Tag Length: 16 bytes for authentication
- Format: base64(IV + TAG + ENCRYPTED_DATA)
```

**Security Features**:
- **Authenticated Encryption**: Prevents tampering
- **Unique IV**: Different IV for each encryption operation
- **Key Rotation**: Support for rotating encrypted tokens
- **Legacy Compatibility**: Graceful handling of unencrypted legacy tokens

**Token Lifecycle**:
```php
// Encrypt → Store → Retrieve → Decrypt → Rotate
$encrypted = $service->encryptToken($plainToken);
// ... storage ...
$decrypted = $service->decryptToken($encrypted);
$rotated = $service->rotateToken($encrypted); // New IV, same content
```

## Utility Services

### 1. QueryCacheService

**Purpose**: Intelligent query result caching with tag-based invalidation

**Caching Strategy**:
```php
// Cache-aside pattern with automatic callback execution
$result = $cache->remember($key, function() {
    return $expensiveOperation();
}, $ttl);
```

**Invalidation Patterns**:
- **Tag-based**: Group related cache entries for bulk invalidation
- **Entity-specific**: Invalidate by entity class and ID
- **Pattern matching**: Wildcard-based cache clearing
- **TTL management**: Automatic expiration handling

**Performance Optimizations**:
- **Memory warmup**: Preload frequently accessed data
- **Statistics tracking**: Monitor cache hit ratios
- **Adapter agnostic**: Works with Redis, Memcached, filesystem

### 2. ResponseFactory

**Purpose**: Standardized API response creation with internationalization

**Response Patterns**:
```php
// Success responses with optional alerts
$factory->success(['data' => $results], 'Operation completed');

// Error responses with HTTP status codes
$factory->error('Validation failed', 422);
$factory->notFound('Resource not found');
$factory->unauthorized('Access denied', '/login');

// Specialized responses
$factory->paginated($items, $page, $totalPages, $totalItems, $perPage);
$factory->jiraApiError($exception, 'JIRA integration failed');
```

**Error Handling Hierarchy**:
- **JIRA API Errors**: Specialized handling for integration failures
- **Validation Errors**: Detailed field-level error reporting  
- **Authentication Errors**: Redirect-aware unauthorized responses
- **Generic Errors**: Fallback with proper HTTP status codes

### 3. TicketService

**Purpose**: Ticket format validation and parsing utilities

**Ticket Format Validation**:
```php
// JIRA ticket format: PROJECT-123
const TICKET_REGEXP = '/^([A-Z]+[0-9A-Z]*)-([0-9]+)$/i';

// Validation and parsing
$isValid = $service->checkFormat('PROJ-123'); // true
$prefix = $service->getPrefix('PROJ-123'); // 'PROJ'
$jiraId = $service->extractJiraId('PROJ-123'); // 'PROJ'
```

### 4. LocalizationService

**Purpose**: Internationalization support for multi-language deployments

**Features**:
- **Message translation**: Support for multiple languages
- **Date/time formatting**: Locale-aware formatting
- **Number formatting**: Currency and decimal formatting
- **Timezone handling**: User-specific timezone conversion

### 5. SystemClock

**Purpose**: Testable time operations with dependency injection

**Clock Interface Implementation**:
```php
interface ClockInterface {
    public function now(): DateTimeImmutable; // Current timestamp
    public function today(): DateTimeImmutable; // Midnight today
}

// Production: SystemClock uses real time
// Testing: MockClock allows time manipulation
```

## Service Patterns and Best Practices

### 1. Dependency Injection Patterns

**Constructor Injection**:
```php
public function __construct(
    private readonly ManagerRegistry $managerRegistry,
    private readonly JiraWorkLogService $jiraWorkLogService,
    private readonly ?LoggerInterface $logger = null
) {}
```

**Service Dependencies**:
- **Repository Access**: Through ManagerRegistry for entity operations
- **HTTP Clients**: Injected HTTP services for external API calls
- **Logging**: Optional logger injection for debugging and monitoring
- **Configuration**: ParameterBag for environment-specific settings

### 2. Error Handling Strategies

**Exception Hierarchy**:
- **Domain Exceptions**: `JiraApiException`, `JiraApiUnauthorizedException`
- **Validation Exceptions**: Input validation and business rule violations
- **Infrastructure Exceptions**: Database, network, and external service failures

**Graceful Degradation**:
```php
// Continue processing despite individual failures
foreach ($entries as $entry) {
    try {
        $results[$entryId] = $this->processEntry($entry);
    } catch (Exception $e) {
        $results[$entryId] = false;
        $this->log('Processing failed', ['entry' => $entryId, 'error' => $e->getMessage()]);
    }
}
```

### 3. Transaction Management

**Database Transactions**:
```php
$objectManager = $this->managerRegistry->getManager();
try {
    $objectManager->beginTransaction();
    // ... multiple operations ...
    $objectManager->commit();
} catch (Exception $e) {
    $objectManager->rollback();
    throw $e;
}
```

**Atomic Operations**:
- **Entry Creation**: Ensure all related data is created together
- **JIRA Sync**: Maintain consistency between local and remote state
- **Batch Processing**: All-or-nothing semantics for critical operations

### 4. Caching Strategies

**Query Result Caching**:
```php
// Cache expensive operations with intelligent invalidation
$entries = $cache->remember("user_{$userId}_entries_{$month}", function() {
    return $this->repository->findByUserAndMonth($userId, $month);
}, 300);

// Tag for group invalidation
$cache->tag("user_{$userId}_entries", 'user_entries', 'monthly_data');
```

**Cache Invalidation Triggers**:
- **Entry CRUD**: Invalidate user-specific and date-specific caches
- **Project Changes**: Clear project-related cached data
- **JIRA Sync**: Refresh integration-related caches

### 5. Security Patterns

**Input Sanitization**:
```php
// LDAP injection prevention
$username = $this->sanitizeLdapInput($username);

// SQL injection prevention through ORM
$entries = $repository->findBy(['user' => $user, 'project' => $project]);
```

**Token Security**:
```php
// Encrypted storage of sensitive data
$encryptedToken = $this->tokenEncryption->encryptToken($accessToken);
$userTicketSystem->setAccessToken($encryptedToken);
```

**Authentication Flow**:
```php
// Multi-factor authentication check
if (!$this->checkUserTicketSystem($user, $ticketSystem)) {
    $this->throwUnauthorizedRedirect($ticketSystem);
}
```

## Performance Optimizations

### 1. Database Query Optimization

**Eager Loading**:
```php
// Load related entities in single query
$entries = $repository->findWithRelations(['user', 'project', 'customer', 'activity']);
```

**Pagination Strategy**:
```php
// Limit result sets and provide pagination
$query->setFirstResult($offset)->setMaxResults($limit);
```

**Index Usage**:
- **User + Date**: Optimize common entry queries
- **Project + Ticket System**: Fast JIRA integration lookups
- **Sync Status**: Efficient identification of entries needing sync

### 2. Memory Management

**Generator Patterns**:
```php
// Stream large datasets without memory exhaustion
function exportLargeDataset(): Generator {
    foreach ($this->getBatches() as $batch) {
        yield from $batch;
    }
}
```

**Batch Processing**:
```php
// Process in configurable batch sizes
$batchSize = 1000;
for ($offset = 0; $offset < $total; $offset += $batchSize) {
    $batch = $this->processBatch($offset, $batchSize);
    unset($batch); // Free memory
}
```

### 3. External API Optimization

**Connection Reuse**:
```php
// Reuse HTTP clients for multiple requests
$client = $this->getClient(); // Singleton pattern
foreach ($requests as $request) {
    $responses[] = $client->request($request);
}
```

**Bulk Operations**:
```php
// Group JIRA requests to minimize API calls
$jql = "key in (" . implode(',', $ticketKeys) . ")";
$result = $this->searchTickets($jql, $fields, count($ticketKeys));
```

## Monitoring and Observability

### 1. Logging Strategy

**Structured Logging**:
```php
$this->logger->info('JIRA worklog synced', [
    'service' => 'JiraIntegrationService',
    'entry_id' => $entry->getId(),
    'worklog_id' => $entry->getWorklogId(),
    'duration' => $entry->getDuration()
]);
```

**Log Levels**:
- **Error**: Failed operations requiring investigation
- **Warning**: Degraded functionality or recoverable errors
- **Info**: Successful operations and state changes
- **Debug**: Detailed execution flow for troubleshooting

### 2. Metrics Collection

**Performance Metrics**:
- **API Response Times**: Track JIRA and LDAP response performance
- **Cache Hit Ratios**: Monitor caching effectiveness
- **Error Rates**: Track failure rates by service and operation

**Business Metrics**:
- **Sync Success Rates**: JIRA integration health
- **User Activity**: Authentication and entry creation patterns
- **Export Volume**: Data export usage patterns

### 3. Health Checks

**Service Health Monitoring**:
```php
// JIRA connectivity health check
public function validateJiraConnection(TicketSystem $ticketSystem, User $user): bool {
    try {
        return $this->jiraWorkLogService->validateConnection($user, $ticketSystem);
    } catch (Exception $exception) {
        $this->log('JIRA connection validation failed', [
            'ticket_system_id' => $ticketSystem->getId(),
            'error' => $exception->getMessage()
        ], 'error');
        return false;
    }
}
```

## Conclusion

The TimeTracker service layer demonstrates sophisticated enterprise-grade architecture with:

- **Comprehensive Business Logic**: Complex time calculations, JIRA integration, and data processing
- **Security-First Design**: Encrypted token storage, input sanitization, and authentication flows
- **Performance Optimization**: Intelligent caching, batch processing, and memory management
- **Fault Tolerance**: Graceful error handling, circuit breaker patterns, and transaction safety
- **Observability**: Structured logging, metrics collection, and health monitoring

The architecture supports scalable, maintainable, and secure time tracking operations while providing extensive integration capabilities with enterprise systems like JIRA and LDAP.