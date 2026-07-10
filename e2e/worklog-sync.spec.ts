import { test, expect, Page } from '@playwright/test';
import { login } from './helpers/auth';
import { waitForGrid } from './helpers/grid';
import { goToAdminPage, goToSettingsPage } from './helpers/navigation';

/**
 * E2E smoke for the worklog-sync UI (ADR-023 §6, Phase 4b).
 *
 * The engine (import/sync/verify + conflict resolution) is unit-, functional-
 * and real-Jira-tested already; the e2e stack has NO Jira, so this spec is a
 * thin presence/gating smoke, not a sync-result check. It covers:
 *   - the admin sync area is ROLE_ADMIN-gated (non-admin redirected away),
 *   - an admin reaches it via the Administration sub-nav and sees its regions,
 *   - the trigger form gates submission until a ticket system is chosen,
 *   - the Settings self-service import section renders its controls for a
 *     plain user.
 *
 * The admin sync area is a non-CRUD Administration sub-page served at
 * `/ui/admin/worklog-sync` (frontend/src/pages/WorklogSync.tsx, registered in
 * Admin.tsx); self-service import lives in Settings
 * (frontend/src/components/WorklogImportSection.tsx). The e2e env renders
 * GERMAN by default, so assertions match a German-or-English regex.
 */

// German (default) or English labels for the strings this smoke asserts on.
const ADMIN_TITLE = /^(Worklog-Synchronisation|Worklog sync)$/i;
const TRIGGER = /^(Lauf auslösen|Trigger a run)$/i;
const RUN_HISTORY = /^(Lauf-Historie|Run history)$/i;
const IMPORT_TITLE = /Jira-Zeiten importieren|Import Jira worklogs/i;
const PREVIEW = /^(Vorschau|Preview)$/i;

function worklogSyncNavLink(page: Page) {
  return page.locator('button.admin-subnav-link').filter({ hasText: ADMIN_TITLE }).first();
}

test.describe('Worklog sync gating', () => {
  test('redirects a non-admin away from the admin sync area', async ({ page }) => {
    // `developer` is a plain user (no ROLE_ADMIN); the guarded route sends them
    // back to the month view instead of rendering the admin area.
    await login(page);
    await page.goto('/ui/admin/worklog-sync');
    await page.waitForURL(/\/ui\/month/, { timeout: 15000 });
    await expect(page.locator('section.admin-page')).toHaveCount(0);
    await expect(page.locator('.worklog-sync')).toHaveCount(0);
  });
});

test.describe('Worklog sync — admin area', () => {
  test.beforeEach(async ({ page }) => {
    // i.myself is type PL → ROLE_ADMIN.
    await login(page, 'i.myself', 'myself123');
    await waitForGrid(page);
    await goToAdminPage(page);
  });

  test('reaches the sync area via the admin sub-nav and shows its regions', async ({ page }) => {
    const link = worklogSyncNavLink(page);
    await expect(link).toBeVisible();
    await link.click();

    await page.waitForURL(/\/ui\/admin\/worklog-sync/, { timeout: 10000 });
    await expect(link).toHaveAttribute('aria-current', 'page');
    await expect(page.locator('.worklog-sync')).toBeVisible();

    // Region headings: trigger + run history (conflicts region also present).
    await expect(page.getByRole('heading', { name: TRIGGER })).toBeVisible();
    await expect(page.getByRole('heading', { name: RUN_HISTORY })).toBeVisible();
  });

  test('gates the trigger until a ticket system is chosen', async ({ page }) => {
    await worklogSyncNavLink(page).click();
    await page.waitForURL(/\/ui\/admin\/worklog-sync/, { timeout: 10000 });

    // No ticket system is selected on first render, so the trigger button is
    // disabled (the form's built-in validation — a run needs a target Jira).
    const triggerButton = page.getByRole('button', { name: TRIGGER });
    await expect(triggerButton).toBeDisabled();
  });
});

test.describe('Worklog sync — self-service import', () => {
  test('renders the import controls in Settings for a plain user', async ({ page }) => {
    await login(page);
    await waitForGrid(page);
    await goToSettingsPage(page);

    // The import section is self-service (no ROLE_ADMIN gate): its fieldset
    // legend, the ticket-system select and the Preview (dry-run) button render.
    const section = page.locator('fieldset.settings-group').filter({ hasText: IMPORT_TITLE });
    await expect(section).toBeVisible();
    await expect(section.locator('select').first()).toBeVisible();
    await expect(section.getByRole('button', { name: PREVIEW })).toBeVisible();
  });
});
