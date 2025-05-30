# Route Migration Reference

This document tracks the migration of routes from the original controllers to the new refactored controllers.

## Migrated Routes

| Original Route | Original Controller | New Route | New Controller | Status |
|----------------|---------------------|-----------|---------------|--------|
| `/getTicketSystems` | `AdminController::getTicketSystemsAction` | `/ticketsystems` | `TicketSystemController::getTicketSystemsAction` | Completed ✅ |
| `/ticketsystem/save` | `AdminController::saveTicketSystemAction` | `/ticketsystem/save` | `TicketSystemController::saveTicketSystemAction` | Completed ✅ |
| `/ticketsystem/delete` | `AdminController::deleteTicketSystemAction` | `/ticketsystem/delete` | `TicketSystemController::deleteTicketSystemAction` | Completed ✅ |
| `/getActivities` | `DefaultController::getActivitiesAction` | `/activities` | `ActivityController::getActivitiesAction` | Completed ✅ |
| `/activity/save` | `AdminController::saveActivityAction` | `/activity/save` | `ActivityController::saveActivityAction` | Completed ✅ |
| `/activity/delete` | `AdminController::deleteActivityAction` | `/activity/delete` | `ActivityController::deleteActivityAction` | Completed ✅ |
| `/getAllPresets` | `AdminController::getPresetsAction` | `/presets` | `PresetController::getPresetsAction` | Completed ✅ |
| `/preset/save` | `AdminController::savePresetAction` | `/preset/save` | `PresetController::savePresetAction` | Completed ✅ |
| `/preset/delete` | `AdminController::deletePresetAction` | `/preset/delete` | `PresetController::deletePresetAction` | Completed ✅ |
| `/getContracts` | `AdminController::getContractsAction` | `/contracts` | `ContractController::getContractsAction` | Completed ✅ |
| `/contract/save` | `AdminController::saveContractAction` | `/contract/save` | `ContractController::saveContractAction` | Completed ✅ |
| `/contract/delete` | `AdminController::deleteContractAction` | `/contract/delete` | `ContractController::deleteContractAction` | Completed ✅ |
| `/tracking/delete` | `CrudController::deleteAction` | `/crud/delete` | `TimeEntryController::deleteAction` | Completed ✅ |
| `/tracking/save` | `CrudController::saveAction` | `/crud/save` | `TimeEntryController::saveAction` | Completed ✅ |
| `/tracking/bulkentry` | `CrudController::bulkentryAction` | `/crud/bulkentry` | `TimeEntryController::bulkentryAction` | Completed ✅ |

## Routes Still to Migrate

| Route | Controller | Target Controller | Status |
|-------|------------|------------------|--------|
| `/syncentries/jira` | `AdminController::jiraSyncEntriesAction` | `JiraSyncController` | To be created |

## Safe Removal Plan

1. Update frontend code to point to new endpoints (if needed)
2. Add tests for the new controllers
3. Test each endpoint manually
4. Mark as "Verified" in this document
5. Remove the old method from the original controller
6. Test again to ensure everything still works

## Progress

- [x] Create controllers and services for entities
- [x] Remove duplicate code from AdminController for:
  - [x] Ticket systems
  - [x] Activities
  - [x] Presets
  - [x] Contracts
- [x] Remove duplicate code from CrudController
- [ ] Create JiraSyncController for migrating `/syncentries/jira`
- [ ] Update frontend code
- [ ] Test new endpoints
- [ ] Add more tests
