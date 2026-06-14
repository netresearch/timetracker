import { test, expect } from '@playwright/test';
import { installFrozenClock } from './helpers/clock';
import * as fs from 'node:fs';
import * as path from 'node:path';

/**
 * The Billing page derives both its default period and the selectable year list
 * from `new Date()` (the page clock). The Controlling export test data lives in
 * January 2026, so this spec freezes to a 2026 date — not the suite-wide
 * 2024-01-15 (E2E_FROZEN_DATE) — so that 2026 is a selectable year and the UI
 * is internally consistent with the data it exports.
 */
const EXPORT_FROZEN_DATE = new Date('2026-01-15T12:00:00');

/**
 * E2E tests for Controlling export functionality.
 *
 * The Controlling/Abrechnung "Exportieren" tab was migrated out of the ExtJS
 * shell into the SolidJS UI (frontend/src/pages/Billing.tsx), served at
 * `/ui/billing` for ROLE_PL / ROLE_ADMIN users. The export is now an anchor
 * (`a.primary-button[download]`) whose href is the query-string route
 * `/controlling/export?...`; clicking it triggers the XLSX download.
 *
 * Tests verify that export works correctly when project/customer filters are
 * "all" (0) — i.e. project=0/customer=0 returns all entries rather than
 * filtering for entries with project_id=0.
 */

// Login as a PL user with the frozen clock installed before navigation.
async function loginWithFrozenClock(page: import('@playwright/test').Page, username: string, password: string) {
  await installFrozenClock(page, EXPORT_FROZEN_DATE);

  await page.goto('/login');
  await page.waitForSelector('input[name="_username"]', { timeout: 10000 });
  await page.locator('input[name="_username"]').fill(username);
  await page.locator('input[name="_password"]').fill(password);
  await page.locator('#form-submit').click();
  await expect(page).toHaveURL('/', { timeout: 15000 });
}

test.describe('Controlling Export', () => {
  test('should export entries with all filters set to "all" (no filtering)', async ({ page }) => {
    // Login as PL user who has access to the billing/controlling export
    await loginWithFrozenClock(page, 'i.myself', 'myself123');

    // Navigate to the SolidJS billing page and wait for the export form
    await page.goto('/ui/billing');
    await page.waitForSelector('form.stack-form', { timeout: 15000 });

    // User/Project/Customer default to "all" (0). The export test data lives in
    // January 2026, so pin year/month explicitly rather than relying on the
    // page's "current period" defaults (which, under the frozen 2024 clock,
    // would otherwise query an empty period). The form has five selects in DOM
    // order: user, project, customer, year, month.
    const selects = page.locator('form.stack-form select');
    await selects.nth(3).selectOption('2026'); // year
    await selects.nth(4).selectOption('1'); // month (1 = January)

    // The export anchor carries the same-origin /controlling/export href,
    // recomputed reactively from the selected filters.
    const exportLink = page.locator('a.primary-button[download]');
    await expect(exportLink).toBeVisible({ timeout: 5000 });

    const href = await exportLink.getAttribute('href');
    console.log(`Export href: ${href}`);
    expect(href).toMatch(/^\/controlling\/export\?/);
    expect(href).toContain('year=2026');
    expect(href).toContain('month=1');

    // Set up download listener before clicking the export anchor
    const downloadPromise = page.waitForEvent('download', { timeout: 30000 });

    await exportLink.click();

    // Wait for download
    const download = await downloadPromise;

    // Verify download is an xlsx file
    const filename = download.suggestedFilename();
    console.log(`Downloaded file: ${filename}`);
    expect(filename).toMatch(/\.xlsx$/);

    // Save to temp location and verify file has content
    const downloadPath = path.join('/tmp', filename);
    await download.saveAs(downloadPath);

    const stats = fs.statSync(downloadPath);
    console.log(`Export file size: ${stats.size} bytes`);

    // The template is ~11KB. With the January 2026 test data, expect ~30KB
    expect(stats.size).toBeGreaterThan(25000);
    console.log('Export file size validates data is present (>25KB)');
  });
});
