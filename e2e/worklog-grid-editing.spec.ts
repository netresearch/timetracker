import { test, expect } from '@playwright/test';

import { login } from './helpers/auth';
import { goToWorklogPage } from './helpers/navigation';
import { createWorklogEntry, rowByStamp } from './helpers/worklog';

/**
 * Spreadsheet-style keyboard + clipboard editing on the SolidJS worklog grid:
 * Tab walks to the next editable cell staying in edit mode, and Ctrl+C / Ctrl+V
 * copy the focused (non-edit) cell / paste into another via the async clipboard API.
 */
test.describe('Worklog grid — keyboard & clipboard editing', () => {
  test.beforeEach(async ({ page, context }) => {
    await context.grantPermissions(['clipboard-read', 'clipboard-write']);
    await login(page);
    await goToWorklogPage(page);
  });

  test('Tab walks to the next editable cell, staying in edit mode', async ({ page }) => {
    const stamp = await createWorklogEntry(page);
    const row = rowByStamp(page, stamp);

    // Start typing on the start cell → it enters inline edit mode (seeded).
    await row.locator('td[data-col-key="start"]').focus();
    await page.keyboard.press('9');
    await expect(page.locator('td[data-col-key="start"][data-inline-editing] input.inline-editor')).toBeVisible();

    // Tab commits and moves to the next editable cell (end), still in edit mode.
    await page.keyboard.press('Tab');
    await expect(page.locator('td[data-col-key="end"][data-inline-editing] input.inline-editor')).toBeVisible();
  });

  test('Ctrl+C copies the focused cell; Ctrl+V pastes into another, seeding the editor', async ({ page }) => {
    // Ctrl+C/V on a focused (non-edit) cell drive the async clipboard API, which needs
    // a secure context. CI serves the app over plain HTTP on a container hostname, so
    // navigator.clipboard is unavailable there — skip rather than fail. Runs locally
    // (localhost is a secure context) and on prod (HTTPS).
    const secure = await page.evaluate(() => navigator.clipboard?.readText !== undefined);
    test.skip(!secure, 'async clipboard API needs a secure context (CI serves plain HTTP)');

    const stamp = await createWorklogEntry(page);
    const row = rowByStamp(page, stamp);

    // Copy the description cell (its text contains the unique stamp) with the cursor on
    // the cell — not in edit mode.
    await row.locator('td[data-col-key="description"]').focus();
    await page.keyboard.press('Control+c');
    expect(await page.evaluate(() => navigator.clipboard.readText())).toContain(stamp);

    // Paste into the ticket cell → opens the editor seeded with the clipboard text.
    await row.locator('td[data-col-key="ticket"]').focus();
    await page.keyboard.press('Control+v');
    const ticketEditor = page.locator('td[data-col-key="ticket"][data-inline-editing] input.inline-editor');
    await expect(ticketEditor).toBeVisible();
    await expect(ticketEditor).toHaveValue(stamp);
  });
});
