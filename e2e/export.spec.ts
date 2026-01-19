import { test, expect } from '@playwright/test';
import { login } from './helpers/auth';
import { waitForGrid } from './helpers/grid';
import { goToTab, TABS } from './helpers/navigation';
import * as fs from 'fs';
import * as path from 'path';

/**
 * E2E tests for Controlling export functionality.
 * Tests verify that export works correctly when project/customer filters are "all" (0).
 *
 * This test specifically verifies the fix for the bug where project=0/customer=0
 * was incorrectly filtering for entries with project_id=0 instead of returning all entries.
 */
test.describe('Controlling Export', () => {
  test('should export entries with all filters set to "all" (no filtering)', async ({ page }) => {
    // Login as PL user who has access to Controlling tab
    await login(page, 'i.myself', 'myself123');
    await waitForGrid(page);

    // Navigate to Controlling tab (Abrechnung)
    await goToTab(page, TABS.controlling);
    await page.waitForTimeout(1000);

    // Verify we're on the controlling tab - look for the export form header
    await expect(page.locator('.x-panel-header-text').filter({ hasText: 'Monats-Abrechnung' })).toBeVisible({ timeout: 5000 });

    // Hide Symfony debug toolbar if present (can block clicks)
    await page.evaluate(() => {
      const toolbar = document.querySelector('.sf-toolbar');
      if (toolbar) (toolbar as HTMLElement).style.display = 'none';
    });

    // User, Project, Customer dropdowns are empty by default = "all"
    // Month defaults to current month, Year to current year (2026)
    // Test data now includes entries for January 2026

    // Set up download listener before clicking export
    const downloadPromise = page.waitForEvent('download', { timeout: 30000 });

    // Click Export button
    await page.getByRole('button', { name: 'Exportieren' }).click();

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
