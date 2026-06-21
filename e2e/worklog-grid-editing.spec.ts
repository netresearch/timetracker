import { test, expect } from '@playwright/test';

import { login } from './helpers/auth';
import { goToWorklogPage } from './helpers/navigation';
import { createWorklogEntry, rowByStamp } from './helpers/worklog';

/**
 * Spreadsheet-style keyboard + clipboard editing on the SolidJS worklog grid:
 * Tab walks to the next editable cell staying in edit mode, and Ctrl+C / Ctrl+V
 * copy the focused cell / paste into it.
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

  test('copy writes the focused cell text; paste seeds the editor', async ({ page }) => {
    // The copy/paste handlers use the clipboard EVENTS (clipboardData), which work
    // over plain HTTP — unlike navigator.clipboard, which needs a secure context.
    const stamp = await createWorklogEntry(page);
    const row = rowByStamp(page, stamp);

    // Copy: the handler fills the copy event's clipboardData with the cell text.
    const copied = await row.locator('td[data-col-key="description"]').evaluate((cell) => {
      (cell as HTMLElement).focus();
      const data = new DataTransfer();
      cell.dispatchEvent(new ClipboardEvent('copy', { clipboardData: data, bubbles: true, cancelable: true }));

      return data.getData('text/plain');
    });
    expect(copied).toContain(stamp);

    // Paste: a paste event with known text opens the editor seeded with it.
    await row.locator('td[data-col-key="ticket"]').evaluate((cell) => {
      (cell as HTMLElement).focus();
      const data = new DataTransfer();
      data.setData('text/plain', 'pasted-text');
      cell.dispatchEvent(new ClipboardEvent('paste', { clipboardData: data, bubbles: true, cancelable: true }));
    });
    const ticketEditor = page.locator('td[data-col-key="ticket"][data-inline-editing] input.inline-editor');
    await expect(ticketEditor).toBeVisible();
    await expect(ticketEditor).toHaveValue('pasted-text');
  });
});
