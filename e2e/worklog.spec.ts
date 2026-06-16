import { test, expect } from '@playwright/test';
import { login } from './helpers/auth';
import { goToWorklogPage } from './helpers/navigation';

/**
 * E2E for the SolidJS work-log (Worklog) grid running in parallel with the
 * legacy ExtJS time-tracking grid during the acceptance window.
 */
test.describe('Worklog grid (parallel SolidJS work-log)', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
  });

  test('the Worklog header link opens the read-only SolidJS grid', async ({ page }) => {
    await goToWorklogPage(page);
    // The read-only grid exposes the WAI-ARIA grid role (use:gridNav).
    await expect(page.locator('table.tracking-table[role="grid"]')).toBeVisible();
  });

  test('the legacy "Time tracking" link still reaches the ExtJS grid after Worklog', async ({ page }) => {
    await goToWorklogPage(page);
    // The once-per-session last-view guard must NOT bounce / back to the last
    // /ui view, or the ExtJS grid would become unreachable via its link.
    await page
      .locator('.main-nav a.main-nav-link', { hasText: /Time Tracking|Zeiterfassung/i })
      .first()
      .click();
    await expect(page).toHaveURL('/', { timeout: 10000 });
  });
});
