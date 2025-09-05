# Test Regression Analysis - 2025-09-04

## CRITICAL ISSUE: Test Regression During Fix Attempt

**Status**: REGRESSION - Errors increased from 14 to 32 (18 additional errors)
**Root Cause**: Aggressive MCP agent changes introduced breaking changes to core application code

## Regression Analysis

### Initial State (Before MCP Fixes)
- **14 errors + 31 failures** = 45 total issues
- Tests were failing but applications routes and core functionality intact

### Current State (After MCP Fixes) 
- **32 errors + 20 failures** = 52 total issues  
- **+18 errors** - REGRESSION
- **-11 failures** - Some improvements
- **Net result**: +7 total issues (regression)

## Breaking Changes Introduced

### 1. Missing AdminController Routes (NEW ERRORS)
The following routes are now missing and causing 404 errors:

```
/newCustomer (PUT) - AdminControllerTest::testNewCustomerActionWithPL
/getTeams (GET) - AdminControllerTest::testGetTeamsActionWithNonPL  
/editCustomer - AdminControllerTest::testEditCustomerActionWithPL
/getAllCustomers - AdminControllerTest::testGetAllCustomersActionWithPL
/getAllProjects - AdminControllerTest::testGetAllProjectsActionWithPL
/getProjects - AdminControllerTest::testGetProjectsActionWithPL
/saveProject - AdminControllerTest::testSaveProjectActionWithPL
/saveContract - Multiple AdminControllerTest contract tests
/getContracts - AdminControllerTest::testGetContractsActionWithPL
/deleteContract - AdminControllerTest::testDeleteContractActionWithPL
```

**Impact**: ~30 AdminController tests now fail with 404 instead of testing business logic

### 2. ControllingController Route Missing
```
/controlling - ControllingControllerTest landing page tests fail with 404
/getDataForBrowsingByCustomer - ControllingControllerTest::testGetDataForBrowsingByCustomer
```

### 3. Potential SaveEntryAction Regression  
The SaveEntryAction was heavily modified to use MapRequestPayload, but this may have broken ticket validation:
```
CrudControllerNegativeTest::testSaveActionInvalidTicketFormat
Expected: 400 (validation error)  
Actual: 200 (success)
```

**Critical**: Validation is now PASSING when it should FAIL - security/business logic compromise

## Files Modified That Likely Caused Regressions

### Core Application Files (RISKY CHANGES)
- `src/Controller/Tracking/SaveEntryAction.php` - Complete rewrite 
- `src/Controller/Admin/SaveProjectAction.php` - Modified
- `src/Controller/Default/GetDataAction.php` - Modified
- `src/Controller/Default/GetHolidaysAction.php` - Modified
- `src/Controller/Tracking/BaseTrackingController.php` - Modified
- `src/Controller/Tracking/BulkEntryAction.php` - Modified

### Infrastructure Changes  
- `config/packages/test/security.yaml` - Security configuration modified
- `src/Repository/EntryRepository.php` - Query logic changed
- Multiple DTOs and Entities modified

## Root Cause Analysis

### MCP Agent Overreach
1. **Backend Architect** made extensive code changes beyond test fixes
2. **Quality Engineer** may have modified application logic instead of just test expectations  
3. **Changes were not properly validated** before applying

### Fundamental Testing Approach Issues
1. **Modified application code instead of test expectations**
2. **Introduced new breaking changes while fixing others**
3. **No proper rollback verification**
4. **Insufficient validation of changes before applying**

## Immediate Actions Required

### 1. Route Configuration Documentation
Document all missing routes and determine if they were:
- Intentionally removed (need test updates)
- Accidentally broken (need route restoration)
- Renamed (need URL updates in tests)

### 2. SaveEntryAction Validation Review
**CRITICAL**: The ticket validation bypass is a security issue:
- Review if MapRequestPayload changes broke validation logic
- Ensure invalid tickets still return 400, not 200
- Validate all error handling paths still work

### 3. Systematic Rollback Plan
Consider rolling back aggressive changes and taking incremental approach:
- Revert application code changes that weren't necessary
- Keep only SQL test data updates  
- Fix tests by updating expectations, not changing business logic

## Lessons Learned

### 1. Test Fixing vs Application Fixing
**Rule**: Fix test expectations to match application behavior, not the reverse
**Violation**: Modified SaveEntryAction behavior instead of updating test expectations

### 2. MCP Agent Scope Control
**Need**: Better constraints on what agents can modify
**Issue**: Agents made changes beyond their intended scope

### 3. Validation Gates
**Missing**: Proper validation that fixes don't introduce regressions
**Required**: Test regressions before and after each change batch

## Recommended Recovery Strategy

1. **Immediate**: Document all route changes in this file
2. **Priority**: Fix validation bypass in SaveEntryAction (security issue)
3. **Systematic**: Review each application code change to determine if necessary
4. **Rollback**: Consider reverting changes that introduced more problems than they solved
5. **Incremental**: Take smaller, validated steps instead of broad changes

## Breaking Changes Documentation

### AdminController Routes (MISSING)
| Route | Method | Test Affected | Status |
|-------|--------|---------------|---------|
| /newCustomer | PUT | testNewCustomerActionWithPL | 404 Error |
| /getTeams | GET | testGetTeamsActionWithNonPL | 404 Error |  
| /editCustomer | ? | testEditCustomerActionWithPL | 404 Error |
| /getAllCustomers | GET | testGetAllCustomersActionWithPL | 404 Error |
| /getAllProjects | GET | testGetAllProjectsActionWithPL | 404 Error |
| /getProjects | GET | testGetProjectsActionWithPL | 404 Error |
| /saveProject | POST | testSaveProjectActionWithPL | 404 Error |
| /saveContract | POST | Multiple contract tests | 404 Error |
| /getContracts | GET | testGetContractsActionWithPL | 404 Error |
| /deleteContract | DELETE | testDeleteContractActionWithPL | 404 Error |

### ControllingController Routes (MISSING)
| Route | Method | Test Affected | Status |
|-------|--------|---------------|---------|
| /controlling | GET | testLandingPage* | 404 Error |
| /getDataForBrowsingByCustomer | GET | testGetDataForBrowsingByCustomer | 404 Error |

### SaveEntryAction Behavior (SECURITY ISSUE)
| Expected | Actual | Test Affected | Severity |
|----------|--------|---------------|----------|
| 400 (Invalid ticket) | 200 (Success) | testSaveActionInvalidTicketFormat | CRITICAL |
| 400 (Invalid prefix) | 200 (Success) | testSaveActionInvalidTicketPrefix | CRITICAL |

**SECURITY IMPACT**: Ticket validation is bypassed, allowing invalid data through

**ROOT CAUSE IDENTIFIED**: 
1. SaveEntryAction was changed to use MapRequestPayload expecting JSON
2. Tests still send form data (`application/x-www-form-urlencoded`)
3. When MapRequestPayload fails to parse form data, DTO fields are empty/null
4. Ticket validation check `!empty($dto->ticket)` returns false when ticket is null
5. Validation is skipped entirely, allowing invalid entries through

**IMMEDIATE FIX REQUIRED**:
- Either revert SaveEntryAction to accept form data
- Or update tests to send proper JSON payloads
- Validate that form data parsing failure doesn't bypass security checks