# TimeTracker Controller Architecture Index

This document provides a comprehensive overview of the TimeTracker application's controller layer, organized by functional domains and following the Single Action Controller pattern.

## Controller Organization Overview

The TimeTracker application employs a modular controller architecture with **58 total controller files** organized into **7 functional domains**:

| Domain | Controller Count | Purpose |
|--------|-----------------|---------|
| **Admin** | 25 | Administrative operations requiring PL (Project Leader) permissions |
| **Default** | 16 | General application endpoints and data retrieval |
| **Interpretation** | 9 | Time tracking analytics and reporting |
| **Tracking** | 4 | Core time entry operations |
| **Controlling** | 1 | Data export functionality |
| **Settings** | 1 | User preference management |
| **Status** | 2 | Application health and status checks |

**Base Controllers**: 3 shared base classes plus 1 legacy compatibility controller.

---

## Architecture Patterns

### Single Action Controller Pattern
All controllers follow the **Single Action Controller** pattern with these conventions:
- Controllers are `final` classes with a single `__invoke()` method
- Class names end with `Action` suffix (e.g., `SaveUserAction`)
- Each controller handles exactly one HTTP endpoint
- Symfony routes are defined using `#[Route]` attributes

### Inheritance Hierarchy
```
AbstractController (Symfony)
├── BaseController
    ├── Admin\*Action (25 controllers)
    ├── Default\*Action (16 controllers)
    ├── Settings\SaveSettingsAction
    ├── Status\*Action (2 controllers)
    ├── BaseTrackingController
    │   ├── Tracking\SaveEntryAction
    │   ├── Tracking\DeleteEntryAction
    │   └── Tracking\BulkEntryAction
    ├── BaseInterpretationController
    │   └── Interpretation\*Action (8 controllers)
    └── SecurityController (authentication)
└── ControllingController (legacy compatibility)
```

### Security Model
- **Authentication**: All controllers check login status via `checkLogin()` or `isLoggedIn()`
- **Authorization**: Admin controllers enforce PL role via `isPl()` method
- **User Context**: Controllers access current user through `getUserId()` and `getUser()`

---

## Admin Controllers (25 Actions)

**Purpose**: Administrative operations for system configuration and user management.
**Security**: All actions require **PL (Project Leader)** role permissions.
**Pattern**: CRUD operations with `Get*`, `Save*`, and `Delete*` naming.

### Entity Management Controllers

#### User Management (4 actions)
- **GetUsersAction** - Retrieve all users for admin interface
- **SaveUserAction** - Create/update user records with team assignments
- **DeleteUserAction** - Remove user accounts (soft delete)
- **[Shared User Logic]** - Team validation, uniqueness checks

#### Customer Management (3 actions)
- **GetCustomersAction** - Retrieve customer list for admin
- **SaveCustomerAction** - Create/update customer records
- **DeleteCustomerAction** - Remove customer accounts

#### Project Management (3 actions)
- **SaveProjectAction** - Create/update projects with JIRA integration
- **DeleteProjectAction** - Remove projects and dependencies
- **[Project Validation]** - JIRA ID validation, ticket system binding

#### Team Management (3 actions)
- **GetTeamsAction** - Retrieve team structures
- **SaveTeamAction** - Create/update team configurations
- **DeleteTeamAction** - Remove teams and update user assignments

#### Activity Management (3 actions)
- **GetActivitiesAction** - Retrieve available activities
- **SaveActivityAction** - Define billable/non-billable activities
- **DeleteActivityAction** - Remove activities from system

#### Contract Management (3 actions)
- **GetContractsAction** - Retrieve contract definitions
- **SaveContractAction** - Create/update billing contracts
- **DeleteContractAction** - Remove contracts and dependencies

#### Preset Management (3 actions)
- **GetPresetsAction** - Retrieve time entry presets
- **SavePresetAction** - Create/update entry templates
- **DeletePresetAction** - Remove preset configurations

#### Ticket System Integration (3 actions)
- **GetTicketSystemsAction** - Retrieve JIRA/ticket system configs
- **SaveTicketSystemAction** - Configure external ticket systems
- **DeleteTicketSystemAction** - Remove ticket system integrations

### JIRA Integration Controllers (3 actions)
- **SyncJiraEntriesAction** - Synchronize time entries with JIRA worklogs
- **SyncProjectSubticketsAction** - Sync individual project tickets
- **SyncAllProjectSubticketsAction** - Batch sync all project tickets

**Key Operations**: OAuth authentication, worklog creation/updates, ticket validation

---

## Tracking Controllers (4 Actions)

**Purpose**: Core time tracking functionality for entry creation and management.
**Security**: User-level permissions with ownership validation.
**Base Class**: `BaseTrackingController` provides JIRA integration and validation logic.

### Core Tracking Actions

#### SaveEntryAction
- **Route**: `POST /tracking/save`
- **Purpose**: Create or update time entries
- **Validation**:
  - Time range validation (start < end, max 24h duration)
  - Entity relationships (Customer → Project → Activity)
  - JIRA ticket format validation
  - User ownership verification
- **Features**:
  - Automatic duration calculation in minutes
  - JIRA worklog integration
  - Entry classification (daybreak, pause, overlap)

#### DeleteEntryAction
- **Route**: `DELETE /tracking/entry/{id}`
- **Purpose**: Remove time entries with JIRA cleanup
- **Security**: Owner-only deletion
- **Features**: Automatic JIRA worklog deletion

#### BulkEntryAction
- **Route**: `POST /tracking/bulk`
- **Purpose**: Mass creation of time entries
- **Use Case**: Batch import, template application

#### BaseTrackingController
**Shared Functionality**:
- **JIRA Integration**: Create/update/delete worklogs
- **Entry Classification**: Pause, overlap, daybreak detection
- **Validation**: Date/time, ticket format, project matching
- **Logging**: Comprehensive tracking operation logs

---

## Interpretation Controllers (9 Actions)

**Purpose**: Time tracking analytics, reporting, and data aggregation.
**Security**: User-scoped data access with DEV/PL role considerations.
**Base Class**: `BaseInterpretationController` provides filtering and aggregation logic.

### Analytics Actions

#### Entry Retrieval
- **GetAllEntriesAction** - Comprehensive entry listing with filters
- **GetLastEntriesAction** - Recent entries for dashboard display

#### Grouping and Aggregation
- **GroupByUserAction** - Time analysis by user with quotas
- **GroupByCustomerAction** - Customer-based time allocation
- **GroupByProjectAction** - Project time tracking and billing
- **GroupByActivityAction** - Activity-based time analysis
- **GroupByTicketAction** - Ticket-level time tracking
- **GroupByWorktimeAction** - Worktime pattern analysis

#### BaseInterpretationController Features
- **Filtering**: Date ranges, entity-based filters, user scoping
- **Aggregation**: Duration summation, quota calculations
- **Date Handling**: Month/year ranges, interval calculations
- **Authorization**: DEV users limited to own data

---

## Default Controllers (16 Actions)

**Purpose**: General application endpoints, data retrieval, and export functionality.
**Security**: User-level permissions with contextual data scoping.

### Application Core
- **IndexAction** - Main application entry point, renders dashboard
- **JiraOAuthCallbackAction** - OAuth callback handler for JIRA integration

### Data Retrieval Actions
- **GetDataAction** - Primary data endpoint for frontend
- **GetCustomerAction** - Single customer details
- **GetCustomersAction** - Customer list for current user
- **GetUsersAction** - User list with role-based filtering
- **GetProjectsAction** - Project list for user context
- **GetAllProjectsAction** - Complete project catalog
- **GetProjectStructureAction** - Hierarchical project data
- **GetActivitiesAction** - Available activities for user

### Summary and Analytics
- **GetSummaryAction** - Dashboard summary statistics
- **GetTimeSummaryAction** - Time aggregation reports
- **GetTicketTimeSummaryAction** - Ticket-based time analysis
- **GetTicketTimeSummaryJsAction** - JavaScript-optimized ticket summaries
- **GetHolidaysAction** - Holiday calendar integration

### Export Functionality
- **ExportCsvAction** - CSV export for time entries

---

## Other Controllers

### SecurityController
- **Purpose**: Authentication and session management
- **Actions**:
  - `login()` - Login form rendering and authentication
  - `logout()` - Session termination and cleanup
- **Features**: Symfony Security integration, session handling

### ControllingController (Legacy)
- **Purpose**: Back-compatibility for spreadsheet operations
- **Status**: Deprecated, functionality moved to `Controlling\ExportAction`
- **Methods**: Static helpers for Excel date/time formatting

### Settings Controllers (1 Action)
- **SaveSettingsAction** - User preference persistence and validation

### Status Controllers (2 Actions)
- **CheckStatusAction** - Login status verification for frontend
- **PageAction** - Application status page rendering

---

## Routing Conventions

### Route Naming Patterns
- **Admin Routes**: `/admin/{entity}/{action}` format
- **Tracking Routes**: `/tracking/{action}` format
- **Interpretation Routes**: `/interpretation/{grouping}` format
- **API Routes**: JSON responses for frontend consumption

### HTTP Methods
- **GET**: Data retrieval and page rendering
- **POST**: Entity creation and updates
- **DELETE**: Entity removal

### Route Attributes
All routes use Symfony `#[Route]` attributes with:
- `path`: URL pattern
- `name`: Route identifier for URL generation
- `methods`: HTTP method restrictions

---

## Response Patterns

### Response Types
- **JsonResponse**: API endpoints returning structured data
- **Response**: Custom response wrapper with status codes
- **Error**: Structured error responses with translation
- **RedirectResponse**: Navigation and authentication redirects
- **Symfony Response**: Template rendering for UI pages

### Error Handling
- **Authentication**: 401 with login redirect
- **Authorization**: 403 with translated error messages
- **Validation**: 400/422 with detailed field errors
- **Not Found**: 404 for missing entities
- **Server Error**: 500 for system failures

---

## Key Dependencies and Services

### Shared Dependencies (BaseController)
- **ManagerRegistry**: Doctrine entity management
- **TranslatorInterface**: Multi-language support
- **ParameterBagInterface**: Configuration access
- **KernelInterface**: Environment detection

### Domain-Specific Dependencies
- **JIRA Integration**: `JiraOAuthApiFactory` for ticket system connectivity
- **Time Calculation**: `TimeCalculationService` for duration formatting
- **Ticket Management**: `TicketService` for ticket parsing and validation
- **Logging**: Domain-specific loggers for operation tracking

### DTO Integration
Controllers use Data Transfer Objects (DTOs) with:
- **MapRequestPayload**: Automatic request mapping and validation
- **Custom Validators**: Entity-specific business rule enforcement
- **Type Safety**: Strict typing for all controller parameters

---

## Security Implementation

### Authentication Flow
1. **Login Check**: `checkLogin()` or `isLoggedIn()` verification
2. **User Resolution**: `getUserId()` with fallback mechanisms
3. **Role Validation**: `isPl()` and `isDEV()` for role-based access

### Authorization Patterns
- **Admin Operations**: Strict PL role requirement
- **User Data**: Owner-based access control
- **Shared Resources**: Role-based filtering

### CSRF Protection
- Stateless CSRF protection for API endpoints
- Form-based CSRF for traditional forms
- Token validation in BaseController

---

This controller architecture demonstrates a well-organized, security-conscious approach to web application development with clear separation of concerns, consistent patterns, and comprehensive business logic encapsulation.