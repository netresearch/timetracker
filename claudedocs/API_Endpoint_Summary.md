# TimeTracker API - Complete Endpoint Summary

## Authentication & Security Overview

**Authentication Method:** Form-based with sessions + LDAP integration  
**Authorization Levels:** 
- Public (no auth required)
- Authenticated (any logged-in user)  
- PL Only (Project Lead role required)
- DEV Filtered (Developers see only own data)

## Complete Endpoint Inventory

### 1. Authentication & Session Management
| Method | Endpoint | Auth Level | Purpose |
|--------|----------|------------|---------|
| GET | `/login` | Public | Display login form |
| POST | `/login_check` | Public | Process login credentials |
| GET | `/logout` | Authenticated | Terminate session |
| GET | `/` | Authenticated | Main dashboard |

### 2. Admin Domain (PL Only)

#### Customer Management
| Method | Endpoint | DTO | Purpose |
|--------|----------|-----|---------|
| POST | `/customer/save` | CustomerSaveDto | Create/update customer |
| GET | `/admin/customers` | - | List all customers |
| DELETE | `/admin/customer/{id}` | - | Delete customer |

#### Project Management  
| Method | Endpoint | DTO | Purpose |
|--------|----------|-----|---------|
| POST | `/project/save` | ProjectSaveDto | Create/update project |
| DELETE | `/admin/project/{id}` | - | Delete project |

#### Team Management
| Method | Endpoint | DTO | Purpose |
|--------|----------|-----|---------|
| POST | `/team/save` | TeamSaveDto | Create/update team |
| GET | `/admin/teams` | - | List all teams |
| DELETE | `/admin/team/{id}` | - | Delete team |

#### User Management
| Method | Endpoint | DTO | Purpose |
|--------|----------|-----|---------|
| POST | `/user/save` | UserSaveDto | Create/update user |
| GET | `/admin/users` | - | List all users |
| DELETE | `/admin/user/{id}` | - | Delete user |

#### Activity Management
| Method | Endpoint | DTO | Purpose |
|--------|----------|-----|---------|
| POST | `/activity/save` | ActivitySaveDto | Create/update activity |
| DELETE | `/admin/activity/{id}` | - | Delete activity |

#### Contract Management
| Method | Endpoint | DTO | Purpose |
|--------|----------|-----|---------|
| POST | `/contract/save` | ContractSaveDto | Create/update contract |
| GET | `/admin/contracts` | - | List all contracts |
| DELETE | `/admin/contract/{id}` | - | Delete contract |

#### Preset Management
| Method | Endpoint | DTO | Purpose |
|--------|----------|-----|---------|
| POST | `/preset/save` | PresetSaveDto | Create/update preset |
| GET | `/admin/presets` | - | List all presets |
| DELETE | `/admin/preset/{id}` | - | Delete preset |

#### Ticket System Management
| Method | Endpoint | DTO | Purpose |
|--------|----------|-----|---------|
| POST | `/ticketsystem/save` | TicketSystemSaveDto | Create/update ticket system |
| GET | `/admin/ticketsystems` | - | List all ticket systems |
| DELETE | `/admin/ticketsystem/{id}` | - | Delete ticket system |

### 3. Time Tracking Domain

#### Individual Entry Management
| Method | Endpoint | DTO | Auth Level | Purpose |
|--------|----------|-----|------------|---------|
| POST | `/tracking/save` | EntrySaveDto | Authenticated | Create/update time entry |
| DELETE | `/tracking/entry/{id}` | - | Authenticated | Delete time entry (owner only) |

#### Bulk Operations
| Method | Endpoint | DTO | Auth Level | Purpose |
|--------|----------|-----|------------|---------|
| POST | `/tracking/bulkentry` | BulkEntryDto | Authenticated | Create multiple entries from preset |

### 4. Data Retrieval Domain

#### Basic Data Access
| Method | Endpoint | Auth Level | Purpose | Filters |
|--------|----------|------------|---------|---------|
| GET | `/getCustomers` | Authenticated | Get user's accessible customers | Team-based |
| GET | `/getProjects` | Authenticated | Get projects for customer | ?customer=id |
| GET | `/getActivities` | Authenticated | Get all activities | None |
| GET | `/getAllProjects` | Authenticated | Get all user's projects | Team-based |
| GET | `/getProjectStructure` | Authenticated | Get hierarchical project data | Team-based |
| GET | `/getUsers` | Authenticated | Get all users | - |
| GET | `/getHolidays` | Authenticated | Get holiday dates | - |

#### Time Entry Retrieval
| Method | Endpoint | Auth Level | Purpose | Default |
|--------|----------|------------|---------|---------|
| GET/POST | `/getData` | Authenticated | Get recent entries | 3 days |
| GET | `/getData/days/{days}` | Authenticated | Get entries for X days | Parameter-based |

#### Customer/Project Queries
| Method | Endpoint | Auth Level | Purpose | Parameters |
|--------|----------|------------|---------|-------------|
| GET | `/getCustomer` | Authenticated | Get single customer | ?id=customer_id |

### 5. Interpretation & Analytics Domain

All interpretation endpoints use `InterpretationFiltersDto` and support comprehensive filtering.

| Method | Endpoint | Auth Level | Grouping | Access Control |
|--------|----------|------------|----------|----------------|
| GET | `/interpretation/activity` | Authenticated | By Activity | DEV filtered |
| GET | `/interpretation/customer` | Authenticated | By Customer | DEV filtered |
| GET | `/interpretation/project` | Authenticated | By Project | DEV filtered |
| GET | `/interpretation/user` | Authenticated | By User | DEV filtered |
| GET | `/interpretation/ticket` | Authenticated | By Ticket | DEV filtered |
| GET | `/interpretation/worktime` | Authenticated | By Time Pattern | DEV filtered |
| GET | `/interpretation/entries` | Authenticated | Raw Entry Data | DEV filtered |
| GET | `/interpretation/lastentries` | Authenticated | Recent Entries | DEV filtered |
| GET | `/interpretation/allentries` | Authenticated | All Entries | DEV filtered |

#### Common Query Parameters for Interpretation
```
customer=1 | customer_id=1          // Customer filter (legacy support)
project=1 | project_id=1            // Project filter (legacy support)  
activity=1 | activity_id=1          // Activity filter (legacy support)
user=1                              // User filter
team=1                              // Team filter
ticket=PROJ-123                     // Ticket number filter
description=text                    // Description content filter
datestart=2024-01-01               // Start date filter
dateend=2024-01-31                 // End date filter
year=2024                          // Year filter
month=01                           // Month filter (requires year)
maxResults=100                     // Result limit
page=1                             // Pagination
```

### 6. Export Domain

| Method | Endpoint | Auth Level | Format | Purpose |
|--------|----------|------------|--------|---------|
| GET | `/export/{days}` | Authenticated | CSV | Export user's entries |
| POST | `/getSummary` | Authenticated | JSON | Get summary for export |

#### Export Parameters
- `days`: Number of recent days (default: 10000 for unlimited)
- CSV includes UTF-8 BOM for Excel compatibility
- Filename format: `username.csv`

### 7. Integration Domain

#### Jira OAuth Integration
| Method | Endpoint | Auth Level | Purpose |
|--------|----------|------------|---------|
| GET | `/jiraoauthcallback` | Authenticated | Handle OAuth callback |

**Parameters:** `oauth_token`, `oauth_verifier`, `tsid`

#### Admin Sync Operations (PL Only)
| Method | Endpoint | Purpose | Parameters |
|--------|----------|---------|------------|
| GET | `/syncentries/jira` | Manual Jira sync | ?from=date&to=date |
| POST | `/admin/sync/project/{id}/subtickets` | Sync project subtickets | project ID in path |
| POST | `/admin/sync/all-projects/subtickets` | Sync all project subtickets | None |

### 8. Settings Domain

| Method | Endpoint | DTO | Auth Level | Purpose |
|--------|----------|-----|------------|---------|
| POST | `/settings/save` | - | Authenticated | Save user preferences |

### 9. Status & Health Domain

| Method | Endpoint | Auth Level | Purpose | Format |
|--------|----------|------------|---------|--------|
| GET | `/status/check` | Public | API health check | JSON |
| GET | `/status/page` | Public | Status page | HTML |

### 10. Controlling Domain

| Method | Endpoint | Auth Level | Purpose |
|--------|----------|------------|---------|
| GET | `/controlling/export` | Authenticated | Export controlling data |

### 11. Time Summary Endpoints

| Method | Endpoint | Auth Level | Purpose | Parameters |
|--------|----------|------------|---------|------------|
| GET | `/getTimeSummary` | Authenticated | Get time summary | Query filters |
| GET | `/getTicketTimeSummary/{ticket}` | Authenticated | Get ticket-specific summary | ticket in path |
| GET | `/scripts/timeSummaryForJira` | Authenticated | Jira integration script | JavaScript response |

## DTO Validation Summary

### Key Validation Patterns

#### CustomerSaveDto
- `name`: NotBlank, Length(min=3), UniqueCustomerName
- `teams`: Required if not global, validated existence
- `id`: 0 for create, existing for update

#### EntrySaveDto  
- `date`: NotBlank, Date format validation
- `start/end`: NotBlank, Time format, logical validation (start < end)
- `ticket`: Length(max=50), Regex pattern, project prefix validation
- `description`: Length(max=1000)
- `*_id fields`: Positive integers, existence validation

#### BulkEntryDto
- `preset`: NotBlank, Positive, existence validation
- `startdate/enddate`: Date validation, logical range validation
- `starttime/endtime`: Time validation, duration validation
- Boolean flags as integers (0/1)

#### InterpretationFiltersDto
- Nullable conversions for all fields
- Legacy field name support (*_id aliases)
- Type-safe parameter extraction from request

## Security Patterns

### Access Control Matrix
| Endpoint Pattern | Anonymous | Authenticated | PL Only | DEV Restricted |
|------------------|-----------|---------------|---------|----------------|
| `/login*` | ✓ | ✓ | ✓ | ✓ |
| `/status/*` | ✓ | ✓ | ✓ | ✓ |
| `/admin/*` | ❌ | ❌ | ✓ | ❌ |
| `/*/save` | ❌ | ❌ | ✓ | ❌ |
| `/tracking/*` | ❌ | ✓ | ✓ | ✓ |
| `/interpretation/*` | ❌ | ✓ | ✓ | ✓ (own data) |
| `/export/*` | ❌ | ✓ | ✓ | ✓ (own data) |
| `/sync*` | ❌ | ❌ | ✓ | ❌ |

### Role-Based Data Filtering
- **DEV users**: Automatically filtered to their own data in interpretation endpoints
- **PL users**: Access to all team data and admin functions
- **Team-based access**: Customers/projects filtered by user's team membership
- **Ownership validation**: Time entries validated against user ownership

## Response Format Patterns

### JSON Success Response
```json
{
  "result": { /* data object */ }
}
```

### JSON Array Response  
```json
[
  { /* item 1 */ },
  { /* item 2 */ }
]
```

### Error Response Patterns
- Plain text with HTTP status code
- JSON with error field
- Localized error messages via translator service

### Pagination Response
```json
{
  "entries": [ /* data array */ ],
  "total": 150,
  "page": 1,
  "maxResults": 50
}
```

## Performance & Optimization

### Query Optimization
- N+1 query prevention in entity loading
- Bulk team fetching in customer operations
- Filtered queries at database level for security

### Caching Strategy
- No explicit HTTP caching (session-based security)
- Database-level optimizations through Doctrine
- Static data (activities, holidays) suitable for client caching

### Safety Limits
- Bulk operations: 100 iteration maximum
- Default result limits on listing endpoints
- Memory-efficient CSV streaming for exports

## API Evolution Support

### Backward Compatibility
- Legacy field names supported in DTOs (`customer` vs `customer_id`)
- Multiple route naming conventions maintained
- Optional parameters with sensible defaults
- Graceful handling of missing fields

### Extensibility Points
- DTO pattern allows easy field addition
- Route attribute system supports versioning
- Modular controller structure enables feature addition
- Service injection allows behavior customization