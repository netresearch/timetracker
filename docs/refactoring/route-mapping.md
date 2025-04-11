# Route Migration Reference

This document tracks the migration of routes from the original controllers to the new refactored controllers.

## Migrated Routes

| Original Route | Original Controller | New Route | New Controller | Status |
|----------------|---------------------|-----------|---------------|--------|
| `/admin/ticketsystems` | `AdminController::getTicketSystemsAction` | `/admin/ticketsystems` | `TicketSystemController::getTicketSystemsAction` | Ready to remove |
| `/admin/ticketsystem/save` | `AdminController::saveTicketSystemAction` | `/admin/ticketsystem/save` | `TicketSystemController::saveTicketSystemAction` | Ready to remove |
| `/admin/ticketsystem/delete` | `AdminController::deleteTicketSystemAction` | `/admin/ticketsystem/delete` | `TicketSystemController::deleteTicketSystemAction` | Ready to remove |
| `/admin/activity/save` | `AdminController::saveActivityAction` | `/admin/activity/save` | `ActivityController::saveActivityAction` | Ready to remove |
| `/admin/activity/delete` | `AdminController::deleteActivityAction` | `/admin/activity/delete` | `ActivityController::deleteActivityAction` | Ready to remove |
| `/admin/presets` | `AdminController::getPresetsAction` | `/admin/presets` | `PresetController::getPresetsAction` | Ready to remove |
| `/admin/preset/save` | `AdminController::savePresetAction` | `/admin/preset/save` | `PresetController::savePresetAction` | Ready to remove |
| `/admin/preset/delete` | `AdminController::deletePresetAction` | `/admin/preset/delete` | `PresetController::deletePresetAction` | Ready to remove |
| `/admin/contracts` | `AdminController::getContractsAction` | `/admin/contracts` | `ContractController::getContractsAction` | Ready to remove |
| `/admin/contract/save` | `AdminController::saveContractAction` | `/admin/contract/save` | `ContractController::saveContractAction` | Ready to remove |
| `/admin/contract/delete` | `AdminController::deleteContractAction` | `/admin/contract/delete` | `ContractController::deleteContractAction` | Ready to remove |
| `/crud/delete` | `CrudController::deleteAction` | `/crud/delete` | `TimeEntryController::deleteAction` | Ready to remove |
| `/crud/save` | `CrudController::saveAction` | `/crud/save` | `TimeEntryController::saveAction` | Ready to remove |
| `/crud/bulkentry` | `CrudController::bulkentryAction` | `/crud/bulkentry` | `TimeEntryController::bulkentryAction` | Ready to remove |

## Routes Still to Migrate

| Route | Controller | Target Controller | Status |
|-------|------------|------------------|--------|
| `/admin/customers` | `AdminController::getCustomersAction` | `CustomerController` | Already implemented |
| `/admin/users` | `AdminController::getUsersAction` | `UserController` | Already implemented |
| `/admin/teams` | `AdminController::getTeamsAction` | `TeamController` | Already implemented |
| `/admin/project/save` | `AdminController::saveProjectAction` | `ProjectController` | Already implemented |
| `/admin/project/delete` | `AdminController::deleteProjectAction` | `ProjectController` | Already implemented |
| `/admin/sync-all-subtasks` | `AdminController::syncAllProjectSubticketsAction` | `ProjectController` | To be migrated |
| `/admin/sync-subtasks` | `AdminController::syncProjectSubticketsAction` | `ProjectController` | To be migrated |
| `/admin/customer/save` | `AdminController::saveCustomerAction` | `CustomerController` | Already implemented |
| `/admin/customer/delete` | `AdminController::deleteCustomerAction` | `CustomerController` | Already implemented |
| `/admin/user/save` | `AdminController::saveUserAction` | `UserController` | Already implemented |
| `/admin/user/delete` | `AdminController::deleteUserAction` | `UserController` | Already implemented |
| `/admin/team/save` | `AdminController::saveTeamAction` | `TeamController` | Already implemented |
| `/admin/team/delete` | `AdminController::deleteTeamAction` | `TeamController` | Already implemented |
| `/admin/jirasync` | `AdminController::jiraSyncEntriesAction` | `JiraSyncController` | To be created |

## Safe Removal Plan

1. Update frontend code to point to new endpoints (if needed)
2. Add tests for the new controllers
3. Test each endpoint manually
4. Mark as "Verified" in this document
5. Remove the old method from the original controller
6. Test again to ensure everything still works

## Progress

- [x] Create controllers and services for entities
- [ ] Update frontend code
- [ ] Test new endpoints
- [ ] Remove duplicate code from original controllers
- [ ] Add more tests
