# TimeTracker Database Schema Documentation

## Overview

The TimeTracker application uses a relational database schema built with Doctrine ORM. This document provides comprehensive documentation of all entity relationships, database schema structure, business rules, and migration patterns.

## Core Entity Relationships

### Entity Relationship Diagram Summary

```
User (1) ----< (M) Entry (M) >---- (1) Project (M) >---- (1) Customer
  |                    |                    |                    |
  |                    |                    |                    |
  v                    v                    v                    v
Team (M) <---> (M) Customer            TicketSystem         Team (M) <---> (M) Customer
  |              
  |
User (1) ----< (M) Contract
  |
  |
User (1) ----< (M) UserTicketsystem (M) >---- (1) TicketSystem

Entry (M) >---- (1) Activity
Entry (M) >---- (1) Account

Project (1) ----< (M) Preset (M) >---- (1) Customer
Activity (1) ----< (M) Preset
```

## Entity Definitions

### 1. User Entity (`users` table)

**Primary Entity**: Central user management for authentication and time tracking.

**Properties:**
- `id` (int, PK, auto-increment): Primary key
- `username` (string, 50): Unique username for authentication
- `abbr` (string, 3, nullable): User abbreviation
- `type` (UserType enum): User role (USER, DEV, PL, ADMIN)
- `jira_token` (string, 255, nullable): Encrypted JIRA token
- `show_empty_line` (boolean, default: false): UI preference
- `suggest_time` (boolean, default: true): UI preference  
- `show_future` (boolean, default: true): UI preference
- `locale` (string, 2, default: 'de'): Localization preference

**Relationships:**
- **One-to-Many**: User -> Entry (user can have multiple time entries)
- **One-to-Many**: User -> Contract (user can have multiple contracts)
- **One-to-Many**: User -> UserTicketsystem (user can connect to multiple ticket systems)
- **Many-to-Many**: User <-> Team (users belong to teams)
- **Many-to-One**: Project -> User (project lead)
- **Many-to-One**: Project -> User (technical lead)

**Business Rules:**
- Username is required and unique
- UserType determines Symfony roles and permissions
- JIRA token is encrypted for security
- Locale is normalized through LocalizationService

### 2. Entry Entity (`entries` table)

**Primary Entity**: Core time tracking entries - the heart of the application.

**Properties:**
- `id` (int, PK, auto-increment): Primary key
- `ticket` (string, 32): Ticket reference number
- `worklog_id` (int, nullable): External worklog ID from ticket system
- `description` (string): Entry description
- `day` (date): Date of the entry
- `start` (time): Start time
- `end` (time): End time
- `duration` (int): Duration in minutes (calculated)
- `synced_to_ticketsystem` (boolean, nullable): Sync status
- `class` (EntryClass enum, default: PLAIN): Entry classification
- `internal_jira_ticket_original_key` (string, 50, nullable): Original ticket key

**Relationships:**
- **Many-to-One**: Entry -> User (who logged the time)
- **Many-to-One**: Entry -> Project (project for the entry)
- **Many-to-One**: Entry -> Customer (customer for the entry)
- **Many-to-One**: Entry -> Activity (activity type)
- **Many-to-One**: Entry -> Account (account/billing category)

**Business Rules:**
- Duration must be > 0 (validated in `validateDuration()`)
- End time must be >= start time (enforced in `alignStartAndEnd()`)
- Duration is auto-calculated from start/end times
- Ticket reference is automatically cleaned (spaces removed)

**Non-Persisted Properties:**
- `billable` (boolean): Runtime flag for billable status
- `externalSummary` (string): External ticket summary
- `externalLabels` (array): External ticket labels
- `externalReporter` (string): External ticket reporter
- `ticketTitle` (string): External ticket title

**Performance Indexes:**
- `idx_entries_user_day`: Composite index for user + date queries
- `idx_entries_day`: Date range queries
- `idx_entries_customer`: Customer queries
- `idx_entries_project`: Project queries
- `idx_entries_activity`: Activity queries
- `idx_entries_ticket`: Ticket reference queries
- `idx_entries_user_project`: User + project composite queries
- `idx_entries_user_sync`: User + sync status queries
- `idx_entries_worklog`: Worklog ID lookups
- `idx_entries_day_start`: Date + start time sorting

### 3. Project Entity (`projects` table)

**Primary Entity**: Project management and organization structure.

**Properties:**
- `id` (int, PK, auto-increment): Primary key
- `name` (string, 127): Project name
- `active` (boolean, default: false): Active status
- `global` (boolean, default: false): Global project flag
- `jira_id` (string, 63, nullable): JIRA project ID
- `jira_ticket` (string, 255, nullable): Main JIRA ticket
- `subtickets` (string, 255, nullable): Comma-separated subticket list
- `estimation` (int, default: 0): Estimated duration in minutes
- `offer` (string, 31, nullable): Offer number
- `billing` (BillingType enum, default: NONE): Billing method
- `cost_center` (string, 31, nullable): Cost center
- `internal_ref` (string, 31, nullable): Internal reference
- `external_ref` (string, 31, nullable): External reference
- `invoice` (string, 31, nullable): Invoice number
- `additional_information_from_external` (boolean, default: false): External info flag
- `internal_jira_project_key` (string, 255, nullable): Internal JIRA project key
- `internal_jira_ticket_system` (string, 255, nullable): Internal JIRA system ID

**Relationships:**
- **Many-to-One**: Project -> Customer (project belongs to customer)
- **Many-to-One**: Project -> TicketSystem (external ticket system)
- **Many-to-One**: Project -> User (project lead)
- **Many-to-One**: Project -> User (technical lead)
- **One-to-Many**: Project -> Entry (project has entries)
- **One-to-Many**: Project -> Preset (project has presets)

**Business Rules:**
- Project must belong to a customer
- Only one active project per customer recommended
- Global projects are available to all users
- Internal JIRA project keys support comma-separated lists
- Estimation is in minutes and converted to readable format

### 4. Customer Entity (`customers` table)

**Primary Entity**: Customer/client management.

**Properties:**
- `id` (int, PK, auto-increment): Primary key
- `name` (string, 255): Customer name
- `active` (boolean, default: false): Active status
- `global` (boolean, default: false): Global customer flag

**Relationships:**
- **One-to-Many**: Customer -> Project (customer has projects)
- **One-to-Many**: Customer -> Entry (direct customer entries)
- **One-to-Many**: Customer -> Preset (customer presets)
- **Many-to-Many**: Customer <-> Team (customers assigned to teams)

**Business Rules:**
- Customer name is required
- Global customers are available to all teams
- Active flag controls visibility

### 5. Team Entity (`teams` table)

**Primary Entity**: Team and organizational structure management.

**Properties:**
- `id` (int, PK, auto-increment): Primary key
- `name` (string, 31): Team name
- `lead_user_id` (int, nullable): Team lead user ID

**Relationships:**
- **Many-to-One**: Team -> User (team lead)
- **Many-to-Many**: Team <-> User (team members)
- **Many-to-Many**: Team <-> Customer (team's customers)

**Business Rules:**
- Team name is required and unique
- Team lead must be a valid user
- Users can belong to multiple teams
- Teams have access to specific customers

### 6. Activity Entity (`activities` table)

**Primary Entity**: Activity types for time categorization.

**Properties:**
- `id` (int, PK, auto-increment): Primary key
- `name` (string, 50): Activity name
- `needs_ticket` (boolean, default: false): Requires ticket reference
- `factor` (float, default: 1.0): Time calculation factor

**Relationships:**
- **One-to-Many**: Activity -> Entry (activity has entries)
- **One-to-Many**: Activity -> Preset (activity has presets)

**Business Rules:**
- Predefined constants: SICK = 'Krank', HOLIDAY = 'Urlaub'
- Factor affects duration calculations
- needs_ticket enforces ticket requirement

### 7. Account Entity (`accounts` table)

**Primary Entity**: Account/billing categorization.

**Properties:**
- `id` (int, PK, auto-increment): Primary key
- `name` (string, 50): Account name

**Relationships:**
- **One-to-Many**: Account -> Entry (account has entries)

**Business Rules:**
- Simple categorization for billing purposes
- Legacy methods maintained for backward compatibility

### 8. Contract Entity (`contracts` table)

**Primary Entity**: User working hour contracts.

**Properties:**
- `id` (int, PK, auto-increment): Primary key
- `user_id` (int, FK, NOT NULL): User reference
- `start` (date): Contract start date
- `end` (date, nullable): Contract end date
- `hours_0` to `hours_6` (float): Working hours for each day of week

**Relationships:**
- **Many-to-One**: Contract -> User (contract belongs to user)

**Business Rules:**
- Contract must have valid user
- Working hours defined per weekday (0=Sunday, 6=Saturday)
- End date optional for ongoing contracts

### 9. Preset Entity (`presets` table)

**Primary Entity**: Predefined time entry templates.

**Properties:**
- `id` (int, PK, auto-increment): Primary key
- `name` (string): Preset name
- `description` (string): Preset description

**Relationships:**
- **Many-to-One**: Preset -> Project (preset for project)
- **Many-to-One**: Preset -> Customer (preset for customer)  
- **Many-to-One**: Preset -> Activity (preset for activity)

**Business Rules:**
- All foreign keys are nullable (flexible presets)
- Presets return new instances if relationships are null
- Used for quick entry creation

### 10. Holiday Entity (`holidays` table)

**Primary Entity**: Holiday calendar management.

**Properties:**
- `day` (date, PK): Holiday date (primary key)
- `name` (string, 255): Holiday description

**Business Rules:**
- Readonly properties after construction
- Uses date as primary key
- Immutable design pattern

### 11. TicketSystem Entity (`ticket_systems` table)

**Primary Entity**: External ticket system integration configuration.

**Properties:**
- `id` (int, PK, auto-increment): Primary key
- `name` (string, 31, unique): System name
- `book_time` (boolean, default: false): Time booking capability
- `type` (TicketSystemType enum, default: JIRA): System type
- `url` (string, 255): Base URL
- `ticketurl` (string, 255): Ticket URL template
- `login` (string, 63): Login credential
- `password` (string, 63): Password credential
- `public_key` (text): OAuth public key
- `private_key` (text): OAuth private key
- `oauth_consumer_key` (string, 255, nullable): OAuth consumer key
- `oauth_consumer_secret` (string, 255, nullable): OAuth consumer secret

**Relationships:**
- **One-to-Many**: TicketSystem -> Project (system used by projects)
- **One-to-Many**: TicketSystem -> UserTicketsystem (user connections)

**Business Rules:**
- System name must be unique
- Supports multiple authentication methods
- OAuth keys stored as encrypted text fields

### 12. UserTicketsystem Entity (`users_ticket_systems` table)

**Primary Entity**: User-specific ticket system authentication.

**Properties:**
- `id` (int, PK, auto-increment): Primary key
- `user_id` (int, FK): User reference
- `ticket_system_id` (int, FK): Ticket system reference
- `accesstoken` (text): Encrypted OAuth access token
- `tokensecret` (text): Encrypted OAuth token secret
- `avoidconnection` (boolean, default: false): Skip connection flag

**Relationships:**
- **Many-to-One**: UserTicketsystem -> User (user's ticket system)
- **Many-to-One**: UserTicketsystem -> TicketSystem (ticket system config)

**Business Rules:**
- Tokens are encrypted and stored as TEXT (expanded from VARCHAR(50))
- User can have multiple ticket system connections
- avoidconnection allows bypassing integration

**Performance Index:**
- `idx_user_ticket_system_user`: User-based queries

### 13. Ticket Entity (`tickets` table)

**Primary Entity**: Cached ticket information from external systems.

**Properties:**
- `id` (int, PK, auto-increment): Primary key
- `ticket_system_id` (int): System reference
- `ticket_number` (string, 31): Ticket identifier
- `name` (string, 127): Ticket title
- `estimation` (int, default: 0): Estimated duration
- `parent` (string, 31): Parent ticket number
- `ticketId` (int, non-persisted): Runtime ticket ID

**Business Rules:**
- Constructor requires system ID, number, and name
- Supports hierarchical ticket relationships
- Used for caching external ticket data

## Enumeration Types

### UserType Enum
- `UNKNOWN` = '' (default, unconfigured)
- `USER` = 'USER' (basic user)
- `DEV` = 'DEV' (developer)  
- `PL` = 'PL' (project lead)
- `ADMIN` = 'ADMIN' (administrator)

**Roles Mapping:**
- USER/DEV: ['ROLE_USER']
- PL: ['ROLE_USER', 'ROLE_PL']
- ADMIN: ['ROLE_USER', 'ROLE_ADMIN']

### EntryClass Enum
- `PLAIN` = 1 (regular work)
- `DAYBREAK` = 2 (day break)
- `PAUSE` = 4 (break/pause)
- `OVERLAP` = 8 (time overlap conflict)

### BillingType Enum
- `NONE` = 0 (no billing)
- Additional values defined in enum

### TicketSystemType Enum  
- `JIRA` = 'JIRA' (default)
- Additional types as needed

## Join Tables

### `teams_users`
**Many-to-Many**: User <-> Team
- `user_id` (FK to users.id, CASCADE DELETE)
- `team_id` (FK to teams.id, CASCADE DELETE)

### `teams_customers`  
**Many-to-Many**: Team <-> Customer
- `customer_id` (FK to customers.id)
- `team_id` (FK to teams.id)

## Database Migration Analysis

### Performance Optimizations (Version20250901_AddPerformanceIndexes)

**Purpose**: Optimize frequent query patterns in the entries table.

**Indexes Added:**
- `idx_entries_user_day`: User + date queries (most common)
- `idx_entries_day`: Date range filtering
- `idx_entries_customer`: Customer-based reports
- `idx_entries_project`: Project-based reports
- `idx_entries_activity`: Activity filtering
- `idx_entries_ticket`: Ticket lookups
- `idx_entries_user_project`: User + project reports
- `idx_entries_user_sync`: Sync status queries
- `idx_entries_worklog`: External worklog references
- `idx_entries_day_start`: Time-ordered queries

### Security Enhancement (Version20250901_EncryptTokenFields)

**Purpose**: Support encrypted token storage for enhanced security.

**Changes:**
- Expanded `accesstoken` and `tokensecret` from VARCHAR(50) to TEXT
- Added `idx_user_ticket_system_user` for performance
- Enables storage of encrypted OAuth tokens

## Business Rules and Constraints

### Data Integrity Rules

1. **Entry Validation:**
   - Duration must be > 0
   - End time >= start time
   - Date is required
   - User is required

2. **Project Management:**
   - Project must belong to customer
   - Only one active project recommended per scope
   - JIRA project keys support multiple values

3. **User Management:**
   - Username must be unique
   - User type determines access permissions
   - Locale is normalized

4. **Team Organization:**
   - Users can belong to multiple teams
   - Teams have specific customer access
   - Team leads must be valid users

### Cascade Operations

1. **User Deletion:**
   - Cascades to teams_users (user removed from teams)
   - Contracts remain (historical data)
   - Entries remain (historical data)

2. **Team Deletion:**
   - Cascades to teams_users (removes team memberships)
   - Cascades to teams_customers (removes customer access)

3. **Foreign Key Behaviors:**
   - Most relationships maintain referential integrity
   - Historical data preservation prioritized
   - Orphan removal limited to join tables

### Security Considerations

1. **Token Encryption:**
   - OAuth tokens encrypted before storage
   - JIRA tokens encrypted in user table
   - Private/public keys stored as encrypted text

2. **Authentication Integration:**
   - LDAP integration through UserInterface
   - Password generation for remember_me functionality
   - Role-based access through UserType enum

3. **Data Access:**
   - Team-based customer access control
   - User-specific ticket system connections
   - Project-based visibility controls

## Performance Characteristics

### Query Optimization
- Comprehensive indexing on entries table (primary query target)
- Composite indexes for common query patterns
- Foreign key indexes for join operations

### Caching Strategy
- Ticket information cached locally
- External system data synchronized periodically
- Runtime properties for non-persisted calculations

### Scalability Considerations
- Entries table will be largest (time data)
- Historical data preservation (no hard deletes)
- Efficient date range queries through indexing
- Composite indexes support complex reporting queries

## Integration Points

### External Ticket Systems
- JIRA integration through TicketSystem entity
- OAuth authentication per user
- Ticket data caching and synchronization
- Worklog bidirectional sync

### Reporting and Analytics
- Time tracking aggregations
- Project progress monitoring
- Team productivity analysis
- Customer billing calculations

### User Interface Support
- Preset templates for quick entry
- Holiday calendar integration
- User preference management
- Real-time entry validation

This database schema provides a robust foundation for time tracking with strong referential integrity, comprehensive audit capabilities, and efficient query performance through strategic indexing.