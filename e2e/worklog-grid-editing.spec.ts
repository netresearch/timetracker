import { test, expect } from '@playwright/test';

import { loginIsolated } from './helpers/auth';
import { goToWorklogPage } from './helpers/navigation';
import { cleanupWorklogEntries, createWorklogEntry, rowByStamp } from './helpers/worklog';

/**
 * Spreadsheet-style keyboard + clipboard editing on the SolidJS worklog grid:
 * Tab walks to the next editable cell staying in edit mode, and Ctrl+C / Ctrl+V
 * copy the focused (non-edit) cell / paste into another via the async clipboard API.
 */
test.describe('Worklog grid — keyboard & clipboard editing', () => {
  test.beforeEach(async ({ page, context }) => {
    await context.grantPermissions(['clipboard-read', 'clipboard-write']);
    await loginIsolated(page);
    await goToWorklogPage(page);
  });

  test.afterEach(async ({ page }) => {
    await cleanupWorklogEntries(page);
  });

  test('Tab walks to the next editable cell, staying in edit mode', async ({ page }) => {
    const stamp = await createWorklogEntry(page);
    const row = rowByStamp(page, stamp);

    // Start typing on the start cell → it enters inline edit mode (seeded). Wait for
    // the cell to actually hold focus before the keystroke, or under load the '9' can
    // land before focus settles and never opens the editor.
    const startCell = row.locator('td[data-col-key="start"]');
    await startCell.focus();
    await expect(startCell).toBeFocused();
    await page.keyboard.press('9');
    const startEditor = page.locator('td[data-col-key="start"][data-inline-editing] input.inline-editor');
    await expect(startEditor).toBeVisible();
    // The editor focuses itself on mount; wait for that before Tab, or the keystroke
    // can land on the still-focused cell (which yields Tab to the browser) instead of
    // the editor's own commit-and-walk handler.
    await expect(startEditor).toBeFocused();

    // Tab commits and moves to the next editable cell (end), still in edit mode.
    await page.keyboard.press('Tab');
    await expect(page.locator('td[data-col-key="end"][data-inline-editing] input.inline-editor')).toBeVisible();
  });

  // Regression for #481: editing an EXISTING, already-complete entry and pressing
  // Tab used to drop inline-edit mode — the next cell showed briefly but was not
  // typeable until re-opened. Cause: a valid change kept the row complete, so the
  // commit auto-saved, and saveRow's refetch remounted the rows and unmounted the
  // just-opened editor. (The Tab test above types an incomplete '9', so its row
  // never auto-saved and it never hit this path.) Auto-save is now deferred for Tab.
  test('Tab keeps inline-edit mode after a valid change to an existing entry (#481)', async ({ page }) => {
    const stamp = await createWorklogEntry(page);
    const row = rowByStamp(page, stamp);

    // The reporter's flow: double-click a cell of a complete row and change it.
    await row.locator('td[data-col-key="start"]').dblclick();
    const startEditor = page.locator('td[data-col-key="start"][data-inline-editing] input.inline-editor');
    await expect(startEditor).toBeVisible();
    await expect(startEditor).toBeFocused();

    // A real, VALID edit (00:05 is still before the 00:15 end) keeps the row
    // complete — the exact condition that used to trigger the disruptive auto-save.
    await startEditor.fill('00:05');
    await page.keyboard.press('Tab');

    // Edit mode must survive: the next editor is open, focused AND typeable (the
    // bug left a dead cell that needed a second double-click).
    const endEditor = page.locator('td[data-col-key="end"][data-inline-editing] input.inline-editor');
    await expect(endEditor).toBeVisible();
    await expect(endEditor).toBeFocused();
    await endEditor.fill('00:20');
    await expect(endEditor).toHaveValue('00:20');
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

    // Close the auto-opened ticket editor so the reset click isn't racing it.
    await page.keyboard.press('Escape');
    // Reset discards the unsaved new row (client-side; no /tracking/delete for a temp id).
    await row.locator('.is-reset').click();
    await expect(page.locator('tr.tracking-row')).toHaveCount(before);
  });

  test('Enter guides a new entry to the next required field (customer → project → activity)', async ({ page }) => {
    await page.getByRole('button', { name: /Add entry|Eintrag hinzufügen/i }).click();
    const row = page.locator('tr.tracking-row.is-new').first();
    await expect(row).toBeVisible();

    // Add starts in the ticket editor (#588); Tab walks on into the first
    // required relation (customer), staying in edit mode.
    const ticketEditor = page.locator('td[data-col-key="ticket"][data-inline-editing] input.inline-editor');
    await expect(ticketEditor).toBeVisible();
    await expect(ticketEditor).toBeFocused();
    await page.keyboard.press('Tab');
    await expect(row.locator('td[data-col-key="customer"][data-inline-editing]')).toBeVisible();

    const arrowEnter = async (): Promise<void> => {
      await expect(page.locator('.combobox-input').first()).toBeVisible();
      // Wait for the option list to populate before navigating it — under shard load
      // ArrowDown can fire before the (async) options arrive, highlighting nothing, so
      // Enter then picks nothing and the guide never advances to the next field.
      await expect(page.locator('.combobox-content .combobox-item').first()).toBeVisible({ timeout: 8000 });
      await page.keyboard.press('ArrowDown'); // highlight an option
      await page.keyboard.press('Enter'); // Enter-driven pick guides to the next required field
    };
    // customer/project/activity are always required-and-empty for a new row, so the
    // guide jumps through them. (date/start/end are pre-filled by suggest-time +
    // the end-prefill minimum, so they're skipped — the guide only targets empties.)
    await arrowEnter();
    await expect(row.locator('td[data-col-key="project"][data-inline-editing]')).toBeVisible();
    await arrowEnter();
    await expect(row.locator('td[data-col-key="activity"][data-inline-editing]')).toBeVisible();
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
