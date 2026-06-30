import { test, expect } from '@playwright/test';
import { login } from './helpers/auth';
import { goToWorklogPage } from './helpers/navigation';

/**
 * E2E for the SolidJS work-log (Worklog) grid — the time-tracking UI after the
 * ExtJS shell was removed.
 */
test.describe('Worklog grid', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
  });

  test('the Worklog header link opens the SolidJS grid', async ({ page }) => {
    await goToWorklogPage(page);
    // The grid exposes the WAI-ARIA grid role (use:gridNav).
    await expect(page.locator('table.tracking-table[role="grid"]')).toBeVisible();
  });
});
