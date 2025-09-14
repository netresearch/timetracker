# Data Transfer Objects (DTOs) Documentation

## Overview

This document provides comprehensive documentation for all Data Transfer Objects (DTOs) in the TimeTracker application. DTOs serve as the interface layer between HTTP requests and domain entities, providing type safety, validation, and data transformation capabilities.

### DTO Architecture

The TimeTracker application uses DTOs for:
- **Request Validation**: Input sanitization and constraint validation using Symfony Validator
- **Type Safety**: Strict typing with readonly properties for immutability
- **Entity Mapping**: Automatic mapping to domain entities using Symfony ObjectMapper
- **Legacy Support**: Backward compatibility with different field naming conventions
- **Data Transformation**: Converting between HTTP request data and domain objects

### Integration with Symfony Components

- **ObjectMapper**: `#[Map(target: EntityClass)]` attributes enable automatic DTO-to-entity mapping
- **Validation**: `#[Assert\*]` constraints provide declarative validation rules
- **MapRequestPayload**: Controller integration for automatic request-to-DTO mapping
- **Custom Validators**: Business-specific validation rules (uniqueness, cross-field validation)

---

## Core DTOs

### 1. EntrySaveDto

**Purpose**: Time entry creation and updates - the primary DTO for time tracking operations.

**Entity Mapping**: `App\Entity\Entry`

**Properties**:
```php
public ?int $id = null                    // Entry ID for updates
public string $date = ''                  // Date in Y-m-d format
public string $start = '00:00:00'        // Start time in H:i:s format
public string $end = '00:00:00'          // End time in H:i:s format
public string $ticket = ''               // Ticket reference (max 50 chars)
public string $description = ''          // Entry description (max 1000 chars)
public ?int $project_id = null          // Project ID (new format)
public ?int $customer_id = null         // Customer ID (new format)
public ?int $activity_id = null         // Activity ID (new format)
public ?int $project = null             // Project ID (legacy format)
public ?int $customer = null            // Customer ID (legacy format)
public ?int $activity = null            // Activity ID (legacy format)
public string $extTicket = ''           // External ticket reference
```

**Validation Constraints**:
- `date`: NotBlank, Date format validation
- `start`: NotBlank, Time format validation
- `end`: NotBlank, Time format validation
- `ticket`: Length max 50, Regex pattern `/^[A-Z0-9\-_]*$/i`
- `description`: Length max 1000 characters
- `project_id`, `customer_id`, `activity_id`: Positive integers
- Custom callback: `validateTimeRange()` ensures start < end time

**Usage Context**:
- **Controller**: `SaveEntryAction`
- **HTTP Method**: POST
- **Endpoint**: `/tracking/save-entry`

**Example JSON Payload**:
```json
{
  "id": 123,
  "date": "2024-09-14",
  "start": "09:00:00",
  "end": "17:30:00",
  "ticket": "PROJ-456",
  "description": "Implemented user authentication feature",
  "project_id": 10,
  "customer_id": 5,
  "activity_id": 2,
  "extTicket": "EXT-789"
}
```

**Transformation Rules**:
- Supports both `project_id`/`project` naming conventions
- Time format flexible: accepts both `H:i` and `H:i:s`
- Legacy field fallback via helper methods (`getProjectId()`, etc.)

---

### 2. BulkEntryDto

**Purpose**: Mass creation of time entries with date ranges and preset configurations.

**Properties**:
```php
public int $preset = 0                   // Preset template ID (required)
public string $startdate = ''           // Start date for bulk creation
public string $enddate = ''             // End date for bulk creation
public string $starttime = ''           // Default start time
public string $endtime = ''             // Default end time
public int $usecontract = 0             // Use contract hours (boolean as int)
public int $skipweekend = 0             // Skip weekends (boolean as int)
public int $skipholidays = 0            // Skip holidays (boolean as int)
```

**Validation Constraints**:
- `preset`: NotBlank, Positive integer
- Custom callbacks:
  - `validateTimeRange()`: Ensures start < end when not using contract
  - `validateDateRange()`: Ensures start date <= end date

**Usage Context**:
- **Controller**: `BulkEntryAction`
- **HTTP Method**: POST
- **Endpoint**: `/tracking/bulk-entry`

**Example JSON Payload**:
```json
{
  "preset": 5,
  "startdate": "2024-09-01",
  "enddate": "2024-09-30",
  "starttime": "08:00:00",
  "endtime": "16:00:00",
  "usecontract": 1,
  "skipweekend": 1,
  "skipholidays": 1
}
```

**Helper Methods**:
- `isUseContract()`: Converts int to boolean
- `isSkipWeekend()`: Converts int to boolean
- `isSkipHolidays()`: Converts int to boolean

---

### 3. UserSaveDto

**Purpose**: User account creation and management with team assignments.

**Entity Mapping**: `App\Entity\User`

**Properties**:
```php
public int $id = 0                       // User ID for updates
public string $username = ''             // Username (min 3 chars)
public string $abbr = ''                 // 3-letter abbreviation
public string $type = ''                 // User type
public string $locale = ''               // User locale preference
public array $teams = []                 // Team ID assignments (not mapped)
```

**Validation Constraints**:
- `username`: NotBlank, Length min 3, UniqueUsername (custom)
- `abbr`: NotBlank, Length exactly 3, UniqueUserAbbr (custom)
- `teams`: Custom callback ensures at least one team assignment

**Usage Context**:
- **Controller**: `SaveUserAction`
- **HTTP Method**: POST
- **Endpoint**: `/admin/save-user`

**Example JSON Payload**:
```json
{
  "id": 42,
  "username": "john.doe",
  "abbr": "JDO",
  "type": "employee",
  "locale": "en_US",
  "teams": [1, 3, 5]
}
```

**Static Factory**: `fromRequest(Request $request)` for form-based input

---

### 4. CustomerSaveDto

**Purpose**: Customer account management with team visibility controls.

**Entity Mapping**: `App\Entity\Customer`

**Properties**:
```php
public int $id = 0                       // Customer ID for updates
public string $name = ''                 // Customer name (min 3 chars)
public bool $active = false              // Active status
public bool $global = false              // Global visibility
public array $teams = []                 // Team access list (not mapped)
```

**Validation Constraints**:
- `name`: NotBlank, Length min 3, UniqueCustomerName (custom)
- Class-level: CustomerTeamsRequired (custom constraint)

**Usage Context**:
- **Controller**: Customer management endpoints
- **HTTP Method**: POST

**Example JSON Payload**:
```json
{
  "id": 15,
  "name": "Acme Corporation",
  "active": true,
  "global": false,
  "teams": [2, 4]
}
```

**Static Factory**: `fromRequest(Request $request)` for form-based input

---

### 5. ProjectSaveDto

**Purpose**: Project management with extensive metadata and external system integration.

**Entity Mapping**: `App\Entity\Project`

**Properties**:
```php
public int $id = 0                       // Project ID for updates
public string $name = ''                 // Project name (min 3 chars)
public ?int $customer = null             // Customer ID (not mapped)
public ?string $jiraId = null            // JIRA prefix (uppercase only)
public ?string $jiraTicket = null        // JIRA ticket reference
public bool $active = false              // Active status
public bool $global = false              // Global visibility
public string $estimation = '0m'         // Time estimation (not mapped)
public int $billing = 0                  // Billing configuration (not mapped)
public ?string $cost_center = null       // Cost center code
public ?string $offer = null             // Offer reference
public ?int $project_lead = null         // Project lead user ID (not mapped)
public ?int $technical_lead = null       // Technical lead user ID (not mapped)
public ?string $ticket_system = null     // External ticket system (not mapped)
public bool $additionalInformationFromExternal = false  // External data flag
public ?string $internalJiraTicketSystem = null         // Internal JIRA system
public string $internalJiraProjectKey = ''              // Internal JIRA key
```

**Validation Constraints**:
- `name`: NotBlank, Length min 3
- `jiraId`: Regex `/^[A-Z]+$/` (uppercase letters only), with trim normalizer
- Class-level: UniqueProjectNameForCustomer (custom constraint)

**Usage Context**:
- **Controller**: Project management endpoints
- **HTTP Method**: POST

**Example JSON Payload**:
```json
{
  "id": 8,
  "name": "Mobile App Development",
  "customer": 15,
  "jiraId": "MAD",
  "jiraTicket": "MAD-001",
  "active": true,
  "global": false,
  "estimation": "160h",
  "billing": 1,
  "cost_center": "DEV-001",
  "offer": "OFF-2024-089",
  "project_lead": 3,
  "technical_lead": 7,
  "ticket_system": "jira",
  "additionalInformationFromExternal": true,
  "internalJiraTicketSystem": "internal-jira",
  "internalJiraProjectKey": "IMAD"
}
```

**Static Factory**: `fromRequest(Request $request)` with null-safe conversions

---

### 6. ActivitySaveDto

**Purpose**: Activity type definition with billing factors and ticket requirements.

**Entity Mapping**: `App\Entity\Activity`

**Properties**:
```php
public int $id = 0                       // Activity ID for updates
public string $name = ''                 // Activity name (min 3 chars)
public bool $needsTicket = false         // Requires ticket reference
public float $factor = 0.0               // Billing factor (>= 0)
```

**Validation Constraints**:
- `name`: NotBlank, Length min 3, UniqueActivityName (custom)
- `factor`: GreaterThanOrEqual 0

**Usage Context**:
- **Controller**: Activity management endpoints
- **HTTP Method**: POST

**Example JSON Payload**:
```json
{
  "id": 3,
  "name": "Software Development",
  "needsTicket": true,
  "factor": 1.5
}
```

---

### 7. TeamSaveDto

**Purpose**: Team creation and management with lead user assignment.

**Entity Mapping**: `App\Entity\Team`

**Properties**:
```php
public int $id = 0                       // Team ID for updates
public string $name = ''                 // Team name (min 3 chars)
public int $lead_user_id = 0             // Lead user ID (required, positive)
```

**Validation Constraints**:
- `name`: NotBlank, Length min 3, UniqueTeamName (custom)
- `lead_user_id`: NotBlank, Positive

**Usage Context**:
- **Controller**: Team management endpoints
- **HTTP Method**: POST

**Example JSON Payload**:
```json
{
  "id": 2,
  "name": "Backend Development Team",
  "lead_user_id": 5
}
```

**Static Factory**: `fromRequest(Request $request)` for form-based input

---

### 8. ContractSaveDto

**Purpose**: Contract definition with weekly hour allocations and date ranges.

**Properties**:
```php
public int $id = 0                       // Contract ID for updates
public int $user_id = 0                  // User ID (required, positive)
public string $start = ''                // Start date (YYYY-MM-DD, required)
public ?string $end = null               // End date (YYYY-MM-DD, optional)
public float $hours_0 = 0.0              // Sunday hours
public float $hours_1 = 0.0              // Monday hours
public float $hours_2 = 0.0              // Tuesday hours
public float $hours_3 = 0.0              // Wednesday hours
public float $hours_4 = 0.0              // Thursday hours
public float $hours_5 = 0.0              // Friday hours
public float $hours_6 = 0.0              // Saturday hours
```

**Validation Constraints**:
- `user_id`: Positive, ValidUser (custom)
- `start`: NotBlank, Regex `/^\d{3,4}-\d{2}-\d{2}$/`
- Class-level: ContractDatesValid (custom constraint)

**Usage Context**:
- **Controller**: Contract management endpoints
- **HTTP Method**: POST

**Example JSON Payload**:
```json
{
  "id": 12,
  "user_id": 8,
  "start": "2024-01-01",
  "end": "2024-12-31",
  "hours_0": 0.0,
  "hours_1": 8.0,
  "hours_2": 8.0,
  "hours_3": 8.0,
  "hours_4": 8.0,
  "hours_5": 6.0,
  "hours_6": 0.0
}
```

**Static Factory**: `fromRequest(Request $request)` with comma-to-dot decimal conversion

---

### 9. PresetSaveDto

**Purpose**: Preset template definition for quick entry creation.

**Entity Mapping**: `App\Entity\Preset`

**Properties**:
```php
public int $id = 0                       // Preset ID for updates
public string $name = ''                 // Preset name (min 3 chars)
public ?int $customer = null             // Default customer ID (not mapped)
public ?int $project = null              // Default project ID (not mapped)
public ?int $activity = null             // Default activity ID (not mapped)
public string $description = ''          // Default description
```

**Validation Constraints**:
- `name`: NotBlank, Length min 3

**Usage Context**:
- **Controller**: Preset management endpoints
- **HTTP Method**: POST

**Example JSON Payload**:
```json
{
  "id": 7,
  "name": "Daily Standup Meeting",
  "customer": 5,
  "project": 12,
  "activity": 4,
  "description": "Daily team synchronization meeting"
}
```

**Static Factory**: `fromRequest(Request $request)` with null-safe conversions

---

### 10. TicketSystemSaveDto

**Purpose**: External ticket system integration configuration.

**Entity Mapping**: `App\Entity\TicketSystem`

**Properties**:
```php
public ?int $id = null                   // System ID (not mapped)
public string $name = ''                 // System name (min 3 chars)
public string $type = ''                 // System type
public bool $bookTime = false            // Time booking capability
public string $url = ''                  // System URL
public string $login = ''                // Login credentials
public string $password = ''             // Password credentials
public string $publicKey = ''            // Public key for auth
public string $privateKey = ''           // Private key for auth
public string $ticketUrl = ''            // Ticket URL template
public ?string $oauthConsumerKey = null  // OAuth consumer key
public ?string $oauthConsumerSecret = null  // OAuth consumer secret
```

**Validation Constraints**:
- `name`: NotBlank, Length min 3, UniqueTicketSystemName (custom)

**Usage Context**:
- **Controller**: Ticket system management endpoints
- **HTTP Method**: POST

**Example JSON Payload**:
```json
{
  "id": 1,
  "name": "Company JIRA",
  "type": "jira",
  "bookTime": true,
  "url": "https://company.atlassian.net",
  "login": "api-user",
  "password": "api-token",
  "publicKey": "",
  "privateKey": "",
  "ticketUrl": "https://company.atlassian.net/browse/{ticket}",
  "oauthConsumerKey": "oauth-key",
  "oauthConsumerSecret": "oauth-secret"
}
```

---

## Query and Filter DTOs

### 11. InterpretationFiltersDto

**Purpose**: Complex filtering for time tracking reports and data interpretation.

**Properties**:
```php
public ?int $customer = null             // Customer filter
public ?int $customer_id = null          // Customer filter (legacy alias)
public ?int $project = null              // Project filter
public ?int $project_id = null           // Project filter (legacy alias)
public ?int $user = null                 // User filter
public ?int $activity = null             // Activity filter
public ?int $activity_id = null          // Activity filter (legacy alias)
public ?int $team = null                 // Team filter
public ?string $ticket = null            // Ticket reference filter
public ?string $description = null       // Description search
public ?string $datestart = null         // Start date filter
public ?string $dateend = null           // End date filter
public ?string $year = null              // Year filter
public ?string $month = null             // Month filter
public ?int $maxResults = null           // Result limit
public ?int $page = null                 // Pagination
```

**Usage Context**:
- **Controller**: Reporting and interpretation endpoints
- **HTTP Method**: GET (query parameters)

**Example Query String**:
```
/interpretation?customer=5&project=12&datestart=2024-09-01&dateend=2024-09-30&maxResults=100&page=1
```

**Static Factory**: `fromRequest(Request $request)` with safe type conversion
**Helper Method**: `toFilterArray(?int $visibilityUserId, ?int $overrideMaxResults)` - Converts to repository filter format with legacy field fallback

---

### 12. ExportQueryDto

**Purpose**: Export parameter configuration for data export operations.

**Properties**:
```php
public int $userid = 0                   // Target user ID
public int $year = 0                     // Export year
public int $month = 0                    // Export month
public int $project = 0                  // Project filter
public int $customer = 0                 // Customer filter
public bool $billable = false            // Include billable entries only
public bool $tickettitles = false        // Include ticket titles
```

**Usage Context**:
- **Controller**: Data export endpoints
- **HTTP Method**: GET (query parameters)

**Example Query String**:
```
/export?userid=8&year=2024&month=9&customer=5&billable=true&tickettitles=true
```

**Static Factory**: `fromRequest(Request $request)` with safe type conversion and boolean parsing

---

## Utility DTOs

### 13. DatabaseResultDto

**Purpose**: Type-safe transformation of mixed database results to structured data.

**Static Methods**:
- `transformEntryRow(array $row): array` - Converts raw DB entry data to typed array
- `transformScopeRow(array $row, string $scope): array` - Converts scope data with metadata
- `safeDateTime(mixed $value, string $default): string` - DateTime validation and conversion

**Usage Context**:
- **Internal**: Repository and service layer data transformation
- **Purpose**: Ensures type safety when working with raw database results

**Example Usage**:
```php
$rawData = $database->fetch();
$typedEntry = DatabaseResultDto::transformEntryRow($rawData);
// Returns: ['id' => int, 'date' => string, 'start' => string, ...]
```

---

### 14. AdminSyncDto

**Purpose**: Simple parameter wrapper for administrative synchronization operations.

**Properties**:
```php
public int $project = 0                  // Project ID for sync operation
```

**Usage Context**:
- **Controller**: Admin synchronization endpoints (JIRA sync)
- **HTTP Method**: GET

**Example Query String**:
```
/admin/sync?project=12
```

---

### 15. IdDto

**Purpose**: Simple ID wrapper for operations requiring only an entity identifier.

**Properties**:
```php
public int $id = 0                       // Entity ID
```

**Usage Context**:
- **Controller**: Delete operations, simple lookups
- **HTTP Method**: POST/DELETE

**Example JSON Payload**:
```json
{
  "id": 42
}
```

---

## Validation Patterns

### Standard Symfony Constraints

| Constraint | Usage | Purpose |
|------------|-------|---------|
| `NotBlank` | Names, required fields | Ensures non-empty values |
| `Length` | Names, descriptions | String length validation |
| `Positive` | IDs, factors | Ensures positive numeric values |
| `GreaterThanOrEqual` | Factors, hours | Numeric range validation |
| `Date/Time` | Date/time strings | Format validation |
| `Regex` | Tickets, JIRA IDs | Pattern matching |

### Custom Validation Constraints

| Constraint | DTOs | Purpose |
|------------|------|---------|
| `UniqueUsername` | UserSaveDto | Username uniqueness |
| `UniqueUserAbbr` | UserSaveDto | User abbreviation uniqueness |
| `UniqueCustomerName` | CustomerSaveDto | Customer name uniqueness |
| `UniqueProjectNameForCustomer` | ProjectSaveDto | Project name per customer |
| `UniqueActivityName` | ActivitySaveDto | Activity name uniqueness |
| `UniqueTeamName` | TeamSaveDto | Team name uniqueness |
| `UniqueTicketSystemName` | TicketSystemSaveDto | Ticket system name uniqueness |
| `CustomerTeamsRequired` | CustomerSaveDto | Team assignment validation |
| `ContractDatesValid` | ContractSaveDto | Date range validation |
| `ValidUser` | ContractSaveDto | User existence validation |

### Cross-Field Validation

Several DTOs implement custom validation callbacks:

**Time Range Validation**:
```php
#[Assert\Callback]
public function validateTimeRange(ExecutionContextInterface $context): void
{
    if ($this->start >= $this->end) {
        $context->buildViolation('Start time must be before end time')
            ->atPath('end')
            ->addViolation();
    }
}
```

**Team Assignment Validation**:
```php
#[Assert\Callback]
public function validateTeams(ExecutionContextInterface $context): void
{
    if (empty($this->teams)) {
        $context->buildViolation('Every user must belong to at least one team')
            ->atPath('teams')
            ->addViolation();
    }
}
```

---

## Usage Examples

### Controller Integration

**Automatic Request Mapping**:
```php
public function saveEntry(#[MapRequestPayload] EntrySaveDto $dto): Response
{
    // DTO is automatically validated and populated from request
    $entry = $this->entityManager->find(Entry::class, $dto->id);
    // ... business logic
}
```

**Manual Request Handling**:
```php
public function bulkEntry(Request $request): Response
{
    $dto = new BulkEntryDto(
        preset: (int) $request->request->get('preset'),
        startdate: (string) $request->request->get('startdate'),
        // ...
    );

    $violations = $this->validator->validate($dto);
    if (count($violations) > 0) {
        // Handle validation errors
    }
}
```

### Error Response Format

Validation errors follow Symfony's standard format:

```json
{
  "type": "validation_error",
  "title": "Validation Failed",
  "violations": [
    {
      "propertyPath": "name",
      "message": "Please provide a valid customer name with at least 3 letters.",
      "code": "9ff3fdc4-b214-49db-8718-39c315e33d45"
    }
  ]
}
```

### Legacy Field Support

Many DTOs support both new and legacy field naming:

```php
// Both formats accepted:
{
  "project_id": 10,    // New format
  "customer_id": 5     // New format
}

{
  "project": 10,       // Legacy format
  "customer": 5        // Legacy format
}

// Access via helper methods:
$projectId = $dto->getProjectId(); // Returns project_id ?? project
```

---

## Best Practices

### DTO Design Principles

1. **Immutability**: All DTOs use `readonly` properties
2. **Type Safety**: Strict typing with appropriate defaults
3. **Validation First**: Comprehensive constraint validation
4. **Legacy Support**: Backward compatibility through helper methods
5. **Single Responsibility**: Each DTO serves a specific use case

### Validation Strategy

1. **Field-Level**: Use Symfony constraints for individual properties
2. **Cross-Field**: Implement `#[Assert\Callback]` for complex validation
3. **Business Rules**: Custom constraints for domain-specific validation
4. **Error Context**: Provide clear, actionable error messages

### ObjectMapper Integration

1. **Selective Mapping**: Use `#[Map(if: false)]` to exclude properties
2. **Entity Targeting**: Specify target entities with `#[Map(target: Entity::class)]`
3. **Manual Handling**: Exclude complex relationships from automatic mapping

This comprehensive documentation provides developers with complete understanding of the DTO layer, enabling efficient and consistent API development while maintaining data integrity and validation standards.