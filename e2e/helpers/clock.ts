import { Page, BrowserContext } from '@playwright/test';

/**
 * Fixed test date that matches the E2E test data in sql/testdata.sql
 * and the APP_FROZEN_TIME environment variable in compose.yml
 *
 * 2024-01-15 is a Monday, which ensures predictable week calculations
 */
export const E2E_FROZEN_DATE = new Date('2024-01-15T12:00:00');

/**
 * Install frozen clock on a page before navigation
 *
 * This must be called BEFORE navigating to any page, as the clock
 * is installed when the page loads.
 *
 * @param page - Playwright page to install clock on
 * @param frozenDate - Date to freeze to (defaults to E2E_FROZEN_DATE)
 *
 * @example
 * test('shows entries for frozen date', async ({ page }) => {
 *   await installFrozenClock(page);
 *   await page.goto('/');
 *   // Page now thinks it's 2024-01-15
 * });
 */
export async function installFrozenClock(
  page: Page,
  frozenDate: Date = E2E_FROZEN_DATE
): Promise<void> {
  await page.clock.install({ time: frozenDate });
}

/**
 * Install frozen clock on a browser context (affects all pages)
 *
 * @param context - Playwright browser context
 * @param frozenDate - Date to freeze to (defaults to E2E_FROZEN_DATE)
 */
export async function installFrozenClockOnContext(
  context: BrowserContext,
  frozenDate: Date = E2E_FROZEN_DATE
): Promise<void> {
  // Context-level clock needs to be set via page creation hook
  context.on('page', async (page) => {
    await page.clock.install({ time: frozenDate });
  });
}

/**
 * Get the E2E frozen date as an ISO date string (YYYY-MM-DD)
 */
export function getE2EFrozenDateString(): string {
  return E2E_FROZEN_DATE.toISOString().split('T')[0];
}
