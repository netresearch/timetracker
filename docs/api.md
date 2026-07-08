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
- **Resource Management**: `/customer/*`, `/project/*`, `/activity/*`, `/team/*`, `/user/*`, `/preset/*`, `/contract/*`, `/ticketsystem/*`, `/holiday/*` - CRUD operations (require `ROLE_ADMIN`)
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
- **Form login**: `POST /login` is handled by `App\Security\LdapAuthenticator` (custom authenticator on the `main` firewall). Credentials are validated against LDAP; on success a local `User` is created/updated (see `App\Security\UserChecker`, which refuses deactivated accounts).
- **Session-based**: Cookie session after login, plus optional "remember me" (30-day lifetime).
- **CSRF**: Stateless CSRF protection is enabled (`config/packages/framework.yaml` → `csrf_protection.stateless_token_ids: ['authenticate', 'logout']`). The login form submits `_csrf_token`, validated against the `authenticate` token id (`CsrfTokenBadge('authenticate', …)`); logout is likewise CSRF-protected via the `logout` token id.
- **Impersonation**: `switch_user` is enabled via the `simulateUserId` parameter for users holding `ROLE_ALLOWED_TO_SWITCH`.

### Authorization Levels
User types are defined in `App\Enum\UserType` (`USER`, `DEV`, `PL`, `ADMIN`; `UNKNOWN` for unconfigured) and mapped to Symfony roles in `UserType::getRoles()`:

| User type | Roles granted |
|-----------|---------------|
| `USER`, `DEV` | `ROLE_USER` |
| `PL` | `ROLE_USER`, `ROLE_PL`, `ROLE_ADMIN` (PL carries `ROLE_ADMIN` for v4 compatibility) |
| `ADMIN` | `ROLE_USER`, `ROLE_ADMIN` |

Resource-management endpoints are guarded with `#[IsGranted('ROLE_ADMIN')]`, so both `PL` and `ADMIN` users can reach them. Regular endpoints use `#[IsGranted('IS_AUTHENTICATED_FULLY')]` (or `ROLE_USER`).

### Protected Endpoints
Access control (`config/packages/security.yaml`) grants `PUBLIC_ACCESS` to:
- `GET /login` / `POST /login` - Login form and authentication
- `GET /logout` - Logout (CSRF-protected)
- `GET /status/check` - Health check (returns login status)
- `GET /status/page` - Status page
- Static assets under `/css`, `/js`, `/images`

Paths under `/admin` require `ROLE_ADMIN`; every other path requires `IS_AUTHENTICATED_FULLY`.

### Interactive API Documentation
A static OpenAPI 3.0 specification ships at `public/api.yml` (title "Time Tracker API"), with a bundled Swagger UI under `public/docs/swagger/`. The spec is not auto-generated from the controllers, so treat this document and the route attributes in `src/Controller/**` as the source of truth if the two diverge.

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

### GET /tracking/entry/{id}
**Purpose**: Fetch a single time entry by ID

**Authentication**: Required (`IS_AUTHENTICATED_FULLY`)

**Parameters**:
- `id`: Entry ID (numeric, `requirements: ['id' => '\d+']`)

**Authorization**: Users may fetch their own entries; leads (`ROLE_ADMIN`, which `PL` carries) may fetch any entry, otherwise a `403 Forbidden` is returned.

---

## Administrative APIs

*Note: All administrative endpoints are guarded with `#[IsGranted('ROLE_ADMIN')]`. Both `PL` and `ADMIN` user types carry `ROLE_ADMIN` (see [Authorization Levels](#authorization-levels)).*

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

**Authentication**: Required (`ROLE_ADMIN`)

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
    "type": "DEV",              // UserType value: USER, DEV, PL, ADMIN
    "abbr": "JDO",
    "abbr_duplicate": false,
    "locale": "en",
    "teams": [1, 2],
    "active": true,
    "last_activity": "2026-07-01 09:12:00"
  }
]
```

#### POST /user/save
**Purpose**: Create or update user

**Request Body** (UserSaveDto):
```json
{
  "id": 0,
  "username": "new.user",      // Required, min 3 chars, unique (UniqueUsername)
  "abbr": "NUS",               // 3-letter abbreviation (ValidUserAbbr, UniqueUserAbbr)
  "type": "DEV",               // One of: USER, DEV, PL, ADMIN
  "locale": "en",              // Locale preference
  "active": true,              // Active flag (default true)
  "teams": [1, 2]              // Team assignments (at least one required)
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

### Holiday Management

#### GET /getAllHolidays
**Purpose**: Retrieve all configured holidays

**Authentication**: Required (`ROLE_ADMIN`)

#### POST /holiday/save
**Purpose**: Create or update a holiday

**Request Body** (HolidaySaveDto):
```json
{
  "day": "2026-01-01",   // Required, valid date
  "name": "New Year"      // Required
}
```

#### POST /holiday/delete
**Purpose**: Delete a holiday

**Request Body** (HolidayDeleteDto):
```json
{
  "day": "2026-01-01"    // Required, valid date
}
```

### Synchronization Operations

#### GET /syncentries/jira
**Purpose**: Synchronize time entries with Jira

**Authentication**: Required (`ROLE_ADMIN`)

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

### GET|POST /getData
**Purpose**: Retrieve user's time entries for recent days or filtered data

**Authentication**: Required (`IS_AUTHENTICATED_FULLY`)

**Methods**: `GET` and `POST` (route `_getData_attr` allows both)

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
**Purpose**: Retrieve all users (username-sorted, current user moved to the top)

**Response (200 OK)**:
```json
[
  {
    "id": 1,
    "username": "john.doe",
    "type": "DEV",
    "abbr": "JDO",
    "locale": "en"
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

### GET /getContractHours
**Purpose**: Retrieve the current user's contract hours (used by the bulk entry "use contract hours" option)

**Authentication**: Required (`IS_AUTHENTICATED_FULLY`)

### POST /getSummary — deprecated
**Deprecated** (ADR-022): superseded by [`GET /api/v2/entries/{id}/summary`](#get-apiv2entriesidsummary); removal in v7. Responses carry a `Deprecation: true` header.

**Purpose**: Per-scope booking totals (customer/project/activity/ticket) for one of the caller's own time entries — the tracking UI's "Info" popup

**Method**: `POST` only (route `_getSummary_attr`, `methods: ['POST']`), form-encoded `id`

**Authentication**: Required (`IS_AUTHENTICATED_FULLY`); scoped to the caller's own entries — a foreign or unknown entry id answers `404`

**Response (200 OK)**: `customer` / `project` / `activity` / `ticket` objects, each `{scope, name, entries, total, own, estimation}` (minutes); `project.quota` is a formatted percentage string when the project has an estimate

### GET /getTimeSummary — deprecated
**Deprecated** (ADR-022): superseded by [`GET /api/v2/time-balance`](#get-apiv2time-balance); removal in v7. Responses carry a `Deprecation: true` header.

**Purpose**: Get worked minutes and target for today/week/month

**Response (200 OK)**:
```json
{
  "today": { "duration": 450, "count": 3, "target": 480 },
  "week": { "duration": 1935, "count": 12, "target": 2400 },
  "month": { "duration": 5760, "count": 40, "target": 9600 }
}
```

### GET /api/v2/time-balance
**Purpose**: The authenticated user's worked-vs-target balance for today, this week and this month (ADR-022; same numbers as the header display and the `get_time_balance` MCP tool)

**Authentication**: Session, or Bearer PAT with `reporting:read`

**Response (200 OK)**:
```json
{
  "today": { "ist": 450, "soll_total": 480, "soll_so_far": 480, "diff": -30, "status": "behind" },
  "week": { "ist": 1935, "soll_total": 2400, "soll_so_far": 960, "diff": 975, "status": "ok" },
  "month": { "ist": 5760, "soll_total": 9600, "soll_so_far": 1440, "diff": 4320, "status": "ok" },
  "warnings": ["today: behind target by 0h 30m (worked 7h 30m, expected 8h 00m so far)."]
}
```

`status` per period: `behind` (IST below the SOLL accrued so far), `over` (IST above the whole period's SOLL), else `ok`.

### GET /api/v2/day
**Purpose**: The caller's own time entries and booked total for one day (default: today) — the tracking grid's day view as data (ADR-022 Phase 2; also returned by `log_time` as `day` and by the `get_day` MCP tool)

**Authentication**: Session, or Bearer PAT with `entries:read`

**Parameters**: `date` (query, optional): `YYYY-MM-DD`; invalid dates answer `422`

**Response (200 OK)**:
```json
{
  "date": "2026-07-06",
  "entries": [ { "id": 4711, "date": "06/07/2026", "start": "09:00", "end": "10:00", "duration": "01:00", "durationMinutes": 60, "ticket": "SA-11", "...": "..." } ],
  "count": 1,
  "total_minutes": 60
}
```

### GET /api/v2/entries/{id}/summary
**Purpose**: Per-scope booking totals and estimate verdict for one of the caller's own entries (ADR-022; the tracking UI's "Info" popup and the `get_ticket_info` MCP tool)

**Authentication**: Session, or Bearer PAT with `reporting:read`. Owner-scoped: a foreign or unknown entry id answers `404` (no existence disclosure).

**Response (200 OK)**:
```json
{
  "customer": { "scope": "customer", "name": "ACME", "entries": 5, "total": 300, "own": 120, "estimation": 0 },
  "project": { "scope": "project", "name": "Site", "entries": 3, "total": 180, "own": 90, "estimation": 600 },
  "activity": { "scope": "activity", "name": "Dev", "entries": 2, "total": 120, "own": 60, "estimation": 0 },
  "ticket": { "scope": "ticket", "name": "ABC-1", "entries": 1, "total": 60, "own": 60, "estimation": 0 },
  "estimate": { "estimation": 600, "booked_total": 180, "percent": 30, "status": "ok" },
  "warnings": []
}
```

`estimate.status`: `none` (no estimate), `ok`, `near` (≥ 90 %), `over` (at/above the estimate). `total` spans all users; `own` is the caller's share.

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
**Purpose**: Returns this instance's ticket-time-summary base URL as a JSON
string, for use by the Greasemonkey userscript
(`public/scripts/timeSummaryForJira.js`).

**Response (200 OK)**:
```json
"https://timetracker.example.com/getTicketTimeSummary/"
```

---

## Interpretation & Reporting APIs

*Note: Interpretation endpoints require authentication, not a specific role. The `/interpretation/allEntries` action uses `#[IsGranted('ROLE_USER')]`; the group-by actions use `#[IsGranted('IS_AUTHENTICATED_FULLY')]`. Visibility is scoped in code: in the group-by actions (`BaseInterpretationController::getEntries`) `DEV` users only see their own entries, and `allEntries` restricts results for users who are neither `ROLE_ADMIN` nor `PL`.*

### POST /interpretation/allEntries
**Purpose**: Retrieve paginated entries with filtering

**Authentication**: Required (`ROLE_USER`; non-admin/non-PL users are limited to their own entries)

**Note**: Despite the `POST` method, filters are read from the query string (`#[MapQueryString] InterpretationFiltersDto`).

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

**Authentication**: Required (`IS_AUTHENTICATED_FULLY`)

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

**Authentication**: Required (`IS_AUTHENTICATED_FULLY`)

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

### GET /admin/status
**Purpose**: Administrative system/health status

**Authentication**: Required (`ROLE_ADMIN`)

---

## Configuration APIs

### POST /settings/save
**Purpose**: Save the current user's preferences

**Authentication**: Required (`IS_AUTHENTICATED_FULLY`)

**Form Data** (`application/x-www-form-urlencoded`):
- `show_empty_line`: Show an empty entry row (0/1)
- `suggest_time`: Suggest start/end times (0/1)
- `show_future`: Show future dates (0/1)
- `min_entry_duration`: Minimum entry duration in minutes (default 5)
- `locale`: Language preference (normalized by `LocalizationService`)

**Response (200 OK)**:
```json
{
  "success": true,
  "settings": { },              // User::getSettings() snapshot
  "locale": "en",
  "message": "The configuration has been successfully saved."
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

**Authentication**: Required (`ROLE_ADMIN`)

**Query Parameters** (ExportQueryDto):
- `userid`: User ID filter (0 = all users)
- `year`: Year filter
- `month`: Month filter
- `project`: Project ID filter
- `customer`: Customer ID filter
- `billable`: Billable filter (0/1)
- `tickettitles`: Include ticket titles (0/1)

**Response (200 OK)**: Excel/CSV file with administrative data

*A positional legacy route also exists: `GET /controlling/export/{userid}/{year}/{month}/{project}/{customer}/{billable}`.*

---

## Authentication Endpoints

### GET /login
**Purpose**: Display login form

**Response (200 OK)**: HTML login page

### POST /login
**Purpose**: Authenticate user (handled by `App\Security\LdapAuthenticator`)

**Form Data**:
- `_username`: Username
- `_password`: Password
- `_csrf_token`: Stateless CSRF token (validated against the `authenticate` token id)

**Response**:
- `302 Found`: Redirect to the start page on success
- `200 OK`: Login form with error message on failure

*The `_login`/`_logout` routes are defined in `config/routes.yaml` (no `#[Route]` attribute on `SecurityController`).*

### GET /logout
**Purpose**: Logout current user (intercepted by the firewall's logout handler; CSRF-protected via the `logout` token id, session invalidated)

**Response (302 Found)**: Redirect to `/login`

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

This API reference provides comprehensive coverage of all TimeTracker endpoints. For specific implementation details, validation rules, and entity relationships, refer to the [DTO Documentation](./DTO_DOCUMENTATION.md).