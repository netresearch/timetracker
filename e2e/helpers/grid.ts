import { Page } from '@playwright/test';

/**
 * Wait for the SolidJS worklog grid to be loaded (the WAI-ARIA grid the
 * use:gridNav directive exposes). A successful login lands on /ui/tracking,
 * which renders this grid.
 */
export async function waitForGrid(page: Page, timeout: number = 15000): Promise<void> {
  await page.waitForSelector('table.tracking-table[role="grid"]', { timeout });
}
