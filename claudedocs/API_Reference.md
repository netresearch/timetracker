# TimeTracker API Reference

## Overview

The TimeTracker application provides a comprehensive REST API for time tracking management, built on Symfony 6.4 with the following architectural patterns:

- **Action-Based Controllers**: Single-responsibility actions using `#[Route]` attributes
- **DTO Validation**: Request/response data transfer objects with Symfony validation
- **Role-Based Security**: PL (Project Lead) and DEV (Developer) user types with access control
- **JSON/CSV/XLSX Responses**: Multiple output formats for different use cases
- **Jira Integration**: OAuth-based synchronization with external ticket systems

## Authentication & Security

### Authentication Method
- **Form-based authentication** with session management
- **LDAP integration** through custom authenticator
- **Remember Me** functionality (30-day sessions)
- **User impersonation** for admin users with `simulateUserId` parameter

### Authorization Patterns
```php
// Project Lead access required
if (false === $this->isPl($request)) {
    return $this->getFailedAuthorizationResponse();
}

// General authentication check
if (!$this->checkLogin($request)) {
    return $this->getFailedLoginResponse();
}

// User type validation
$this->hasUserType($request, UserType::PL)
$this->isDEV($request)
```

### Security Headers
- **CSRF Protection**: Enabled for logout operations
- **HTTPS Required**: For remember-me functionality
- **Session Security**: Automatic session invalidation on logout

## API Endpoints by Domain

### 1. Authentication & Session Management

#### POST /login_check
**Purpose**: Process login credentials
- **Method**: POST
- **Authentication**: None (public)
- **Request**: Form data (username, password)
- **Response**: Session establishment or error
- **Error Codes**: 401 (Unauthorized)

#### GET /logout
**Purpose**: Terminate user session
- **Method**: GET  
- **Authentication**: Required
- **Response**: Redirect to login page
- **CSRF**: Protected with token

#### GET /
**Purpose**: Main application dashboard
- **Method**: GET
- **Authentication**: Required
- **Response**: HTML template with user data
- **Template**: `index.html.twig`
- **Data**: customers, projects, settings, global config

### 2. Admin Endpoints (Project Lead Only)

#### Customer Management

##### POST /customer/save
**Purpose**: Create or update customer records
- **Method**: POST
- **Authentication**: PL role required
- **Request DTO**: `CustomerSaveDto`
```json
{
  "id": 0,                    // 0 for new customer
  "name": "string",           // min 3 chars, unique validation
  "active": true,
  "global": false,
  "teams": [1, 2, 3]         // array of team IDs
}
```
- **Validation Rules**:
  - Name: NotBlank, Length(min=3), UniqueCustomerName
  - Teams: Required if not global, must exist in database
- **Response**: `JsonResponse` with customer data
- **Error Codes**: 
  - 403 (Forbidden) - Non-PL access
  - 404 (Not Found) - Customer not found for update
  - 406 (Not Acceptable) - Validation errors

##### Similar patterns for:
- **Project Management**: `/project/save` (POST)
- **Team Management**: `/team/save` (POST)  
- **User Management**: `/user/save` (POST)
- **Activity Management**: `/activity/save` (POST)
- **Contract Management**: `/contract/save` (POST)
- **Preset Management**: `/preset/save` (POST)
- **Ticket System Management**: `/ticketsystem/save` (POST)

#### Admin Data Retrieval

##### GET /admin/customers
**Purpose**: Retrieve all customers for admin interface
- **Method**: GET
- **Authentication**: PL role required
- **Response**: JSON array of customer objects
- **Pagination**: None (returns all)

##### Similar retrieval endpoints:
- `GET /admin/teams` - All teams
- `GET /admin/users` - All users  
- `GET /admin/contracts` - All contracts
- `GET /admin/presets` - All presets
- `GET /admin/ticketsystems` - All ticket systems

#### Admin Deletion

##### DELETE /admin/customer/{id}
**Purpose**: Delete customer by ID
- **Method**: DELETE
- **Authentication**: PL role required
- **Parameters**: Customer ID in URL
- **Response**: Success/error message
- **Validation**: Checks for dependencies

##### Similar deletion endpoints:
- `DELETE /admin/project/{id}` - Delete project
- `DELETE /admin/team/{id}` - Delete team
- `DELETE /admin/user/{id}` - Delete user
- `DELETE /admin/activity/{id}` - Delete activity

### 3. Time Tracking Endpoints

#### Single Entry Management

##### POST /tracking/save
**Purpose**: Create or update individual time entry
- **Method**: POST
- **Authentication**: Required
- **Request DTO**: `EntrySaveDto`
```json
{
  "id": null,                 // null for new entry
  "date": "2024-01-15",       // Y-m-d format
  "start": "09:00:00",        // H:i:s format
  "end": "17:00:00",          // H:i:s format
  "ticket": "PROJ-123",       // optional, validated format
  "description": "Work description", // max 1000 chars
  "project_id": 1,            // or legacy "project"
  "customer_id": 1,           // or legacy "customer"  
  "activity_id": 1            // or legacy "activity"
}
```
- **Validation Rules**:
  - Date: NotBlank, Date format
  - Start/End: NotBlank, Time format, start < end
  - Ticket: Length(max=50), Regex pattern, project prefix validation
  - Description: Length(max=1000)
  - IDs: Positive integers, must exist in database
- **Response**: JSON with calculated duration and formatted data
- **Error Codes**:
  - 400 (Bad Request) - Validation errors, invalid entities
  - 500 (Internal Server Error) - Database errors

##### DELETE /tracking/entry/{id}
**Purpose**: Delete time entry
- **Method**: DELETE
- **Authentication**: Required (owner only)
- **Parameters**: Entry ID in URL
- **Validation**: User ownership check
- **Response**: Success/error message

#### Bulk Entry Creation

##### POST /tracking/bulkentry
**Purpose**: Create multiple entries from date range and preset
- **Method**: POST
- **Authentication**: Required
- **Request DTO**: `BulkEntryDto`
```json
{
  "preset": 1,                // preset ID (required)
  "startdate": "2024-01-01",  // start of date range
  "enddate": "2024-01-31",    // end of date range
  "starttime": "09:00:00",    // daily start time
  "endtime": "17:00:00",      // daily end time
  "usecontract": 0,           // 1 to use contract hours
  "skipweekend": 1,           // 1 to skip weekends
  "skipholidays": 1           // 1 to skip holidays
}
```
- **Business Logic**:
  - Creates entries for each day in range
  - Skips weekends/holidays if requested
  - Uses contract working hours if specified
  - Hard-coded German holidays support
  - Maximum 100 iterations for safety
- **Response**: HTML message with count of created entries
- **Error Codes**: 422 (Unprocessable Entity) - Validation or creation errors

### 4. Data Retrieval Endpoints

#### Basic Data Access

##### GET /getCustomers
**Purpose**: Get customers accessible to current user
- **Method**: GET
- **Authentication**: Required
- **Response**: JSON array of customer objects with team filtering
- **Access Control**: Users see only customers from their teams

##### GET /getProjects
**Purpose**: Get projects for specified customer
- **Method**: GET
- **Authentication**: Required
- **Query Parameters**: `customer` (customer ID)
- **Response**: JSON array of project objects
- **Validation**: Customer access verification

##### GET /getActivities  
**Purpose**: Get all available activities
- **Method**: GET
- **Authentication**: Required
- **Response**: JSON array of activity objects
- **Caching**: Can be cached client-side

#### Time Entry Retrieval

##### GET|POST /getData
**Purpose**: Get recent time entries for current user
- **Methods**: GET, POST
- **Authentication**: Required
- **Default**: Last 3 days of entries
- **Response**: JSON with entries, pagination metadata
- **Sorting**: Most recent first

##### GET /getData/days/{days}
**Purpose**: Get entries for specific number of recent days
- **Method**: GET
- **Authentication**: Required
- **Parameters**: `days` (default: 3, used in URL path)
- **Response**: JSON array of time entries
- **Limit**: Configurable day range

##### GET /getHolidays
**Purpose**: Get holiday dates for calendar display
- **Method**: GET
- **Authentication**: Required
- **Response**: JSON array of holiday dates
- **Format**: Standard date objects

### 5. Interpretation & Analytics Endpoints

All interpretation endpoints require login and use common filtering:

#### Common Query Parameters
```
customer=1              // filter by customer ID
project=1               // filter by project ID  
user=1                  // filter by user ID
activity=1              // filter by activity ID
team=1                  // filter by team ID
ticket=PROJ-123         // filter by ticket number
description=text        // filter by description content
datestart=2024-01-01    // start date filter
dateend=2024-01-31      // end date filter
year=2024               // year filter
month=01                // month filter (requires year)
maxResults=100          // limit results
page=1                  // pagination
```

#### Analytics Endpoints

##### GET /interpretation/activity
**Purpose**: Time summary grouped by activity
- **Method**: GET
- **Authentication**: Required
- **Filters**: All common filters supported
- **Response**: JSON array with activity breakdown
```json
[
  {
    "id": 1,
    "name": "Development",
    "hours": 24.5,
    "quota": "45.2%"
  }
]
```

##### GET /interpretation/customer
**Purpose**: Time summary grouped by customer
- **Similar to activity grouping**

##### GET /interpretation/project  
**Purpose**: Time summary grouped by project
- **Similar to activity grouping**

##### GET /interpretation/user
**Purpose**: Time summary grouped by user
- **Access Control**: DEV users see only their own data

##### GET /interpretation/ticket
**Purpose**: Time summary grouped by ticket
- **Useful for project tracking**

##### GET /interpretation/worktime
**Purpose**: Time summary grouped by working time patterns
- **Shows daily/weekly patterns**

##### GET /interpretation/entries
**Purpose**: Raw entry data with filtering
- **Method**: GET
- **Authentication**: Required
- **Filters**: All common filters
- **Response**: Paginated entry list with full details
- **Access Control**: DEV users filtered by user ID

### 6. Export Endpoints

#### CSV Export

##### GET /export/{days}
**Purpose**: Export user's time entries as CSV
- **Method**: GET
- **Authentication**: Required
- **Parameters**: `days` (default: 10000, unlimited)
- **Response**: CSV file download
- **Headers**: 
  - `Content-Type: text/csv; charset=utf-8`
  - `Content-disposition: attachment;filename=username.csv`
- **Format**: UTF-8 with BOM, twig template rendered

#### Summary Export

##### POST /getSummary
**Purpose**: Get time summary for export
- **Method**: POST
- **Authentication**: Required
- **Request**: Filter parameters in POST body
- **Response**: JSON summary data suitable for export
- **Formats**: Can be used for CSV, Excel, PDF generation

### 7. Integration Endpoints

#### Jira OAuth Integration

##### GET /jiraoauthcallback
**Purpose**: Handle Jira OAuth callback after user authorization
- **Method**: GET  
- **Authentication**: Required
- **Query Parameters**:
  - `oauth_token` - OAuth token from Jira
  - `oauth_verifier` - OAuth verifier from Jira  
  - `tsid` - Ticket System ID
- **Process**:
  1. Validates OAuth parameters
  2. Exchanges tokens for access token
  3. Initiates limited worklog sync
  4. Redirects to dashboard
- **Error Handling**: Returns error response for invalid OAuth flow
- **Response**: Redirect to dashboard or error message

#### Admin Sync Operations

##### GET /syncentries/jira
**Purpose**: Manually trigger Jira entry synchronization
- **Method**: GET
- **Authentication**: PL role required
- **Query Parameters**:
  - `from` - Start date for sync (default: -3 days)
  - `to` - End date for sync (default: now)
- **Process**: Updates time entries with Jira worklog data
- **Response**: JSON success/failure status
- **Access Control**: Only Project Leads can trigger sync

##### POST /admin/sync/project/{projectId}/subtickets
**Purpose**: Sync project subtickets from Jira
- **Method**: POST
- **Authentication**: PL role required
- **Parameters**: Project ID in URL
- **Response**: JSON with sync results

##### POST /admin/sync/all-projects/subtickets
**Purpose**: Sync subtickets for all projects
- **Method**: POST
- **Authentication**: PL role required
- **Process**: Bulk synchronization across all active projects
- **Response**: JSON with batch sync results

### 8. Settings & Configuration

#### User Settings

##### POST /settings/save
**Purpose**: Save user preferences and configuration
- **Method**: POST
- **Authentication**: Required
- **Request**: User settings data (locale, preferences, etc.)
- **Validation**: Settings format and value validation
- **Response**: Success/error status
- **Persistence**: Stored in user entity settings field

### 9. Status & Health Endpoints

#### System Health

##### GET /status/check
**Purpose**: API health check endpoint
- **Method**: GET
- **Authentication**: None (public)
- **Response**: System status information
- **Use Case**: Load balancer health checks, monitoring

##### GET /status/page
**Purpose**: Status page for system monitoring
- **Method**: GET  
- **Authentication**: None (public)
- **Response**: HTML status page
- **Information**: System version, database connectivity, etc.

## Data Transfer Objects (DTOs)

### Request DTOs

#### CustomerSaveDto
```php
readonly class CustomerSaveDto {
    public int $id = 0;
    #[Assert\NotBlank, Assert\Length(min: 3), UniqueCustomerName]
    public string $name = '';
    public bool $active = false;
    public bool $global = false;
    public array $teams = [];
}
```

#### EntrySaveDto  
```php
readonly class EntrySaveDto {
    public ?int $id = null;
    #[Assert\NotBlank, Assert\Date]
    public string $date = '';
    #[Assert\NotBlank, Assert\Time]
    public string $start = '00:00:00';
    #[Assert\NotBlank, Assert\Time]  
    public string $end = '00:00:00';
    #[Assert\Length(max: 50), Assert\Regex('/^[A-Z0-9\-_]*$/i')]
    public string $ticket = '';
    #[Assert\Length(max: 1000)]
    public string $description = '';
    // Supports both new (project_id) and legacy (project) field names
    public ?int $project_id = null;
    public ?int $customer_id = null;  
    public ?int $activity_id = null;
}
```

#### BulkEntryDto
```php
readonly class BulkEntryDto {
    #[Assert\NotBlank, Assert\Positive]
    public int $preset = 0;
    public string $startdate = '';
    public string $enddate = '';
    public string $starttime = '';
    public string $endtime = '';
    public int $usecontract = 0;    // boolean as int
    public int $skipweekend = 0;    // boolean as int 
    public int $skipholidays = 0;   // boolean as int
}
```

#### InterpretationFiltersDto
```php
readonly class InterpretationFiltersDto {
    // Supports both new and legacy field naming
    public ?int $customer = null;
    public ?int $customer_id = null;  // legacy alias
    public ?int $project = null;
    public ?int $project_id = null;   // legacy alias
    public ?int $user = null;
    public ?int $activity = null;
    public ?int $activity_id = null;  // legacy alias
    public ?int $team = null;
    public ?string $ticket = null;
    public ?string $description = null;
    public ?string $datestart = null;
    public ?string $dateend = null;
    public ?string $year = null;
    public ?string $month = null;
    public ?int $maxResults = null;
    public ?int $page = null;
}
```

### Response Formats

#### JSON Response Pattern
```json
{
  "result": {
    "date": "15/01/2024",
    "start": "09:00", 
    "end": "17:00",
    "user": 1,
    "customer": 1,
    "project": 1,
    "activity": 1,
    "duration": 480,                    // minutes
    "durationString": "08:00",         // formatted hours:minutes
    "class": "daybreak",               // entry classification
    "ticket": "PROJ-123",              // optional
    "description": "Work description"   // optional
  }
}
```

#### Error Response Pattern
```json
{
  "error": "Error message",
  "code": 400
}
```
OR plain text response with appropriate HTTP status code.

#### CSV Export Format
```csv
Date,Start,End,Duration,Customer,Project,Activity,Ticket,Description
15/01/2024,09:00,17:00,08:00,Customer Name,Project Name,Activity Name,PROJ-123,Work description
```

## API Patterns & Conventions

### Route Naming Convention
- **Legacy routes**: `_actionName_attr` (e.g., `_getCustomers_attr`)
- **New routes**: `domain_action_attr` (e.g., `timetracking_save_attr`)
- **Admin routes**: `adminAction_attr` (e.g., `saveCustomer_attr`)

### HTTP Methods
- **GET**: Data retrieval, exports, status checks
- **POST**: Create/update operations, complex queries with filters
- **DELETE**: Resource deletion (limited use)

### Authentication Patterns
```php
// Standard login check
if (!$this->checkLogin($request)) {
    return $this->getFailedLoginResponse();
}

// Role-based authorization  
if (false === $this->isPl($request)) {
    return $this->getFailedAuthorizationResponse();
}

// User ID extraction with fallback
$userId = $this->getUserId($request);
```

### Error Response Patterns
- **401 Unauthorized**: Login required
- **403 Forbidden**: Insufficient permissions
- **400 Bad Request**: Validation errors, invalid data
- **404 Not Found**: Resource not found
- **406 Not Acceptable**: Business logic validation failures
- **422 Unprocessable Entity**: DTO validation failures
- **500 Internal Server Error**: Database or system errors

### Request/Response Content Types
- **Application/JSON**: Primary API format
- **Text/CSV**: Export format with UTF-8 BOM
- **Text/HTML**: Twig template responses
- **Application/x-www-form-urlencoded**: Form submissions

### Security Middleware
- **Access Control**: Path-based rules in `security.yaml`
- **CSRF Protection**: Enabled for state-changing operations
- **Role Hierarchy**: `ROLE_ADMIN` inherits `ROLE_USER`
- **User Switching**: Available for `ROLE_ALLOWED_TO_SWITCH`

### Pagination & Filtering
- **Default Limits**: Reasonable defaults per endpoint
- **Filter Validation**: Type conversion with null handling
- **Legacy Support**: Multiple field naming conventions supported
- **Access Control**: DEV users filtered to own data automatically

This API provides comprehensive time tracking functionality with strong security, validation, and integration capabilities while maintaining backward compatibility with legacy clients.