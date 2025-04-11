# Controller Analysis for Refactoring

This document maps the responsibilities of major controllers and identifies business logic that should be extracted into separate services.

## CrudController

CrudController is a large controller (857 lines) that handles multiple responsibilities related to time entries.

### Actions and Responsibilities

| Method | Responsibility | Business Logic to Extract | Target Service |
|--------|----------------|---------------------------|---------------|
| `deleteAction` | Delete a time entry, including removing Jira worklog entries | Jira worklog deletion, class calculation | TimeEntryService, WorklogService |
| `deleteJiraWorklog` | Removes a worklog from Jira | Jira API interaction | WorklogService |
| `calculateClasses` | Sets rendering classes for entries (pause, overlap, daybreak) | Time entry classification logic | ClassCalculationService |
| `saveAction` | Creates or updates time entries | Validation, Jira integration, business rules | TimeEntryService, ValidationService |
| `bulkentryAction` | Processes bulk entry creation | Bulk processing logic | TimeEntryService |
| `requireValidTicketFormat` | Validates ticket format | Validation logic | TicketValidationService |
| `requireValidTicketPrefix` | Validates ticket prefix | Validation logic | TicketValidationService |
| `updateJiraWorklog` | Updates Jira worklog | Jira API integration | WorklogService |
| `createTicket` | Creates a ticket in Jira | Jira API integration | TicketService |
| `handleInternalJiraTicketSystem` | Handles internal Jira setup | Jira configuration | JiraConfigurationService |
| `shouldTicketBeDeleted` | Logic to determine if a ticket should be deleted | Business rule | TicketService |

### Dependencies

The CrudController has dependencies on:
- Doctrine ORM
- Translator
- Router
- Logger
- JiraOAuthApi
- TicketHelper

## AdminController

AdminController is a very large controller (1177 lines) handling all administrative functions.

### Actions and Responsibilities

| Method | Responsibility | Business Logic to Extract | Target Service |
|--------|----------------|---------------------------|---------------|
| `getCustomersAction` | Returns customer list | Data retrieval | CustomerService |
| `getUsersAction` | Returns user list | Data retrieval | UserService |
| `getTeamsAction` | Returns team list | Data retrieval | TeamService |
| `getPresetsAction` | Returns preset list | Data retrieval | PresetService |
| `getTicketSystemsAction` | Returns ticket systems list | Data retrieval, permission filtering | TicketSystemService |
| `saveProjectAction` | Creates/updates projects | Validation, entity management | ProjectService |
| `deleteProjectAction` | Deletes projects | Entity deletion, dependency check | ProjectService |
| `syncAllProjectSubticketsAction` | Syncs all subtickets for projects | Jira sync logic | SubticketSyncService |
| `syncProjectSubticketsAction` | Syncs project subtickets | Jira sync logic | SubticketSyncService |
| `saveCustomerAction` | Creates/updates customers | Validation, entity management | CustomerService |
| `deleteCustomerAction` | Deletes customers | Deletion, dependency check | CustomerService |
| `saveUserAction` | Creates/updates users | User management | UserService |
| `deleteUserAction` | Deletes users | User deletion | UserService |
| `deletePresetAction` | Deletes presets | Preset deletion | PresetService |
| `savePresetAction` | Creates/updates presets | Preset management | PresetService |
| `saveTicketSystemAction` | Creates/updates ticket systems | Ticket system configuration | TicketSystemService |
| `deleteTicketSystemAction` | Deletes ticket systems | Ticket system deletion | TicketSystemService |
| `saveActivityAction` | Creates/updates activities | Activity management | ActivityService |
| `deleteActivityAction` | Deletes activities | Activity deletion | ActivityService |
| `saveTeamAction` | Creates/updates teams | Team management | TeamService |
| `deleteTeamAction` | Deletes teams | Team deletion | TeamService |
| `jiraSyncEntriesAction` | Syncs entries with Jira | Jira sync logic | JiraSyncService |
| `getContractsAction` | Returns contracts list | Data retrieval | ContractService |
| `saveContractAction` | Creates/updates contracts | Contract management, validation | ContractService |
| `deleteContractAction` | Deletes contracts | Contract deletion | ContractService |
| `updateOldContract` | Updates old contracts | Contract management | ContractService |
| `checkOldContractsStartDateOverlap` | Checks for overlapping contracts | Validation logic | ContractValidationService |
| `checkOldContractsEndDateOverlap` | Checks for overlapping contracts | Validation logic | ContractValidationService |

### Dependencies

The AdminController has dependencies on:
- Doctrine ORM
- Router
- Translator
- JiraOAuthApi
- SubticketSyncService
- TimeHelper

## Proposed New Controller Structure

Based on the analysis, we propose refactoring into the following controllers:

1. **TimeEntryController** (extracted from CrudController)
   - Responsible for time entries CRUD operations
   - Will use TimeEntryService, WorklogService, ClassCalculationService

2. **TicketController** (extracted from CrudController)
   - Responsible for ticket-related operations
   - Will use TicketService, TicketValidationService

3. **CustomerController** (extracted from AdminController)
   - Responsible for customer management
   - Will use CustomerService

4. **ProjectController** (extracted from AdminController)
   - Responsible for project management
   - Will use ProjectService

5. **UserController** (extracted from AdminController)
   - Responsible for user management
   - Will use UserService

6. **TeamController** (extracted from AdminController)
   - Responsible for team management
   - Will use TeamService

7. **TicketSystemController** (extracted from AdminController)
   - Responsible for ticket system management
   - Will use TicketSystemService

8. **ActivityController** (extracted from AdminController)
   - Responsible for activity management
   - Will use ActivityService

9. **PresetController** (extracted from AdminController)
   - Responsible for preset management
   - Will use PresetService

10. **ContractController** (extracted from AdminController)
    - Responsible for contract management
    - Will use ContractService

## Proposed New Service Structure

Based on the controller responsibilities, we need to create the following services:

1. **TimeEntryService**
   - CRUD operations for time entries
   - Bulk entry creation

2. **ClassCalculationService**
   - Time entry classification (pause, overlap, daybreak)

3. **WorklogService**
   - Jira worklog operations (create, update, delete)

4. **TicketService**
   - Ticket operations

5. **TicketValidationService**
   - Ticket format and prefix validation

6. **CustomerService**
   - Customer management

7. **ProjectService**
   - Project management

8. **UserService**
   - User management

9. **TeamService**
   - Team management

10. **TicketSystemService**
    - Ticket system management

11. **ActivityService**
    - Activity management

12. **PresetService**
    - Preset management

13. **ContractService**
    - Contract management

14. **ContractValidationService**
    - Contract validation (check for overlaps)

## Refactoring Order

1. Start with the TimeEntryService and WorklogService as they're central to the application
2. Create ClassCalculationService
3. Refactor CrudController into TimeEntryController
4. Create entity-specific services for AdminController
5. Refactor AdminController into domain-specific controllers
