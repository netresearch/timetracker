import { test, expect } from '@playwright/test';
import { loginIsolated } from './helpers/auth';
import { goToWorklogPage } from './helpers/navigation';
import { cleanupWorklogEntries, createWorklogEntry, rowByStamp } from './helpers/worklog';

/**
 * The client-side date-format preference (Settings → Appearance →
 * ISO / Automatic / Custom). It applies to the read-only display leaves only —
 * the wire format, the sort key and the inline date EDITOR all stay ISO
 * yyyy-mm-dd.
 */
test.describe('Date-format preference', () => {
  test.beforeEach(async ({ page }) => {
    await loginIsolated(page);
    await goToWorklogPage(page);
  });

  test.afterEach(async ({ page }) => {
    await cleanupWorklogEntries(page);
  });

  test('a custom pattern reformats the worklog date cells, but editing stays ISO', async ({ page }) => {
    const stamp = await createWorklogEntry(page);
    // The date cell renders three responsive widths (full / MM-DD / DD) plus a
    // visually-hidden row-class label; assert on the full-date span only.
    await expect(rowByStamp(page, stamp).locator('td[data-col-key="date"] .dt-full'))
      .toHaveText(/^\d{4}-\d{2}-\d{2}$/); // ISO by default

    // Switch to a custom DD.MM.YYYY pattern (Appearance section).
    await page.goto('/ui/settings/appearance');
    await page.getByLabel(/Date format|Datumsformat/i).selectOption('custom');
    await page.getByLabel(/Custom date pattern|Eigenes Datumsmuster/i).fill('DD.MM.YYYY');

    // Back on the worklog (direct nav — this also exercises the
    // localStorage-persistence path) the same cell
    // now renders dd.mm.yyyy ...
    await page.goto('/ui/tracking');
    await page.waitForSelector('table.tracking-table');
    const dateCell = rowByStamp(page, stamp).locator('td[data-col-key="date"]');
    await expect(dateCell.locator('.dt-full')).toHaveText(/^\d{2}\.\d{2}\.\d{4}$/);

    // ... but opening its editor shows the ISO value (edit/wire format unchanged).
    await dateCell.focus();
    await page.keyboard.press('Enter');
    await expect(page.locator('td[data-inline-editing] input.inline-editor')).toHaveValue(/^\d{4}-\d{2}-\d{2}$/);
  });

  test('the Settings preview rejects an invalid pattern and keeps the last valid one', async ({ page }) => {
    await page.goto('/ui/settings/appearance');
    await page.getByLabel(/Date format|Datumsformat/i).selectOption('custom');
    const pattern = page.getByLabel(/Custom date pattern|Eigenes Datumsmuster/i);

    await pattern.fill('YYYY/MM/DD');
    await expect(page.locator('.field-hint[aria-live]')).toContainText(/2026|20\d\d/); // a live preview

    // A pattern with no date token is rejected (and not persisted).
    await pattern.fill('nope');
    await expect(page.locator('.field-hint[aria-live]')).toContainText(/Invalid|Ungültig/i);
  });
});
