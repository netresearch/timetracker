# TimeTracker API - Detailed Endpoint Specifications

## Admin Domain Endpoints

### Customer Management

#### POST /customer/save
Create or update customer records with team associations.

**Headers:**
```
Content-Type: application/json
Cookie: PHPSESSID=...
```

**Request Body:**
```json
{
  "id": 0,
  "name": "Acme Corporation",
  "active": true,
  "global": false,
  "teams": [1, 2, 3]
}
```

**Response (Success - 200):**
```json
[123, "Acme Corporation", true, false, [1, 2, 3]]
```

**Response (Validation Error - 406):**
```
Could not find team(s) with ID(s): 5, 6.
```

**Response (Authorization Error - 403):**
```
You are not allowed to perform this action.
```

**Validation Rules:**
- `name`: Required, minimum 3 characters, must be unique
- `teams`: Required if `global` is false, all team IDs must exist
- `id`: 0 for new customer, existing ID for updates

---

### Project Management

#### POST /project/save
Create or update project records.

**Request Body:**
```json
{
  "id": 0,
  "name": "Mobile App Development",
  "customer_id": 123,
  "active": true,
  "jira_id": "MOBILE",
  "estimation": 1000.0
}
```

**Validation:**
- Project name must be unique within customer
- Customer must exist and be accessible
- Jira ID optional but must be unique if provided

---

### User Management

#### POST /user/save
Create or update user accounts.

**Request Body:**
```json
{
  "id": 0,
  "username": "john.doe",
  "password": "secure_password",
  "email": "john.doe@company.com",
  "type": "DEV",
  "active": true,
  "teams": [1, 2]
}
```

**User Types:**
- `DEV`: Developer - limited access to own data
- `PL`: Project Lead - admin access to manage teams and projects

---

### Team Management

#### POST /team/save
Create or update team records.

**Request Body:**
```json
{
  "id": 0,
  "name": "Frontend Team",
  "lead_user_id": 5,
  "active": true
}
```

---

## Tracking Domain Endpoints

### Individual Entry Management

#### POST /tracking/save
Save individual time tracking entries with comprehensive validation.

**Request Body Examples:**

**Minimum Required:**
```json
{
  "date": "2024-01-15",
  "start": "09:00",
  "end": "17:00",
  "customer_id": 1,
  "project_id": 2,
  "activity_id": 3
}
```

**Full Entry:**
```json
{
  "id": null,
  "date": "2024-01-15",
  "start": "09:00:00",
  "end": "17:00:00",
  "ticket": "MOBILE-123",
  "description": "Implemented user authentication flow",
  "customer_id": 1,
  "project_id": 2,
  "activity_id": 3
}
```

**Response (Success - 200):**
```json
{
  "result": {
    "date": "15/01/2024",
    "start": "09:00",
    "end": "17:00",
    "user": 5,
    "customer": 1,
    "project": 2,
    "activity": 3,
    "duration": 480,
    "durationString": "08:00",
    "class": "daybreak",
    "ticket": "MOBILE-123",
    "description": "Implemented user authentication flow"
  }
}
```

**Validation Errors (400):**
```json
{
  "error": "Start time cannot be after end time."
}
```

```json
{
  "error": "Given ticket does not have a valid prefix."
}
```

---

### Bulk Entry Creation

#### POST /tracking/bulkentry
Create multiple time entries based on date range and preset configuration.

**Request Body:**
```json
{
  "preset": 5,
  "startdate": "2024-01-01",
  "enddate": "2024-01-31",
  "starttime": "09:00:00",
  "endtime": "17:00:00",
  "usecontract": 0,
  "skipweekend": 1,
  "skipholidays": 1
}
```

**Parameters Explained:**
- `preset`: ID of preset containing customer/project/activity defaults
- `usecontract`: 1 to use contract-defined working hours, 0 to use manual times
- `skipweekend`: 1 to skip Saturday/Sunday, 0 to include all days
- `skipholidays`: 1 to skip German holidays, 0 to include holidays

**Response (Success - 200):**
```
22 entries have been added
Contract is valid from 01.01.2024.
```

**Response (Error - 422):**
```
Die Aktivität muss mindestens eine Minute angedauert haben!
```

---

## Data Retrieval Domain

### User Data Access

#### GET /getCustomers
Retrieve customers accessible to current user based on team membership.

**Response:**
```json
[
  {
    "id": 1,
    "name": "Acme Corporation",
    "active": true,
    "global": false,
    "teams": [1, 2]
  },
  {
    "id": 2,
    "name": "Global Client",
    "active": true,
    "global": true,
    "teams": []
  }
]
```

---

#### GET /getProjects?customer=1
Retrieve projects for specific customer.

**Query Parameters:**
- `customer`: Customer ID (required)

**Response:**
```json
[
  {
    "id": 2,
    "name": "Mobile App Development",
    "customer_id": 1,
    "active": true,
    "jira_id": "MOBILE",
    "estimation": 1000.0
  }
]
```

---

#### GET /getActivities
Retrieve all available activities.

**Response:**
```json
[
  {
    "id": 1,
    "name": "Development",
    "active": true
  },
  {
    "id": 2,
    "name": "Testing",
    "active": true
  }
]
```

---

### Time Entry Retrieval

#### GET /getData
Get recent time entries for current user (default: 3 days).

**Response:**
```json
{
  "entries": [
    {
      "id": 100,
      "date": "2024-01-15",
      "start": "09:00:00",
      "end": "17:00:00",
      "duration": 480,
      "ticket": "MOBILE-123",
      "description": "Authentication work",
      "customer": {
        "id": 1,
        "name": "Acme Corporation"
      },
      "project": {
        "id": 2,
        "name": "Mobile App Development"
      },
      "activity": {
        "id": 1,
        "name": "Development"
      }
    }
  ],
  "pagination": {
    "total": 1,
    "page": 1,
    "limit": 50
  }
}
```

#### GET /getData/days/7
Get entries for specific number of recent days.

**Path Parameters:**
- `days`: Number of days to retrieve (e.g., 7, 14, 30)

---

## Interpretation Domain

### Analytics Endpoints

#### GET /interpretation/activity
Time breakdown grouped by activity.

**Query Parameters:**
```
customer=1
project=2  
datestart=2024-01-01
dateend=2024-01-31
user=5
maxResults=100
```

**Response:**
```json
[
  {
    "id": 1,
    "name": "Development",
    "hours": 120.5,
    "quota": "65.2%"
  },
  {
    "id": 2,
    "name": "Testing", 
    "hours": 45.0,
    "quota": "24.3%"
  }
]
```

---

#### GET /interpretation/customer
Time breakdown grouped by customer.

**Response:**
```json
[
  {
    "id": 1,
    "name": "Acme Corporation",
    "hours": 240.0,
    "quota": "80.0%"
  }
]
```

---

#### GET /interpretation/entries
Raw entry data with advanced filtering.

**Query Parameters:**
```
customer=1
project=2
activity=1
ticket=MOBILE-123
description=auth
datestart=2024-01-01
dateend=2024-01-31
maxResults=50
page=1
```

**Response:**
```json
{
  "entries": [
    {
      "id": 100,
      "date": "2024-01-15",
      "start": "09:00:00",
      "end": "17:00:00",
      "duration": 480,
      "ticket": "MOBILE-123",
      "description": "Authentication implementation",
      "user": {
        "id": 5,
        "username": "john.doe"
      },
      "customer": {
        "id": 1,
        "name": "Acme Corporation"
      },
      "project": {
        "id": 2,
        "name": "Mobile App Development"
      },
      "activity": {
        "id": 1,
        "name": "Development"
      }
    }
  ],
  "total": 45,
  "page": 1,
  "maxResults": 50
}
```

---

## Export Domain

### CSV Export

#### GET /export/30
Export user's time entries as CSV file.

**Path Parameters:**
- `days`: Number of recent days to export (default: 10000 for all)

**Response Headers:**
```
Content-Type: text/csv; charset=utf-8
Content-Disposition: attachment;filename=john.doe.csv
```

**Response Body (CSV):**
```csv
Date,Start,End,Duration,Customer,Project,Activity,Ticket,Description
15/01/2024,09:00,17:00,08:00,Acme Corporation,Mobile App Development,Development,MOBILE-123,Authentication implementation
14/01/2024,09:30,16:30,07:00,Acme Corporation,Mobile App Development,Testing,MOBILE-124,Unit test creation
```

---

### Summary Export

#### POST /getSummary
Get aggregated time summary for export purposes.

**Request Body:**
```json
{
  "customer": 1,
  "project": 2,
  "datestart": "2024-01-01",
  "dateend": "2024-01-31",
  "groupby": "activity"
}
```

**Response:**
```json
{
  "summary": {
    "total_hours": 185.5,
    "total_entries": 23,
    "date_range": {
      "start": "2024-01-01",
      "end": "2024-01-31"
    },
    "breakdown": [
      {
        "group": "Development",
        "hours": 120.5,
        "percentage": 65.0
      },
      {
        "group": "Testing",
        "hours": 45.0,
        "percentage": 24.3
      }
    ]
  }
}
```

---

## Integration Domain

### Jira Integration

#### GET /jiraoauthcallback
Handle OAuth callback from Jira after user authorization.

**Query Parameters:**
- `oauth_token`: OAuth token received from Jira
- `oauth_verifier`: OAuth verifier received from Jira
- `tsid`: Ticket System ID

**Example URL:**
```
/jiraoauthcallback?oauth_token=abc123&oauth_verifier=def456&tsid=1
```

**Success Response:**
Redirect to dashboard (`/`)

**Error Response (400):**
```
Invalid OAuth callback parameters
```

**Error Response (404):**
```
Ticket system not found
```

---

#### GET /syncentries/jira
Manually trigger Jira worklog synchronization (Admin only).

**Query Parameters:**
- `from`: Start date for sync (optional, default: -3 days)
- `to`: End date for sync (optional, default: now)

**Example:**
```
/syncentries/jira?from=2024-01-01&to=2024-01-31
```

**Response (Success - 200):**
```json
{
  "success": true
}
```

**Response (Error - 403):**
```json
{
  "success": false,
  "message": "Forbidden"
}
```

---

## Error Handling Patterns

### Standard Error Responses

#### Authentication Required (401)
```
You need to login.
```

#### Authorization Failed (403)
```
You are not allowed to perform this action.
```

#### Validation Error (400)
```json
{
  "error": "Start time cannot be after end time."
}
```

#### Resource Not Found (404)
```
No entry for id.
```

#### Business Logic Error (406)
```
Every customer must belong to at least one team if it is not global.
```

#### Validation Error (422)
```
Die Aktivität muss mindestens eine Minute angedauert haben!
```

### HTTP Status Code Usage

- **200 OK**: Successful operation
- **400 Bad Request**: Invalid request data, validation errors
- **401 Unauthorized**: Authentication required
- **403 Forbidden**: Insufficient permissions
- **404 Not Found**: Resource not found
- **406 Not Acceptable**: Business rule violations
- **422 Unprocessable Entity**: DTO validation failures
- **500 Internal Server Error**: System/database errors

## Request/Response Headers

### Standard Request Headers
```
Content-Type: application/json
Accept: application/json
Cookie: PHPSESSID=session_id_here
```

### Standard Response Headers
```
Content-Type: application/json; charset=utf-8
Set-Cookie: PHPSESSID=new_session_id (for login)
Cache-Control: no-cache, private (for authenticated responses)
```

### CSV Export Headers
```
Content-Type: text/csv; charset=utf-8
Content-Disposition: attachment;filename=username.csv
```

## Rate Limiting & Performance

### Current Implementation
- No explicit rate limiting implemented
- Session-based authentication provides natural throttling
- Database connection pooling through Doctrine ORM
- No explicit caching headers (relies on session management)

### Performance Considerations
- Bulk operations limited to 100 iterations for safety
- Pagination available on entry listing endpoints
- Database queries optimized to avoid N+1 problems
- Team-based filtering applied at query level for security

## API Versioning

### Current State
- No explicit API versioning
- Backward compatibility maintained through:
  - Legacy field name support in DTOs
  - Multiple route naming conventions
  - Optional parameters with sensible defaults

### Legacy Support Examples
```php
// DTOs support both naming conventions
public ?int $customer_id = null;    // new format
public ?int $customer = null;       // legacy format

// Getter methods handle both
public function getCustomerId(): ?int {
    return $this->customer_id ?? $this->customer;
}
```