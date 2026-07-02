# E2E Test Coverage

Playwright suite for the SolidJS UI (served under `/ui`). Run from the repo
root: `npm run e2e` (see `playwright.config.ts`; the app under test is the
`app-e2e` compose service on port 8766, `APP_ENV=test`).

## Suite map

| Spec | Covers |
|------|--------|
| `login.spec.ts` | Login form, invalid credentials, successful login, logout, protected-route redirect |
| `navigation.spec.ts` | Header nav links, icon actions, worktime badges, drawer/overflow structure |
| `worklog.spec.ts` | Worklog grid display at `/ui/tracking`, data rendering |
| `worklog-crud.spec.ts` | Worklog create / edit / save / delete journey |
| `worklog-grid-editing.spec.ts` | Spreadsheet-style keyboard + clipboard editing on the worklog grid |
| `settings.spec.ts` | Settings page save/restore and effect on the worklog grid |
| `date-format.spec.ts` | Date-format preference (ISO / Automatic / Custom) |
| `interpretation.spec.ts` | Evaluation (Auswertung) page + `/interpretation/*` API contracts |
| `export.spec.ts` | Controlling XLSX export from `/ui/billing` (`/controlling/export`) |
| `admin/admin-ui.spec.ts` | Administration CRUD shell for all eight entities at `/ui/admin` |
| `admin-inline-edit.spec.ts` | Inline (spreadsheet-style) cell editing on Administration tables |
| `session-expiry.spec.ts` | Expired-session re-login overlay (issue #408) |
| `error-handling.spec.ts` | JSON API error/contract behaviour |
| `accessibility.spec.ts` | axe-core WCAG gate across the main views |

Helpers live in `e2e/helpers/`: `auth` (login/logout), `grid` (worklog-grid
waits), `navigation` (header link selectors), `worklog`, `api`, `clock`
(frozen time), `date` (d/m/Y ↔ Y-m-d), `index` (re-exports).

## Known gaps

- Bulk entry / preset application flows
- Jira integration flows (ticket autocomplete, worklog sync indicators)
- Locale switching

## Test data requirements

Users must exist in BOTH:

1. LDAP (`docker/ldap/users-only.ldif`, ldap-dev container) — authentication
2. The test database (`sql/unittest/*.sql` fixtures) — authorization

With `LDAP_CREATE_USER=true` (default), the first login creates the database
user automatically.
