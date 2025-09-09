# Timetracker Application API Documentation

This comprehensive documentation covers the critical services, controllers, and data transfer objects in the timetracker application.

## Table of Contents

1. [Authentication & Security APIs](#authentication--security-apis)
2. [Core Entry Management APIs](#core-entry-management-apis)
3. [Integration APIs](#integration-apis)
4. [Data Transfer Objects](#data-transfer-objects)
5. [Error Handling Patterns](#error-handling-patterns)
6. [Security Considerations](#security-considerations)
7. [Performance Implications](#performance-implications)

---

## Authentication & Security APIs

### LdapAuthenticator

**Purpose**: Handles LDAP authentication for user login and automatic user creation.

**Location**: `src/Security/LdapAuthenticator.php`

#### Key Methods

##### `supports(Request $request): bool`
Determines if this authenticator should handle the current request.

```php
/**
 * @param Request $request HTTP request object
 * @return bool True if request is POST to _login route
 */
public function supports(Request $request): bool
```

**Parameters:**
- `$request`: The HTTP request object

**Returns:** Boolean indicating if authenticator should handle request

**Usage Example:**
```php
// Automatically called by Symfony security system
// Returns true for POST requests to /_login endpoint
```

##### `authenticate(Request $request): Passport`
Performs LDAP authentication and returns security passport.

```php
/**
 * @param Request $request HTTP request with username/password
 * @return Passport Security passport with user credentials
 * @throws CustomUserMessageAuthenticationException When authentication fails
 * @throws UserNotFoundException When user not found and creation disabled
 * @throws Exception When LDAP or database operations fail
 */
public function authenticate(Request $request): Passport
```

**Parameters:**
- `$request`: HTTP request containing `_username`, `_password`, `_csrf_token`

**Returns:** Symfony Passport object with authenticated user

**Security Features:**
- Username sanitization and validation (max 256 chars, alphanumeric + `._@-`)
- LDAP injection prevention through input escaping
- Automatic user creation if enabled
- Team assignment based on LDAP groups
- Comprehensive error logging with partial username masking

**Error Handling:**
- Invalid credentials → `CustomUserMessageAuthenticationException`
- LDAP connection issues → Generic authentication failure message
- User creation disabled → `UserNotFoundException`

**Usage Example:**
```php
// Configuration in security.yaml
security:
    firewalls:
        main:
            custom_authenticator: App\Security\LdapAuthenticator
```

---

### TokenEncryptionService

**Purpose**: Provides secure encryption/decryption for sensitive tokens using AES-256-GCM.

**Location**: `src/Service/Security/TokenEncryptionService.php`

#### Key Methods

##### `encryptToken(string $token): string`
Encrypts a plaintext token with authenticated encryption.

```php
/**
 * @param string $token Plain text token to encrypt
 * @return string Base64 encoded encrypted token with IV and auth tag
 * @throws RuntimeException If encryption fails
 */
public function encryptToken(string $token): string
```

**Parameters:**
- `$token`: Plaintext token to encrypt

**Returns:** Base64 encoded string containing IV + auth tag + encrypted data

**Security Features:**
- AES-256-GCM authenticated encryption
- Random IV generation for each encryption
- Tamper detection through authentication tag
- Secure key derivation from environment secret

**Usage Example:**
```php
$encryptionService = new TokenEncryptionService($parameterBag);
$encryptedToken = $encryptionService->encryptToken('secret-api-token');
// Returns: "base64-encoded-iv-tag-ciphertext"
```

##### `decryptToken(string $encryptedToken): string`
Decrypts an encrypted token and verifies authenticity.

```php
/**
 * @param string $encryptedToken Base64 encoded encrypted token
 * @return string The decrypted plain text token
 * @throws RuntimeException If decryption fails or token tampered
 */
public function decryptToken(string $encryptedToken): string
```

**Parameters:**
- `$encryptedToken`: Base64 encoded encrypted token

**Returns:** Original plaintext token

**Error Conditions:**
- Invalid format → `RuntimeException`
- Tampered data → `RuntimeException`
- Decryption failure → `RuntimeException`

##### `rotateToken(string $encryptedToken): string`
Re-encrypts token with new IV for security rotation.

```php
/**
 * @param string $encryptedToken Current encrypted token
 * @return string Newly encrypted token with fresh IV
 * @throws RuntimeException If rotation fails
 */
public function rotateToken(string $encryptedToken): string
```

**Performance Note:** Token rotation should be performed periodically for enhanced security.

---

## Core Entry Management APIs

### SaveEntryAction

**Purpose**: REST endpoint for creating and updating time tracking entries.

**Location**: `src/Controller/Tracking/SaveEntryAction.php`

**Route**: `POST /tracking/save`

#### Method Signature

```php
/**
 * @param Request $request HTTP request object
 * @param EntrySaveDto $dto Validated entry data
 * @return Response|JsonResponse|Error|RedirectResponse
 * @throws BadRequestException When request data invalid
 * @throws BadMethodCallException When method called incorrectly
 * @throws InvalidArgumentException When arguments invalid
 */
public function __invoke(Request $request, EntrySaveDto $dto): Response
```

#### Request Payload

```json
{
  "id": 123,                    // Optional: Entry ID for updates
  "date": "2024-01-15",         // Required: Date in Y-m-d format
  "start": "09:00:00",          // Required: Start time in H:i:s format
  "end": "17:30:00",            // Required: End time in H:i:s format
  "customer_id": 1,             // Required: Customer ID
  "project_id": 2,              // Required: Project ID
  "activity_id": 3,             // Required: Activity ID
  "ticket": "ABC-123",          // Optional: Ticket reference
  "description": "Work done"    // Optional: Description
}
```

#### Response Format

**Success Response (200):**
```json
{
  "result": {
    "date": "15/01/2024",
    "start": "09:00",
    "end": "17:30",
    "user": 1,
    "customer": 1,
    "project": 2,
    "activity": 3,
    "duration": 510,              // Duration in minutes
    "durationString": "08:30",    // Formatted duration
    "class": "DAYBREAK",
    "ticket": "ABC-123",
    "description": "Work done"
  }
}
```

**Error Response (400):**
```json
{
  "error": "Start time cannot be after end time."
}
```

#### Validation Rules

1. **Authentication**: User must be logged in
2. **Entity Validation**: Customer, Project, Activity must exist
3. **Time Validation**: Start < End time
4. **Ticket Validation**: Must match project JIRA prefix if configured
5. **Project Status**: Project must be active
6. **Ownership**: Can only edit own entries

#### Business Logic

- Automatically calculates duration from start/end times
- Validates ticket format against project JIRA configuration
- Prevents overlapping entries (implicit through UI)
- Sets entry class to `DAYBREAK` by default

**Usage Example:**
```javascript
fetch('/tracking/save', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({
    date: '2024-01-15',
    start: '09:00',
    end: '17:30',
    customer_id: 1,
    project_id: 2,
    activity_id: 3,
    description: 'Development work'
  })
});
```

---

### ExportAction

**Purpose**: Generates Excel exports of time tracking data with filtering and aggregation.

**Location**: `src/Controller/Controlling/ExportAction.php`

**Route**: `GET /controlling/export`

#### Method Signature

```php
/**
 * @param Request $request HTTP request with query parameters
 * @param ExportQueryDto $exportQueryDto Mapped query parameters
 * @return Response Excel file download or error response
 * @throws InvalidArgumentException When export parameters invalid
 */
public function __invoke(Request $request, ExportQueryDto $exportQueryDto): Response
```

#### Query Parameters

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `userid` | int | 0 | User ID (0 = all users) |
| `year` | int | current | Year to export (1900-2100) |
| `month` | int | 0 | Month (0 = all months, 1-12) |
| `project` | int | 0 | Project ID filter |
| `customer` | int | 0 | Customer ID filter |
| `billable` | bool | false | Filter by billable status |
| `tickettitles` | bool | false | Include ticket titles |

**Usage Examples:**

```bash
# Export all entries for 2024
GET /controlling/export?year=2024

# Export specific user's January 2024 entries
GET /controlling/export?userid=5&year=2024&month=1

# Export with ticket information
GET /controlling/export?year=2024&tickettitles=true&billable=true
```

#### Response

**Success**: Excel file download with headers:
```
Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet
Content-Disposition: attachment;filename=2024_01_john-doe.xlsx
```

**Error (422)**: Invalid parameters
```
Month must be between 0 and 12 (0 means all months)
```

#### Export Features

1. **Data Sheets**:
   - Main data with entries
   - Summary statistics by user
   - Holiday and sick day counts

2. **Enrichment**:
   - JIRA ticket information when available
   - Billable status if configured
   - User abbreviations and full names

3. **Performance**:
   - Uses optimized queries for large datasets
   - Temporary file handling for memory efficiency
   - Streaming response for large exports

---

### EntryRepository

**Purpose**: Data access layer for time tracking entries with optimized queries.

**Location**: `src/Repository/EntryRepository.php`

#### Key Methods

##### `findOneById(int $id): ?Entry`
Type-safe method to find entry by ID.

```php
/**
 * @param int $id Entry ID
 * @return Entry|null Found entry or null
 */
public function findOneById(int $id): ?Entry
```

##### `getEntriesForMonth(User $user, string $startDate, string $endDate): array`
Retrieves entries for a user within date range.

```php
/**
 * @param User $user User entity
 * @param string $startDate Start date (Y-m-d format)
 * @param string $endDate End date (Y-m-d format)
 * @return Entry[] Array of entries ordered by date and time
 */
public function getEntriesForMonth(User $user, string $startDate, string $endDate): array
```

##### `getRawData(string $startDate, string $endDate, ?int $userId = null): array`
High-performance raw data retrieval with database-agnostic formatting.

```php
/**
 * @param string $startDate Start date filter
 * @param string $endDate End date filter
 * @param int|null $userId Optional user filter
 * @return list<array<string, mixed>> Raw entry data with formatted dates/times
 */
public function getRawData(string $startDate, string $endDate, ?int $userId = null): array
```

**Performance Features:**
- Direct SQL for optimal performance
- Database-agnostic date formatting (MySQL/SQLite)
- Prepared statements for security
- Left joins for complete data retrieval

##### `getFilteredEntries(array $filters, int $offset, int $limit, string $orderBy, string $orderDirection): array`
Paginated entry retrieval with filtering.

```php
/**
 * @param array $filters Associative array of filter conditions
 * @param int $offset Pagination offset
 * @param int $limit Maximum results
 * @param string $orderBy Field to order by
 * @param string $orderDirection ASC or DESC
 * @return Entry[] Filtered and paginated results
 */
public function getFilteredEntries(array $filters = [], int $offset = 0, int $limit = 50, string $orderBy = 'day', string $orderDirection = 'DESC'): array
```

**Filter Options:**
- `startDate` / `endDate`: Date range
- `user`: User ID
- `customer`: Customer ID  
- `project`: Project ID
- `activity`: Activity ID

##### `bulkUpdate(array $entryIds, array $updateData): int`
Efficiently update multiple entries.

```php
/**
 * @param int[] $entryIds Array of entry IDs to update
 * @param array<string, mixed> $updateData Fields to update
 * @return int Number of affected records
 */
public function bulkUpdate(array $entryIds, array $updateData): int
```

**Usage Example:**
```php
$repository = $em->getRepository(Entry::class);

// Bulk update descriptions
$affectedRows = $repository->bulkUpdate(
    [1, 2, 3], 
    ['description' => 'Updated via bulk operation']
);
```

#### Database Compatibility Features

The repository includes sophisticated database abstraction for MySQL and SQLite:

```php
// MySQL
"DATE_FORMAT(e.day, '%d/%m/%Y')"
"YEAR(e.day)"

// SQLite  
"strftime('%d/%m/%Y', e.day)"
"strftime('%Y', e.day)"
```

This ensures consistent functionality across development (SQLite) and production (MySQL) environments.

---

## Integration APIs

### JiraOAuthApiService

**Purpose**: Handles JIRA OAuth authentication and REST API operations.

**Location**: `src/Service/Integration/Jira/JiraOAuthApiService.php`

#### Authentication Flow

##### `fetchOAuthRequestToken(): string`
Initiates OAuth flow by requesting a temporary token.

```php
/**
 * @return string OAuth authorization URL for user redirect
 * @throws JiraApiException When OAuth request fails
 */
protected function fetchOAuthRequestToken(): string
```

**Process:**
1. Generates OAuth request token from JIRA
2. Returns authorization URL for user redirect
3. User authorizes application in JIRA
4. JIRA redirects to callback with verification code

##### `fetchOAuthAccessToken(string $oAuthRequestToken, string $oAuthVerifier): void`
Exchanges temporary token for permanent access token.

```php
/**
 * @param string $oAuthRequestToken Temporary request token
 * @param string $oAuthVerifier Verification code from JIRA
 * @throws JiraApiException When token exchange fails
 */
public function fetchOAuthAccessToken(string $oAuthRequestToken, string $oAuthVerifier): void
```

#### JIRA API Operations

##### `updateEntryJiraWorkLog(Entry $entry): void`
Creates or updates work log entry in JIRA.

```php
/**
 * @param Entry $entry Time tracking entry
 * @throws JiraApiException When API request fails
 * @throws JiraApiInvalidResourceException When resource not found
 */
public function updateEntryJiraWorkLog(Entry $entry): void
```

**Process:**
1. Validates ticket exists in JIRA
2. Checks for existing work log entry
3. Creates new or updates existing work log
4. Stores work log ID in entry for future updates

**Work Log Data:**
```php
$workLogData = [
    'comment' => '#123: Development: Implemented user authentication',
    'started' => '2024-01-15T09:00:00.000+0100',
    'timeSpentSeconds' => 3600  // 1 hour in seconds
];
```

##### `searchTicket(string $jql, array $fields, int $limit = 1): mixed`
Searches JIRA tickets using JQL (JIRA Query Language).

```php
/**
 * @param string $jql JIRA Query Language string
 * @param string[] $fields Fields to retrieve
 * @param int $limit Maximum results
 * @return mixed Search results from JIRA API
 * @throws JiraApiException When search fails
 */
public function searchTicket(string $jql, array $fields, int $limit = 1): mixed
```

**Usage Example:**
```php
$results = $jiraApi->searchTicket(
    'project = ABC AND status = "In Progress"',
    ['key', 'summary', 'status'],
    50
);
```

##### `getSubtickets(string $ticketKey): array`
Retrieves subtasks and epic-related tickets.

```php
/**
 * @param string $ticketKey JIRA ticket key (e.g., 'ABC-123')
 * @return list<mixed> Array of subtask keys
 */
public function getSubtickets(string $ticketKey): array
```

**Features:**
- Handles regular subtasks
- Supports Epic ticket type with linked stories
- Recursive subtask discovery
- Safe handling of missing/invalid tickets

#### Security Configuration

OAuth configuration requires:

```php
// RSA-SHA1 signature with private key
$oauth1 = new Oauth1([
    'consumer_key' => 'timetracker-app',
    'consumer_secret' => '-----BEGIN PRIVATE KEY-----...',
    'token_secret' => 'user-specific-secret',
    'token' => 'user-access-token',
    'signature_method' => Oauth1::SIGNATURE_METHOD_RSA,
    'private_key_file' => '/path/to/private-key.pem'
]);
```

---

### JiraAuthenticationService

**Purpose**: Separated authentication service for better maintainability and token security.

**Location**: `src/Service/Integration/Jira/JiraAuthenticationService.php`

#### Key Features

1. **Token Encryption**: All OAuth tokens encrypted before database storage
2. **Legacy Support**: Handles both encrypted and unencrypted tokens
3. **Clean Architecture**: Separated from API operations

##### `getTokens(User $user, TicketSystem $ticketSystem): array`
Retrieves and decrypts OAuth tokens for user.

```php
/**
 * @param User $user User entity
 * @param TicketSystem $ticketSystem JIRA ticket system
 * @return array{token: string, secret: string} Decrypted tokens
 * @throws Exception When token decryption fails
 */
public function getTokens(User $user, TicketSystem $ticketSystem): array
```

**Security Features:**
- Automatic encryption/decryption using TokenEncryptionService
- Fallback to legacy unencrypted tokens
- Secure token storage in database

##### `authenticate(User $user, TicketSystem $ticketSystem): void`
Validates user has valid OAuth tokens for ticket system.

```php
/**
 * @param User $user User to authenticate
 * @param TicketSystem $ticketSystem Target ticket system
 * @throws JiraApiUnauthorizedException When authentication required
 * @throws Exception When database operations fail
 */
public function authenticate(User $user, TicketSystem $ticketSystem): void
```

**Usage in Service Chain:**
```php
// 1. Check authentication
$authService->authenticate($user, $ticketSystem);

// 2. Get authenticated HTTP client  
$client = $httpClientService->getClient('user');

// 3. Make API requests
$response = $client->get('issue/ABC-123');
```

---

## Data Transfer Objects

### EntrySaveDto

**Purpose**: Validates and transfers time entry data between HTTP layer and business logic.

**Location**: `src/Dto/EntrySaveDto.php`

#### Properties and Validation

```php
readonly class EntrySaveDto
{
    public function __construct(
        public ?int $id = null,
        
        #[Assert\NotBlank(message: 'Date is required')]
        #[Assert\Date(message: 'Invalid date format')]
        public string $date = '',
        
        #[Assert\NotBlank(message: 'Start time is required')]
        #[Assert\Time(message: 'Invalid start time format')]
        public string $start = '00:00:00',
        
        #[Assert\NotBlank(message: 'End time is required')]
        #[Assert\Time(message: 'Invalid end time format')]
        public string $end = '00:00:00',
        
        #[Assert\Length(max: 50, maxMessage: 'Ticket cannot be longer than 50 characters')]
        #[Assert\Regex(pattern: '/^[A-Z0-9\-_]*$/i', message: 'Invalid ticket format')]
        public string $ticket = '',
        
        #[Assert\Length(max: 1000, maxMessage: 'Description cannot be longer than 1000 characters')]
        public string $description = '',
        
        #[Assert\Positive(message: 'Project ID must be positive')]
        public ?int $project_id = null,
        
        #[Assert\Positive(message: 'Customer ID must be positive')]
        public ?int $customer_id = null,
        
        #[Assert\Positive(message: 'Activity ID must be positive')]  
        public ?int $activity_id = null,
        
        // Legacy field support
        public ?int $project = null,
        public ?int $customer = null,
        public ?int $activity = null,
        public string $extTicket = '',
    ) {}
}
```

#### Custom Validation

##### Time Range Validation
```php
#[Assert\Callback]
public function validateTimeRange(ExecutionContextInterface $context): void
{
    $start = $this->getStartAsDateTime();
    $end = $this->getEndAsDateTime();
    
    if ($start && $end && $start >= $end) {
        $context->buildViolation('Start time must be before end time')
            ->atPath('end')
            ->addViolation();
    }
}
```

#### Helper Methods

##### Legacy Field Support
```php
public function getCustomerId(): ?int
{
    return $this->customer_id ?? $this->customer;
}

public function getProjectId(): ?int  
{
    return $this->project_id ?? $this->project;
}

public function getActivityId(): ?int
{
    return $this->activity_id ?? $this->activity;
}
```

This ensures backward compatibility with both `customer_id` and `customer` field naming conventions.

##### Date/Time Conversion
```php
/**
 * @throws Exception
 */
public function getDateAsDateTime(): ?DateTimeInterface
{
    if (empty($this->date)) {
        return null;
    }
    
    $date = DateTime::createFromFormat('Y-m-d', $this->date);
    return $date ?: null;
}
```

**Usage Example:**
```php
// Automatic mapping from HTTP request
public function save(Request $request, #[MapRequestPayload] EntrySaveDto $dto)
{
    // All validation automatically performed
    $customerId = $dto->getCustomerId(); // Handles legacy fields
    $date = $dto->getDateAsDateTime();   // Converts to DateTime
}
```

---

### ExportQueryDto

**Purpose**: Validates and transfers export parameters with type safety.

**Location**: `src/Dto/ExportQueryDto.php`

```php
readonly class ExportQueryDto
{
    public function __construct(
        public int $userid = 0,        // 0 = all users
        public int $year = 0,          // 0 = current year
        public int $month = 0,         // 0 = all months
        public int $project = 0,       // 0 = all projects
        public int $customer = 0,      // 0 = all customers
        public bool $billable = false, // Filter billable entries
        public bool $tickettitles = false, // Include ticket titles
    ) {}
}
```

#### Type-Safe Parameter Conversion

```php
private static function toInt(mixed $value): int
{
    if (null === $value || '' === $value) {
        return 0;
    }
    
    if (is_numeric($value)) {
        return (int) $value;
    }
    
    return 0;
}

private static function toBool(mixed $value): bool
{
    if (is_scalar($value)) {
        $normalized = strtolower(trim((string) $value));
        return in_array($normalized, ['1', 'true', 'on', 'yes'], true);
    }
    
    return false;
}
```

This handles various input formats safely:
- `"1"`, `"true"`, `"on"`, `"yes"` → `true`
- `"0"`, `"false"`, `"off"`, `"no"`, `""`, `null` → `false`

---

## Error Handling Patterns

### Exception Hierarchy

```php
// Base JIRA API exception
JiraApiException
├── JiraApiUnauthorizedException    // OAuth required
├── JiraApiInvalidResourceException // 404 errors
└── RuntimeException               // Encryption/decryption errors
```

### Consistent Error Responses

#### API Endpoints
```php
// Validation errors (400)
return new JsonResponse(['error' => 'Customer ID is required'], 400);

// Business logic errors (400)
return new Error('Start time cannot be after end time.', 400);

// Server errors (500)
return new Error('Could not save entry: ' . $exception->getMessage(), 500);
```

#### Authentication Errors
```php
// Redirect unauthenticated users
if (!$this->checkLogin($request)) {
    return $this->redirectToRoute('_login');
}

// LDAP authentication failures
throw new CustomUserMessageAuthenticationException('Authentication failed. Please check your credentials.');
```

### Security-First Error Handling

```php
// Safe error logging with data sanitization
$this->logger->error('LDAP authentication error', [
    'username' => substr($userIdentifier, 0, 3) . '***',  // Partial masking
    'error_code' => $ldapException->getCode(),
    'error_type' => $ldapException::class,
]);

// Generic user-facing messages for security
throw new CustomUserMessageAuthenticationException('An unexpected error occurred during authentication.');
```

---

## Security Considerations

### Input Validation and Sanitization

#### LDAP Injection Prevention
```php
private function sanitizeLdapInput(string $input): string
{
    // LDAP special characters that need escaping per RFC 4515
    $metaChars = [
        '\\' => '\5c',   // Must be first
        '*'  => '\2a',
        '('  => '\28', 
        ')'  => '\29',
        "\x00" => '\00',
        '/'  => '\2f',
    ];
    
    return str_replace(
        array_keys($metaChars),
        array_values($metaChars), 
        $input
    );
}
```

#### Username Validation
```php
private function isValidUsername(string $username): bool
{
    // Max length check
    if (strlen($username) > 256) {
        return false;
    }
    
    // Allow alphanumeric, dots, hyphens, underscores, @ for email format
    return 1 === preg_match('/^[a-zA-Z0-9._@-]+$/', $username);
}
```

#### SQL Injection Prevention
```php
// Always use prepared statements
$statement = $connection->prepare($sql);
foreach ($params as $key => $value) {
    $statement->bindValue($key, $value);
}
$result = $statement->executeQuery();
```

### Token Security

#### Encryption at Rest
```php
// All OAuth tokens encrypted before database storage
$encryptedSecret = $this->tokenEncryption->encryptToken($tokenSecret);
$encryptedToken = $this->tokenEncryption->encryptToken($accessToken);
```

#### Secure Key Management
```php
// Environment-based key derivation
$key = $parameterBag->get('app.encryption_key') ?? $parameterBag->get('APP_SECRET');
$this->encryptionKey = hash('sha256', $key, true);
```

### Access Control

#### Entry Ownership Validation
```php
// Prevent users from editing others' entries
if ($entry instanceof Entry && $entry->getUserId() !== $user->getId()) {
    return new Error('Entry is already owned by a different user.', Response::HTTP_BAD_REQUEST);
}
```

#### Project Access Control  
```php
// Validate project is active
if (!$project->getActive()) {
    return new Error('Project is no longer active.', Response::HTTP_BAD_REQUEST);
}
```

### CSRF Protection

```php
// Automatic CSRF token validation
return new Passport(
    new UserBadge($username, $userLoader),
    new CustomCredentials(static fn (): true => true, ['username' => $username]),
    [
        new CsrfTokenBadge('authenticate', $csrfToken),
        new RememberMeBadge(),
    ]
);
```

---

## Performance Implications

### Database Optimization

#### Query Optimization
```php
// Use database-specific functions for optimal performance
// MySQL
"DATE_FORMAT(e.day, '%d/%m/%Y')"
"YEAR(e.day)"  

// SQLite
"strftime('%d/%m/%Y', e.day)" 
"strftime('%Y', e.day)"
```

#### Efficient Joins
```php
public function findEntriesWithRelations(array $conditions = []): QueryBuilder
{
    return $this->createQueryBuilder('e')
        ->leftJoin('e.user', 'u')      // Eager load relations
        ->leftJoin('e.customer', 'c')
        ->leftJoin('e.project', 'p') 
        ->leftJoin('e.activity', 'a');
}
```

#### Pagination for Large Datasets
```php
public function findByDatePaginated(
    int $user,
    int $year, 
    ?int $month = null,
    ?int $project = null,
    ?int $customer = null,
    ?array $arSort = null,
    int $offset = 0,
    int $limit = 1000  // Configurable page size
): array
```

### Memory Management

#### Raw Data Access
```php
// Direct SQL for large exports to minimize memory usage
public function getRawData(string $startDate, string $endDate, ?int $userId = null): array
{
    // Uses prepared statements with streaming results
    $statement = $connection->prepare($sql);
    return $statement->executeQuery()->fetchAllAssociative();
}
```

#### Temporary File Handling
```php
// Excel export with temporary files for memory efficiency
$tmp = tempnam(sys_get_temp_dir(), 'ttt-export-');
$xlsx->save($tmp);
$response->setContent(file_get_contents($tmp));
unlink($tmp);  // Cleanup
```

### Caching Strategies

#### Client Connection Pooling
```php
// Reuse HTTP clients for multiple JIRA requests
/** @var Client[] */
protected array $clients = [];

protected function getClient(string $tokenMode = 'user', ?string $oAuthToken = null): Client
{
    $key = $oAuthToken . $oAuthTokenSecret;
    
    if (isset($this->clients[$key])) {
        return $this->clients[$key];  // Reuse existing client
    }
    
    // Create and cache new client
    $this->clients[$key] = new Client([...]);
    return $this->clients[$key];
}
```

### Bulk Operations

#### Batch Updates
```php
public function bulkUpdate(array $entryIds, array $updateData): int
{
    if (empty($entryIds) || empty($updateData)) {
        return 0;
    }
    
    $qb = $this->getEntityManager()->createQueryBuilder()
        ->update(Entry::class, 'e')
        ->where('e.id IN (:ids)')
        ->setParameter('ids', $entryIds);
        
    foreach ($updateData as $field => $value) {
        $qb->set("e.{$field}", ":{$field}")
           ->setParameter($field, $value);
    }
    
    return (int) $qb->getQuery()->execute();
}
```

#### Batch JIRA Operations
```php
public function updateEntriesJiraWorkLogsLimited(?int $entryLimit = null): void
{
    $entries = $objectRepository->findByUserAndTicketSystemToSync(
        (int) $this->user->getId(),
        (int) $this->ticketSystem->getId(), 
        $entryLimit ?? 50  // Process in batches
    );
    
    foreach ($entries as $entry) {
        try {
            $this->updateEntryJiraWorkLog($entry);
            $objectManager->persist($entry);
        } finally {
            $objectManager->flush();  // Commit each batch
        }
    }
}
```

---

This documentation provides comprehensive coverage of the timetracker application's critical APIs, focusing on practical usage, security considerations, and performance implications for developers working with the system.