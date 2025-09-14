# TimeTracker API Reference

This document provides comprehensive reference documentation for all API endpoints in the TimeTracker application.

## Table of Contents

1. [API Overview](#api-overview)
2. [Authentication & Authorization](#authentication--authorization)
3. [Entry Tracking APIs](#entry-tracking-apis)
4. [Administrative APIs](#administrative-apis)
5. [Data Retrieval APIs](#data-retrieval-apis)
6. [Interpretation & Reporting APIs](#interpretation--reporting-apis)
7. [System Status APIs](#system-status-apis)
8. [Configuration APIs](#configuration-apis)
9. [Error Handling](#error-handling)
10. [Response Formats](#response-formats)

---

## API Overview

### Base URL Patterns
The TimeTracker application provides RESTful endpoints organized by functional domains:

- **Entry Tracking**: `/tracking/*` - Time entry operations
- **Administration**: `/admin/*` - Resource management (PL users only)
- **Data Access**: `/get*` - Data retrieval operations
- **Interpretation**: `/interpretation/*` - Reporting and analysis
- **Status**: `/status/*` - System health checks
- **Configuration**: `/settings/*` - User preferences

### Content Types
- **Request**: `application/json` (for POST requests with DTOs)
- **Response**: `application/json` (standard), `text/csv` (exports)
- **Form Data**: `application/x-www-form-urlencoded` (legacy endpoints)

### Common Response Formats
- **Success**: JSON with data or confirmation message
- **Error**: JSON with error message and appropriate HTTP status code
- **Pagination**: Links object with `first`, `last`, `next`, `prev` URLs

---

## Authentication & Authorization

### Authentication Methods
- **Session-based**: Cookie authentication via Symfony Security
- **LDAP Integration**: Automatic user creation on successful LDAP authentication

### Authorization Levels
- **Authenticated User**: Access to own data and basic operations
- **Project Leader (PL)**: Administrative access to resource management
- **Developer (DEV)**: Special access permissions for development operations

### Protected Endpoints
All endpoints require authentication except:
- `POST /login` - Authentication endpoint
- `GET /status/check` - Health check (returns login status)

---

## Entry Tracking APIs

### POST /tracking/save
**Purpose**: Create or update time entries

**Authentication**: Required

**Request Body** (EntrySaveDto):
```json
{
  "id": null,                    // Entry ID for updates (null for new)
  "date": "2024-09-14",         // Date in Y-m-d format (required)
  "start": "09:00:00",          // Start time in H:i:s format (required)
  "end": "17:30:00",            // End time in H:i:s format (required)
  "ticket": "PROJ-456",         // Ticket reference (max 50 chars)
  "description": "Work done",    // Description (max 1000 chars)
  "project_id": 10,             // Project ID (required)
  "customer_id": 5,             // Customer ID (required)
  "activity_id": 2,             // Activity ID (required)
  "extTicket": "EXT-789"        // External ticket reference
}
```

**Validation Rules**:
- `date`, `start`, `end` are required
- Start time must be before end time
- Ticket format: alphanumeric with hyphens/underscores
- All referenced entities must exist and be active

**Response (200 OK)**:
```json
{
  "result": {
    "date": "14/09/2024",
    "start": "09:00",
    "end": "17:30",
    "user": 123,
    "customer": 5,
    "project": 10,
    "activity": 2,
    "duration": 510,              // Duration in minutes
    "durationString": "08:30",
    "class": "DAYBREAK",
    "ticket": "PROJ-456",
    "description": "Work done"
  }
}
```

**Errors**:
- `400 Bad Request`: Invalid data, missing required fields, time validation errors
- `401 Unauthorized`: Not authenticated
- `500 Internal Server Error`: Database operation failed

---

### POST /tracking/delete
**Purpose**: Delete a time entry

**Authentication**: Required (own entries only)

**Request Body** (IdDto):
```json
{
  "id": 123
}
```

**Response (200 OK)**:
```json
{
  "message": "Entry deleted successfully"
}
```

**Errors**:
- `400 Bad Request`: Entry not found or not owned by user
- `401 Unauthorized`: Not authenticated

---

### POST /tracking/bulkentry
**Purpose**: Create multiple time entries from a preset template

**Authentication**: Required

**Request Body** (BulkEntryDto):
```json
{
  "preset": 5,                  // Preset template ID (required)
  "startdate": "2024-09-01",   // Start date for bulk creation
  "enddate": "2024-09-30",     // End date for bulk creation
  "starttime": "08:00:00",     // Default start time
  "endtime": "16:30:00",       // Default end time
  "usecontract": 1,            // Use contract hours (0/1)
  "skipweekend": 1,            // Skip weekends (0/1)
  "skipholidays": 1            // Skip holidays (0/1)
}
```

**Response (200 OK)**:
```html
25 entries have been added
```

**Errors**:
- `422 Unprocessable Entity`: Validation errors, missing preset, no contract found
- `401 Unauthorized`: Not authenticated

---

## Administrative APIs

*Note: All administrative endpoints require Project Leader (PL) authorization.*

### Customer Management

#### GET /getAllCustomers
**Purpose**: Retrieve all customers

**Authentication**: Required

**Response (200 OK)**:
```json
[
  {
    "id": 1,
    "name": "Customer Name",
    "active": true,
    "global": false,
    "teams": [1, 2, 3]
  }
]
```

#### POST /customer/save
**Purpose**: Create or update customer

**Authentication**: Required (PL only)

**Request Body** (CustomerSaveDto):
```json
{
  "id": 0,                     // 0 for new customer
  "name": "New Customer",      // Required, max 100 chars
  "active": true,              // Required
  "global": false,             // Required
  "teams": [1, 2, 3]          // Team IDs (required if not global)
}
```

**Validation Rules**:
- Non-global customers must belong to at least one team
- Team IDs must exist in database
- Name is required and unique

**Response (200 OK)**:
```json
[1, "New Customer", true, false, [1, 2, 3]]
```

**Errors**:
- `403 Forbidden`: Not a project leader
- `404 Not Found`: Customer not found (for updates)
- `406 Not Acceptable`: Validation errors, missing teams

#### POST /customer/delete
**Purpose**: Delete customer

**Request Body** (IdDto):
```json
{
  "id": 123
}
```

**Response (200 OK)**:
```json
{
  "message": "Customer deleted successfully"
}
```

### Project Management

#### POST /project/save
**Purpose**: Create or update project

**Request Body** (ProjectSaveDto):
```json
{
  "id": 0,
  "name": "New Project",       // Required, max 100 chars
  "active": true,              // Required
  "global": false,             // Required
  "customer_id": 5,            // Required, must exist
  "jira_id": "PROJ",           // Optional, Jira project key
  "jira_login": "user@example.com", // Optional
  "jira_password": "secret",   // Optional
  "teams": [1, 2]              // Required if not global
}
```

**Response (200 OK)**:
```json
[1, "New Project", true, false, 5, "PROJ", [1, 2]]
```

#### POST /project/delete
**Purpose**: Delete project

**Request Body** (IdDto)

### Team Management

#### GET /getAllTeams
**Purpose**: Retrieve all teams

**Response (200 OK)**:
```json
[
  {
    "id": 1,
    "name": "Development Team",
    "active": true
  }
]
```

#### POST /team/save
**Purpose**: Create or update team

**Request Body** (TeamSaveDto):
```json
{
  "id": 0,
  "name": "New Team",          // Required, max 100 chars
  "active": true               // Required
}
```

#### POST /team/delete
**Purpose**: Delete team

### Activity Management

#### POST /activity/save
**Purpose**: Create or update activity

**Request Body** (ActivitySaveDto):
```json
{
  "id": 0,
  "name": "Development",       // Required, max 100 chars
  "factor": 1.0,              // Billing factor
  "active": true,             // Required
  "teams": [1, 2]             // Team assignments
}
```

#### POST /activity/delete
**Purpose**: Delete activity

### User Management

#### GET /getAllUsers
**Purpose**: Retrieve all users

**Response (200 OK)**:
```json
[
  {
    "id": 1,
    "username": "john.doe",
    "email": "john@example.com",
    "type": "DEV",              // UserType: DEV, PL
    "active": true,
    "teams": [1, 2]
  }
]
```

#### POST /user/save
**Purpose**: Create or update user

**Request Body** (UserSaveDto):
```json
{
  "id": 0,
  "username": "new.user",      // Required, unique
  "email": "new@example.com",  // Required, valid email
  "type": "DEV",               // Required: DEV or PL
  "active": true,              // Required
  "teams": [1, 2],             // Team assignments
  "showFuture": false          // Show future dates in UI
}
```

#### POST /user/delete
**Purpose**: Delete user

### Preset Management

#### GET /getAllPresets
**Purpose**: Retrieve all presets for current user

**Response (200 OK)**:
```json
[
  {
    "id": 1,
    "name": "Daily Standup",
    "customer_id": 5,
    "project_id": 10,
    "activity_id": 2,
    "description": "Daily standup meeting"
  }
]
```

#### POST /preset/save
**Purpose**: Create or update preset template

**Request Body** (PresetSaveDto):
```json
{
  "id": 0,
  "name": "Meeting Template",  // Required
  "customer_id": 5,           // Required
  "project_id": 10,           // Required
  "activity_id": 2,           // Required
  "description": "Template for meetings"
}
```

#### POST /preset/delete
**Purpose**: Delete preset

### Contract Management

#### GET /getContracts
**Purpose**: Retrieve user contracts

**Response (200 OK)**:
```json
[
  {
    "id": 1,
    "user_id": 123,
    "start": "2024-01-01",
    "end": "2024-12-31",
    "hours0": 0.0,              // Sunday hours
    "hours1": 8.0,              // Monday hours
    "hours2": 8.0,              // Tuesday hours
    "hours3": 8.0,              // Wednesday hours
    "hours4": 8.0,              // Thursday hours
    "hours5": 8.0,              // Friday hours
    "hours6": 0.0               // Saturday hours
  }
]
```

#### POST /contract/save
**Purpose**: Create or update contract

**Request Body** (ContractSaveDto):
```json
{
  "id": 0,
  "user_id": 123,             // Required
  "start": "2024-01-01",      // Required, date format
  "end": "2024-12-31",        // Optional, date format
  "hours0": 0.0,              // Hours for each day of week
  "hours1": 8.0,
  "hours2": 8.0,
  "hours3": 8.0,
  "hours4": 8.0,
  "hours5": 8.0,
  "hours6": 0.0
}
```

#### POST /contract/delete
**Purpose**: Delete contract

### Ticket System Management

#### GET /getTicketSystems
**Purpose**: Retrieve configured ticket systems

**Response (200 OK)**:
```json
[
  {
    "id": 1,
    "name": "Jira Production",
    "type": "jira",
    "url": "https://company.atlassian.net",
    "active": true
  }
]
```

#### POST /ticketsystem/save
**Purpose**: Create or update ticket system

**Request Body** (TicketSystemSaveDto):
```json
{
  "id": 0,
  "name": "New Jira Instance", // Required
  "type": "jira",              // Required
  "url": "https://jira.company.com", // Required, valid URL
  "active": true               // Required
}
```

#### POST /ticketsystem/delete
**Purpose**: Delete ticket system

### Synchronization Operations

#### GET /syncentries/jira
**Purpose**: Synchronize time entries with Jira

**Authentication**: Required (PL only)

**Response (200 OK)**:
```json
{
  "synchronized": 15,
  "errors": 2,
  "message": "Synchronization completed"
}
```

#### GET /projects/syncsubtickets
**Purpose**: Sync subttickets for all projects

#### GET /projects/{project}/syncsubtickets
**Purpose**: Sync subtickers for specific project

**Parameters**:
- `project`: Project ID

**Query Parameters** (AdminSyncDto):
- `project`: Project ID to sync

---

## Data Retrieval APIs

### GET /getData
**Purpose**: Retrieve user's time entries for recent days or filtered data

**Authentication**: Required

**Query Parameters**:
- `days`: Number of recent days (default: 3)
- `year`: Filter by year (triggers summary mode)
- `month`: Filter by month (requires year)
- `user`: User ID filter (for PL users, default: 0 = all)
- `customer`: Customer ID filter
- `project`: Project ID filter

**Response (200 OK)** - Recent entries:
```json
[
  {
    "id": 123,
    "date": "2024-09-14",
    "start": "09:00:00",
    "end": "17:30:00",
    "duration": 510,
    "customer": "Customer Name",
    "project": "Project Name",
    "activity": "Development",
    "description": "Work description",
    "ticket": "PROJ-456"
  }
]
```

**Response (200 OK)** - Filtered summary:
```json
{
  "totalWorkTime": 12600        // Total minutes worked
}
```

### GET /getData/days/{days}
**Purpose**: Retrieve entries for specific number of days

**Parameters**:
- `days`: Number of days to retrieve

### GET /getCustomers
**Purpose**: Retrieve customers accessible to current user

**Response (200 OK)**:
```json
[
  {
    "id": 1,
    "name": "Customer Name",
    "projects": [
      {
        "id": 10,
        "name": "Project Name",
        "active": true
      }
    ]
  }
]
```

### GET /getCustomer
**Purpose**: Retrieve single customer with projects

**Query Parameters**:
- `id`: Customer ID

### GET /getProjects
**Purpose**: Retrieve projects accessible to current user

**Response (200 OK)**:
```json
[
  {
    "id": 10,
    "name": "Project Name",
    "customer_id": 1,
    "customer_name": "Customer Name",
    "active": true,
    "jira_id": "PROJ"
  }
]
```

### GET /getAllProjects
**Purpose**: Retrieve all projects (admin view)

### GET /getProjectStructure
**Purpose**: Retrieve hierarchical customer/project structure

**Response (200 OK)**:
```json
{
  "customers": [
    {
      "id": 1,
      "name": "Customer Name",
      "projects": [
        {
          "id": 10,
          "name": "Project Name",
          "activities": [
            {
              "id": 2,
              "name": "Development"
            }
          ]
        }
      ]
    }
  ]
}
```

### GET /getActivities
**Purpose**: Retrieve activities accessible to current user

**Response (200 OK)**:
```json
[
  {
    "id": 1,
    "name": "Development",
    "factor": 1.0,
    "active": true
  }
]
```

### GET /getUsers
**Purpose**: Retrieve users in current user's teams

**Response (200 OK)**:
```json
[
  {
    "id": 1,
    "username": "john.doe",
    "email": "john@example.com",
    "active": true
  }
]
```

### GET /getHolidays
**Purpose**: Retrieve holiday calendar

**Response (200 OK)**:
```json
{
  "2024": [
    {
      "date": "2024-01-01",
      "name": "New Year's Day",
      "type": "public"
    }
  ]
}
```

### GET /getSummary
**Purpose**: Generate time summary report

**Method**: POST (legacy endpoint)

**Response (200 OK)**:
```json
{
  "summary": {
    "totalHours": 160.5,
    "totalMinutes": 9630,
    "averageDaily": 8.0,
    "workingDays": 20
  },
  "breakdown": [
    {
      "customer": "Customer Name",
      "project": "Project Name",
      "hours": 40.0,
      "percentage": 25.0
    }
  ]
}
```

### GET /getTimeSummary
**Purpose**: Get time summary for current user

**Response (200 OK)**:
```json
{
  "today": {
    "hours": 8.5,
    "minutes": 510
  },
  "week": {
    "hours": 42.0,
    "minutes": 2520
  },
  "month": {
    "hours": 168.0,
    "minutes": 10080
  }
}
```

### GET /getTicketTimeSummary/{ticket}
**Purpose**: Get time summary for specific ticket

**Parameters**:
- `ticket`: Ticket identifier (optional)

**Response (200 OK)**:
```json
{
  "ticket": "PROJ-456",
  "totalTime": 480,              // Minutes
  "entries": [
    {
      "date": "2024-09-14",
      "duration": 240,
      "description": "Implementation work"
    }
  ]
}
```

### GET /scripts/timeSummaryForJira
**Purpose**: JavaScript integration for Jira time tracking

**Response (200 OK)**:
```json
{
  "script": "// JavaScript code for Jira integration",
  "version": "1.0.0"
}
```

---

## Interpretation & Reporting APIs

*Note: All interpretation endpoints require Project Leader (PL) authorization.*

### POST /interpretation/allEntries
**Purpose**: Retrieve paginated entries with filtering

**Authentication**: Required (PL only)

**Query Parameters** (InterpretationFiltersDto):
- `page`: Page number (default: 1)
- `limit`: Items per page (default: 25, max: 100)
- `year`: Year filter
- `month`: Month filter (1-12)
- `user`: User ID filter
- `customer`: Customer ID filter
- `project`: Project ID filter
- `activity`: Activity ID filter
- `ticket`: Ticket filter
- `description`: Description search
- `start_date`: Start date filter (YYYY-MM-DD)
- `end_date`: End date filter (YYYY-MM-DD)

**Response (200 OK)**:
```json
{
  "data": [
    {
      "id": 123,
      "date": "2024-09-14",
      "user": "john.doe",
      "customer": "Customer Name",
      "project": "Project Name",
      "activity": "Development",
      "duration": 480,
      "description": "Work description",
      "ticket": "PROJ-456"
    }
  ],
  "first": "/interpretation/allEntries?page=1",
  "last": "/interpretation/allEntries?page=10",
  "next": "/interpretation/allEntries?page=3",
  "prev": "/interpretation/allEntries?page=1",
  "total": 245,
  "page": 2,
  "limit": 25
}
```

**Errors**:
- `400 Bad Request`: Invalid page number
- `403 Forbidden`: Not a project leader
- `422 Unprocessable Entity`: Invalid date format

### GET /interpretation/entries
**Purpose**: Get recent entries for interpretation

**Authentication**: Required (PL only)

**Query Parameters**:
- `limit`: Number of entries (default: 50)

**Response (200 OK)**:
```json
[
  {
    "id": 123,
    "date": "2024-09-14",
    "user": "john.doe",
    "duration": 480,
    "description": "Recent work"
  }
]
```

### GET /interpretation/user
**Purpose**: Group entries by user

**Authentication**: Required (PL only)

**Query Parameters**: Same as allEntries

**Response (200 OK)**:
```json
[
  {
    "user": "john.doe",
    "totalHours": 160.0,
    "totalMinutes": 9600,
    "entries": 48,
    "averageDaily": 8.0
  }
]
```

### GET /interpretation/customer
**Purpose**: Group entries by customer

**Response (200 OK)**:
```json
[
  {
    "customer": "Customer Name",
    "totalHours": 240.0,
    "totalMinutes": 14400,
    "entries": 72,
    "projects": [
      {
        "project": "Project Name",
        "hours": 160.0
      }
    ]
  }
]
```

### GET /interpretation/project
**Purpose**: Group entries by project

### GET /interpretation/activity
**Purpose**: Group entries by activity

### GET /interpretation/ticket
**Purpose**: Group entries by ticket

### GET /interpretation/time
**Purpose**: Group entries by time periods

**Response (200 OK)**:
```json
[
  {
    "period": "2024-09",
    "totalHours": 168.0,
    "workingDays": 21,
    "averageDaily": 8.0,
    "users": [
      {
        "user": "john.doe",
        "hours": 168.0
      }
    ]
  }
]
```

---

## System Status APIs

### GET /status/check
**Purpose**: Health check and login status

**Authentication**: Not required

**Response (200 OK)**:
```json
{
  "loginStatus": true
}
```

### GET /status/page
**Purpose**: Status page with system information

**Authentication**: Not required

**Response (200 OK)**: HTML status page

---

## Configuration APIs

### POST /settings/save
**Purpose**: Save user preferences

**Authentication**: Required

**Request Body**:
```json
{
  "locale": "en",               // Language preference
  "showFuture": false,          // Show future dates
  "theme": "light",             // UI theme
  "timeFormat": "24h",          // Time display format
  "dateFormat": "Y-m-d"         // Date display format
}
```

**Response (200 OK)**:
```json
{
  "message": "Settings saved successfully"
}
```

---

## Export APIs

### GET /export/{days}
**Purpose**: Export time entries as CSV

**Authentication**: Required

**Parameters**:
- `days`: Number of days to export (default: 10000)

**Response (200 OK)**: CSV file
```csv
Date,Start,End,Duration,Customer,Project,Activity,Description,Ticket
2024-09-14,09:00:00,17:30:00,08:30,Customer Name,Project Name,Development,Work done,PROJ-456
```

### GET /controlling/export
**Purpose**: Administrative export with advanced filtering

**Authentication**: Required (PL only)

**Query Parameters** (ExportQueryDto):
- `userid`: User ID filter (0 = all users)
- `year`: Year filter
- `month`: Month filter
- `project`: Project ID filter
- `customer`: Customer ID filter
- `billable`: Billable filter (0/1)

**Response (200 OK)**: CSV file with administrative data

---

## Authentication Endpoints

### GET /login
**Purpose**: Display login form

**Response (200 OK)**: HTML login page

### POST /login
**Purpose**: Authenticate user

**Form Data**:
- `_username`: Username
- `_password`: Password

**Response**:
- `302 Found`: Redirect to dashboard on success
- `200 OK`: Login form with error message on failure

### GET /logout
**Purpose**: Logout current user

**Response (302 Found)**: Redirect to login page

### GET /jiraoauthcallback
**Purpose**: Handle Jira OAuth callback

**Query Parameters**:
- `oauth_token`: OAuth token
- `oauth_verifier`: OAuth verifier

---

## Error Handling

### HTTP Status Codes

#### Success Codes
- `200 OK`: Request successful, data returned
- `201 Created`: Resource created successfully
- `204 No Content`: Request successful, no data returned

#### Client Error Codes
- `400 Bad Request`: Invalid request data, validation errors
- `401 Unauthorized`: Authentication required
- `403 Forbidden`: Access denied, insufficient permissions
- `404 Not Found`: Resource not found
- `406 Not Acceptable`: Request cannot be processed
- `422 Unprocessable Entity`: Validation errors in request payload

#### Server Error Codes
- `500 Internal Server Error`: Unexpected server error

### Error Response Format

```json
{
  "error": "Error message",
  "code": 400,
  "details": {
    "field": "Specific field error",
    "validation": ["List of validation errors"]
  }
}
```

### Common Error Scenarios

1. **Authentication Errors**:
   - Missing session: `401 Unauthorized`
   - Expired session: Redirect to `/login`

2. **Authorization Errors**:
   - PL permission required: `403 Forbidden`
   - Resource ownership: `403 Forbidden`

3. **Validation Errors**:
   - Required field missing: `400 Bad Request`
   - Invalid format: `422 Unprocessable Entity`
   - Business rule violation: `406 Not Acceptable`

4. **Resource Errors**:
   - Entity not found: `404 Not Found`
   - Inactive resource: `400 Bad Request`
   - Constraint violation: `409 Conflict`

---

## Response Formats

### Standard JSON Response
```json
{
  "data": {},                   // Response payload
  "message": "Success",         // Optional message
  "timestamp": "2024-09-14T10:30:00Z"
}
```

### Paginated Response
```json
{
  "data": [],                   // Array of items
  "total": 245,                 // Total count
  "page": 2,                    // Current page
  "limit": 25,                  // Items per page
  "first": "/endpoint?page=1",  // First page URL
  "last": "/endpoint?page=10",  // Last page URL
  "next": "/endpoint?page=3",   // Next page URL
  "prev": "/endpoint?page=1"    // Previous page URL
}
```

### Collection Response
```json
[
  {
    "id": 1,
    "name": "Item 1"
  },
  {
    "id": 2,
    "name": "Item 2"
  }
]
```

### Empty Response
For successful operations without data:
```json
{
  "message": "Operation completed successfully"
}
```

---

## Rate Limiting

Currently, the TimeTracker API does not implement rate limiting. However, consider the following recommendations for high-volume usage:

- **Bulk Operations**: Use `/tracking/bulkentry` for creating multiple entries
- **Batch Requests**: Group related operations when possible
- **Pagination**: Use appropriate `limit` values for large datasets
- **Caching**: Implement client-side caching for relatively static data

---

## Integration Examples

### Creating a Time Entry
```javascript
const response = await fetch('/tracking/save', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
  },
  body: JSON.stringify({
    date: '2024-09-14',
    start: '09:00:00',
    end: '17:30:00',
    project_id: 10,
    customer_id: 5,
    activity_id: 2,
    description: 'API integration work',
    ticket: 'PROJ-456'
  })
});

const data = await response.json();
```

### Fetching User Data
```javascript
const response = await fetch('/getData?days=7');
const entries = await response.json();
```

### Administrative Operations (PL users)
```javascript
// Create customer
const customer = await fetch('/customer/save', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({
    id: 0,
    name: 'New Customer',
    active: true,
    global: false,
    teams: [1, 2]
  })
});

// Get interpretation report
const report = await fetch('/interpretation/allEntries?year=2024&month=9');
const reportData = await report.json();
```

---

This API reference provides comprehensive coverage of all TimeTracker endpoints. For specific implementation details, validation rules, and entity relationships, refer to the [DTO Documentation](./DTO_DOCUMENTATION.md) and [Controller Index](./CONTROLLER_INDEX.md).