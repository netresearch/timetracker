# E2E Test Coverage Plan

## Overview

This document outlines the comprehensive E2E test coverage plan for the TimeTracker application.

## Current Test Coverage

### Existing Test Files

| File | Coverage |
|------|----------|
| `login.spec.ts` | Login/logout flow, protected routes |
| `entries.spec.ts` | Entry visibility, data display, API format |
| `entry-operations.spec.ts` | Entry CRUD operations, duration calculations |
| `settings.spec.ts` | User settings save/restore |

## Feature Coverage Matrix

### 1. Authentication & Security (✅ Covered)
- [x] Login form display
- [x] Invalid credentials error
- [x] Successful login
- [x] Logout flow
- [x] Protected route redirect
- [ ] Session timeout handling
- [ ] Remember me functionality

### 2. Core Time Tracking (⚠️ Partial)
- [x] Entry grid display
- [x] Entry creation (add new row)
- [x] Duration calculation
- [x] Duration format (H:i string)
- [ ] **Entry editing via UI** (keyboard/mouse)
- [ ] **Entry deletion via UI** (context menu)
- [ ] **Keyboard shortcuts** (add, delete, navigate)
- [ ] Date picker interaction
- [ ] Time input validation
- [ ] **Autocompletion** (customer, project, activity dropdowns)

### 3. Bulk Entry / Presets (❌ Not Covered)
- [ ] Bulk entry button
- [ ] Preset selection
- [ ] Date range for bulk entry
- [ ] Preset application

### 4. Reporting & Analysis (❌ Not Covered)
- [ ] Charts tab access
- [ ] Per-user chart display
- [ ] Per-project chart display
- [ ] Company overview chart
- [ ] **Controlling export** (XLSX download)
- [ ] Date range filters

### 5. Administration (❌ Not Covered)
- [ ] **Customer management** (CRUD)
- [ ] **Project management** (CRUD)
- [ ] **User management** (CRUD, roles)
- [ ] **Team management** (CRUD)
- [ ] **Activity management** (CRUD)
- [ ] **Preset management** (CRUD)
- [ ] **Ticket system management** (CRUD)

### 6. User Settings (✅ Covered)
- [x] Settings tab display
- [x] show_empty_line toggle
- [x] suggest_time toggle
- [x] Settings persistence
- [ ] Locale switching
- [ ] show_future toggle effectiveness

### 7. API Format & Data Integrity (✅ Covered)
- [x] /getData JSON format
- [x] /tracking/save response format
- [x] Duration format (string H:i)
- [x] Entry wrapper format
- [ ] /interpretation/* endpoints
- [ ] /controlling/* endpoints

### 8. User Interface Elements (⚠️ Partial)
- [x] Header work time display
- [x] User badge (status + logout)
- [ ] **Tab navigation** (all tabs)
- [ ] **Grid column sorting**
- [ ] **Grid column filtering**
- [ ] **Pagination** (if applicable)
- [ ] **Error message display**
- [ ] **Success notifications**

### 9. Jira Integration (❌ Not Covered)
- [ ] Jira ticket autocomplete
- [ ] Worklog sync indicator
- [ ] Ticket time summary

## Proposed Test Structure

```
e2e/
├── auth/
│   ├── login.spec.ts          # Login/logout (existing)
│   └── session.spec.ts        # Session management (new)
├── tracking/
│   ├── entries.spec.ts        # Entry display (existing)
│   ├── entry-crud.spec.ts     # Entry operations (existing, enhanced)
│   ├── bulk-entry.spec.ts     # Bulk entry (new)
│   └── keyboard.spec.ts       # Keyboard shortcuts (new)
├── admin/
│   ├── customers.spec.ts      # Customer CRUD (new)
│   ├── projects.spec.ts       # Project CRUD (new)
│   ├── users.spec.ts          # User CRUD (new)
│   ├── teams.spec.ts          # Team CRUD (new)
│   ├── activities.spec.ts     # Activity CRUD (new)
│   └── presets.spec.ts        # Preset CRUD (new)
├── reporting/
│   ├── charts.spec.ts         # Chart display (new)
│   └── export.spec.ts         # Export functionality (new)
├── settings/
│   └── settings.spec.ts       # User settings (existing)
├── ui/
│   ├── navigation.spec.ts     # Tab navigation (new)
│   ├── grid.spec.ts           # Grid interactions (new)
│   └── notifications.spec.ts  # Error/success messages (new)
└── helpers/
    ├── login.ts               # Shared login helper
    ├── grid.ts                # Grid interaction helpers
    └── api.ts                 # API request helpers
```

## Priority Implementation Order

### Phase 1: Core Tracking (High Priority)
1. Entry editing via UI
2. Entry deletion via UI
3. Keyboard shortcuts
4. Autocompletion dropdowns

### Phase 2: Navigation & UI (Medium Priority)
1. Tab navigation
2. Grid sorting/filtering
3. Notifications

### Phase 3: Admin Features (Medium Priority)
1. Customer CRUD
2. Project CRUD
3. Activity CRUD
4. User/Team management

### Phase 4: Reporting (Lower Priority)
1. Charts display
2. Export functionality

### Phase 5: Advanced Features (Lower Priority)
1. Bulk entry
2. Jira integration
3. Session management

## Test Data Requirements

### LDAP Users (from docker/ldap/dev-users.ldif)
- `developer` / `dev123` - DEV role
- `unittest` / `test123` - Test user
- `i.myself` / `myself123` - Another user

### Database Requirements

**For E2E tests to work, users must exist in BOTH:**
1. LDAP (ldap-dev container) - for authentication
2. TimeTracker database - for authorization

**Current Setup:**
- `unittest` database (db_unittest): Has test users (unittest, developer, i.myself)
- `timetracker` database (db): Has production users

**To run E2E tests locally:**
1. Ensure LDAP_CREATE_USER=true in .env (default)
2. First login attempt will create user in database
3. OR manually add test users to database

### Database Fixtures (from sql/unittest/)
- Test customers
- Test projects
- Test activities
- Test entries

## Known Limitations

1. **LDAP Authentication**: E2E tests require LDAP container running and test users configured
2. **Database Users**: Test users must exist in both LDAP and application database
3. **Session State**: Tests that require login may fail if LDAP isn't properly configured

## Success Criteria

1. All core user flows work end-to-end
2. No regressions in existing functionality
3. Test coverage for all admin CRUD operations
4. Export functionality verified
5. All tests run in CI pipeline
