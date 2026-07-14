# Design: User Settings — Full Page with Section Navigation

Date: 2026-07-13
Status: Draft (awaiting review)
Related: ADR-004, ADR-014, ADR-016, ADR-018, ADR-021, ADR-023, ADR-024

## 1. Context and goals

The user settings screen (`/ui/settings`) is currently a modal (`PageDialog` rendered over the last full page via `MODAL_SEGMENTS` in `frontend/src/App.tsx`). Inside, it is a flat scrolling stack of five fieldsets with three different, invisible save models (server form with Save button, per-action endpoints, instant localStorage). Contextual help exists only as `field-hint` small text; there is no tooltip/popover help component. Wide sub-UIs (API token scope grid, worklog import preview, backup-code display) do not fit a dialog well.

Goals:

1. Split the screen into clearly bounded sections, each deep-linkable.
2. Convert from modal to a full page.
3. Make the save model of each section visible.
4. Introduce a reusable contextual-help component and use it for the explanation-heavy topics (passkeys, token scopes, sync semantics).

Non-goals (out of scope):

- A "quick settings" popover on the header gear (former proposal C) — can be layered on later without rework.
- New settings or backend features beyond the one partial-save change described in §6.
- Changing Help or Billing, which remain modal.

## 2. Decision summary

Settings becomes a full page at `/settings/:section?`, following the existing `/admin/:entity?` pattern. A section navigation (sidebar on desktop, horizontal tab strip on mobile) switches between five sections. `'settings'` is removed from `MODAL_SEGMENTS`; the header gear link and command-palette entry keep working unchanged (they navigate to `/ui/settings`, which renders the default section).

## 3. Information architecture

| Route | Nav label (de) | Content | Save model (made visible) |
|---|---|---|---|
| `/settings/account` (default) | Konto & Erfassung | Locale, show empty line, suggest time, show future, minimum entry duration | Form + Save button |
| `/settings/appearance` | Darstellung | Font family, font size, navigation layout, date format, grid Enter behavior | Instant, device-local — badge "applies immediately on this device" |
| `/settings/security` | Sicherheit | Password change (local accounts), 2FA/TOTP, passkeys | Per action |
| `/settings/tokens` | API-Tokens | Personal access tokens: create with scopes/expiry, list, revoke | Per action |
| `/settings/sync` | Synchronisation | Jira worklog import (dry run + execute), per-ticket-system sync preferences, **Personio sync opt-in (moved here from Account)** | Per action / small forms |

Unknown `:section` values render the default section (`account`) — same tolerant behavior as Admin.

Rationale for the moves:

- API tokens leave the Security card: they are an integration/automation concern with a large scope grid; ADR-021 treats them as their own domain.
- The Personio opt-in is a sync opt-in (ADR-024), not an entry-grid preference; it belongs next to the Jira sync preferences. Its disabled-preservation semantics (do not silently flip a persisted opt-in when Personio is unconfigured) are preserved (§9).

## 4. Routing and navigation

- `App.tsx`: change route to `<Route path="/settings/:section?" component={Settings} />`; remove `'settings'` from `MODAL_SEGMENTS` (leaving `help`, `billing`).
- `Settings.tsx` becomes a shell: reads `useParams().section`, renders section nav + the active section component. Navigation uses router links (`<A href>`), so browser back/forward works between sections.
- Page title: the Layout's title effect derives a per-section `document.title` for settings routes — the base `settings_title` plus the active section's nav label (e.g. `Einstellungen – Sicherheit`), reusing the `settings_nav_*` messages. It is computed in the Layout (which reads the `:section` from the location), not raced from the shell, so there is no conflict with the title effect; the same per-section string drives the route-change live-region announcement so a screen-reader user hears which section they opened (WCAG 4.1.3) and each section is a distinct page (WCAG 2.4.2). The active section is additionally conveyed by the nav's `aria-current` and each section's fieldset legend.
- The Twig header gear (`templates/partials/header.html.twig`) and the command-palette `nav-settings` entry continue to point at `/settings` — no change needed; optional follow-up: palette entries per section ("Settings: Security").

Layout: two-column on ≥ 768 px (nav column ~200 px, content column max-width for readable forms), single column with a horizontally scrollable segmented nav below the page heading on small screens. Implemented with new semantic classes (`.settings-page`, `.settings-nav`, `.settings-content`) in `frontend/src/styles/app.css`, consistent with the existing hand-rolled `light-dark()` CSS system (no Tailwind utilities, matching the rest of the settings styles).

## 5. Component structure

```
frontend/src/pages/Settings.tsx            → shell: section nav + routing (small)
frontend/src/components/settings/
  AccountSection.tsx                       → extracted from today's Settings.tsx (form + save)
  AppearanceSection.tsx                    → extracted device-local prefs block
  SecuritySection.tsx                      → moved; loses ApiTokenControls import
  TokensSection.tsx                        → thin wrapper around ApiTokenControls
  SyncSection.tsx                          → composes WorklogImportSection,
                                             WorklogSyncPreferences, PersonioOptIn
  PersonioOptIn.tsx                        → extracted personio checkbox + save
frontend/src/components/HelpPopover.tsx    → new reusable help component (§7)
```

`TwoFactorControls`, `PasskeyControls`, `PasswordChange` stay independently importable — `TwoFactorGate.tsx` (mandatory-2FA enrolment, ADR-018) reuses them and must not be affected. `WorklogImportSection` and `WorklogSyncPreferences` are reused as-is inside `SyncSection`.

Each section component owns its data fetching and save status, so sections stay independently testable and lazily mounted (only the active section renders — today all five fieldsets mount and fetch on open).

## 6. Backend change: v2 settings endpoints (partial update)

Today `SaveSettingsAction` (`POST /settings/save`) persists all six account fields from one form. After the split, the Personio opt-in lives in the Sync section and must be savable without resubmitting the account form.

Instead of retrofitting partial semantics onto the legacy form endpoint, introduce v2 API endpoints following the existing `/api/v2/…` convention (prefix-first versioning, matching `/api/v2/worklog-sync/preferences`, `/api/v2/users`):

- **`GET /api/v2/settings`** — the authenticated user's account settings (implicitly current-user, like the worklog-sync preferences endpoint).
- **`PATCH /api/v2/settings`** — partial update, JSON body; **only the fields present in the payload are persisted**. Returns the updated settings.

`PATCH` is the native HTTP semantics for exactly the partial update the split needs. The account form sends its five fields; `PersonioOptIn` sends only `personio_sync_enabled`. When Personio is not configured, the control is disabled and the field is simply not sent — partial semantics make "not sent = unchanged" a server guarantee (replacing today's special client-side preservation logic).

Controllers live in `src/Controller/Api/V2/` (e.g. `GetSettingsAction`, `UpdateSettingsAction`). Once the frontend is migrated, the legacy `POST /settings/save` and `SaveSettingsAction` are removed in the same phase — the SPA is their only consumer.

Explicitly **not** migrated to v2: the security flows (`/settings/2fa/*`, `/settings/security/passkeys/*`, `/settings/api-tokens/*`). They are session-bound flows; moving them is churn without user-facing gain and out of scope.

## 7. HelpPopover component

A new reusable component built on Ark UI `Popover` (already a dependency; used by `DatePopover`):

- Trigger: small info icon button (ⓘ) rendered after a label or section heading; `aria-label` "Hilfe zu …".
- Content: short rich text from Paraglide messages; may contain a link (e.g. to the Help page or docs).
- A11y: Ark Popover provides focus management; trigger is a real `<button>`; content linked via `aria-describedby`/popover semantics; Esc closes. Verified with `vitest-axe`.
- Usage rule: one-line facts stay `<small class="field-hint">` (existing pattern); explanations that would bloat the form ("What is a passkey?", "What do token scopes mean?", "What does the import dry run do?", "What does the nightly sync do / who can sync all?") move into `HelpPopover`.

Initial help content (new i18n keys, all five locales): passkeys, TOTP + backup codes, token scopes/expiry/one-time reveal, import dry-run, sync opt-in semantics, Personio opt-in, date-format patterns, minimum entry duration.

## 8. Save-model visibility

- **Account**: unchanged form; Save button + status line (`.form-status`).
- **Appearance**: a section-level badge/hint "Änderungen gelten sofort und nur auf diesem Gerät" (new key), replacing the current buried hint. No buttons.
- **Security / Tokens / Sync**: per-action buttons as today; each sub-block keeps its own status message.

## 9. Behavior that must survive unchanged

1. **Locale change reload**: saving a changed locale still triggers a full reload (UI strings are locale-bound at load); reload targets the current section URL.
2. **Personio opt-in preservation**: never silently flip the persisted opt-in when the control is disabled — now guaranteed server-side by PATCH partial semantics (§6).
3. **TwoFactorGate reuse**: `TwoFactorControls`/`PasskeyControls` keep their current props/contract.
4. **Deep-linkability**: `/ui/settings` keeps working (renders account); old bookmarks are not broken. New deep links per section.
5. **LDAP gating**: password change only for `localAccount`; passkey block only when `passkeysSupported()`.
6. **`/.well-known/change-password`**: continues to resolve to the settings password UI — update its target to `/ui/settings/security`.
7. **Esc/close behavior disappears with the modal**: settings is now a page; "closing" is normal navigation. The command palette and header nav remain the way back.

## 10. i18n

- ~10 new keys for section nav labels + page structure, ~10–15 keys for help content and the instant-apply badge; every key added to all five catalogs (`frontend/messages/{en,de,es,fr,ru}.json`).
- Existing `settings_*` keys are reused; keys made obsolete by the restructuring are removed in the same change.

## 11. Testing

- **Unit (Vitest + @solidjs/testing-library)**:
  - Split `Settings.test.tsx` along the new component boundaries; add shell tests: section param → correct section rendered, unknown section → account, nav links present.
  - `HelpPopover`: opens/closes, trigger a11y, `vitest-axe` clean.
  - `PersonioOptIn`: disabled state sends no request; enabled toggle posts only the personio field.
- **Backend (PHPUnit)**: v2 settings endpoint tests — `GET` returns current values; `PATCH` with full payload (parity with legacy save), single-field payload, absent fields untouched, locale normalization; token-scope gating (`settings:read`/`settings:write`, wrong scope → 403) following the existing `MintsApiTokens` pattern. Extra unknown fields follow the serializer default (ignored) and are not asserted.
- **E2E (Playwright, `e2e/settings.spec.ts`)**: adapt selectors to the page layout; add: deep link to `/ui/settings/security` shows security section; section nav switches content and URL; account save round-trip still passes; appearance change applies instantly.
- Quality gates before PR: `composer analyze`, `composer cs-check`, frontend `lint` + `typecheck` + `test`.

## 12. Risks

- **Feel change**: the gear now performs a full page navigation instead of an overlay; users lose the "peek over my work" behavior. Accepted trade-off; proposal C (quick-settings popover) remains a possible later addition.
- **E2E churn**: `settings.spec.ts` asserts modal semantics today (dialog role, Esc-close); those tests need deliberate rewriting, not mechanical fixing.
- **Locale reload interplay with router**: reload must land on the section URL, not `/ui/` — covered by an e2e assertion.

## 13. Implementation phasing (for the plan)

1. Backend: `GET`/`PATCH /api/v2/settings` + tests (independent, mergeable alone; legacy endpoint untouched).
2. Section split + routing conversion, frontend switches to the v2 endpoints, legacy `/settings/save` removed (the bulk; includes CSS and e2e rewrite).
3. `HelpPopover` + help content + i18n (layers on top; independently mergeable).

Each phase is an atomic PR that keeps CI green.
