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

  test('the toolbar refresh button refetches the entries', async ({ page }) => {
    const refetch = page.waitForResponse((r) => /\/getData\/days\/\d+/.test(r.url()) && r.request().method() === 'GET');
    await page.getByRole('button', { name: /^(Refresh|Aktualisieren)$/ }).click();
    await refetch;
  });

  test('a new row shows the unsaved save + reset actions, and reset discards it', async ({ page }) => {
    const before = await page.locator('tr.tracking-row').count();
    await page.getByRole('button', { name: /Add entry|Eintrag hinzufügen/i }).click();
    const row = page.locator('tr.tracking-row.is-new').first();
    await expect(row).toBeVisible();

    // A brand-new row is unsaved by definition: both the force-save and reset
    // actions show immediately, before any edit.
    await expect(row.locator('.is-unsaved')).toBeVisible();
    await expect(row.locator('.is-reset')).toBeVisible();

    // Close the auto-opened customer editor so the reset click isn't racing the combobox.
    await page.keyboard.press('Escape');
    // Reset discards the unsaved new row (client-side; no /tracking/delete for a temp id).
    await row.locator('.is-reset').click();
    await expect(page.locator('tr.tracking-row')).toHaveCount(before);
  });

  test('Enter guides a new entry to the next required field (customer → project → activity → end)', async ({ page }) => {
    await page.getByRole('button', { name: /Add entry|Eintrag hinzufügen/i }).click();
    const row = page.locator('tr.tracking-row.is-new').first();
    await expect(row).toBeVisible();

    const arrowEnter = async (): Promise<void> => {
      await expect(page.locator('.combobox-input').first()).toBeVisible();
      await page.keyboard.press('ArrowDown'); // highlight an option
      await page.keyboard.press('Enter'); // Enter-driven pick guides to the next required field
    };
    await arrowEnter();
    await expect(row.locator('td[data-col-key="project"][data-inline-editing]')).toBeVisible();
    await arrowEnter();
    await expect(row.locator('td[data-col-key="activity"][data-inline-editing]')).toBeVisible();
    await arrowEnter();
    // start is pre-filled by suggest-time, so the guide skips it straight to end.
    await expect(row.locator('td[data-col-key="end"][data-inline-editing]')).toBeVisible();
  });

  test('Enter committing a select editor keeps focus on a grid cell (no focus loss)', async ({ page }) => {
    const stamp = await createWorklogEntry(page);
    const row = rowByStamp(page, stamp);

    await row.locator('td[data-col-key="customer"]').focus();
    await page.keyboard.press('Enter'); // open the (portalled) select editor
    await page.keyboard.press('ArrowDown');
    await page.keyboard.press('Enter'); // commit — focus must return to a grid cell, not <body>
    await expect(page.locator('.tracking-table td:focus')).toHaveCount(1);
  });
});
