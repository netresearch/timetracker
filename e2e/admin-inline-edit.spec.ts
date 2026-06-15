import { test, expect } from '@playwright/test';
import { login } from './helpers/auth';

/**
 * Inline (spreadsheet-style) cell editing on the SolidJS Administration tables.
 * The classic ExtJS tracking-grid editing lives in keyboard.spec.ts; this covers
 * the new in-cell editing built on the use:gridNav directive.
 */
test.describe('Admin inline cell editing', () => {
  test.beforeEach(async ({ page }) => {
    // i.myself is a PL (ROLE_ADMIN), so the Administration page is reachable.
    await login(page, 'i.myself', 'myself123');
    await page.goto('/ui/admin');
    await page.waitForSelector('table.admin-table [role="gridcell"]', { timeout: 15000 });
  });

  test('edits a cell in place and persists it on row-leave', async ({ page }) => {
    const cell = page.locator('td[data-col-key="name"]').first();
    const original = ((await cell.textContent()) ?? '').trim();
    const updated = `${original}-E2E`;

    await cell.focus();
    await page.keyboard.press('Enter');
    const editor = page.locator('td[data-inline-editing] input.inline-editor').first();
    await expect(editor).toBeVisible();
    await editor.fill(updated);

    // Enter commits and (by default) stays in the cell; leaving the row — here by
    // focusing the filter box — saves the whole entity.
    await page.keyboard.press('Enter');
    await expect(page.locator('td[data-inline-editing]')).toHaveCount(0); // editor closed, still on the cell
    const saved = page.waitForResponse((r) => /\/customer\/save$/.test(r.url()) && r.request().method() === 'POST');
    await page.locator('input.admin-filter').focus();
    await saved;

    // The edit survives a full reload (it was persisted, not just optimistic).
    await page.reload();
    await page.waitForSelector('table.admin-table [role="gridcell"]', { timeout: 15000 });
    await expect(page.getByRole('gridcell', { name: updated })).toBeVisible();
  });

  test('opens an editor by typing and cancels with Escape', async ({ page }) => {
    const cell = page.locator('td[data-col-key="name"]').first();
    const original = ((await cell.textContent()) ?? '').trim();

    await cell.focus();
    await page.keyboard.press('q');
    const editor = page.locator('td[data-inline-editing] input.inline-editor').first();
    await expect(editor).toBeVisible();
    await expect(editor).toHaveValue('q'); // seeded with the typed character

    await page.keyboard.press('Escape');
    await expect(page.locator('td[data-inline-editing]')).toHaveCount(0);
    // Cancel discards the edit — the cell keeps its original value.
    await expect(page.locator('td[data-col-key="name"]').first()).toHaveText(original);
  });

  test('does not inline-edit a modal-only (multiselect) column', async ({ page }) => {
    const teamsCell = page.locator('td[data-col-key="teams"]').first();
    await teamsCell.focus();
    await page.keyboard.press('Enter');

    // The teams column maps to a multiselect field → no inline editor opens.
    await expect(page.locator('td[data-inline-editing]')).toHaveCount(0);
  });

  test('the Edit button hands a pending inline draft to the modal (no stale data, no double-save)', async ({ page }) => {
    let saved = false;
    page.on('request', (r) => {
      if (/\/customer\/save$/.test(r.url()) && r.request().method() === 'POST') saved = true;
    });

    const row = page.locator('table.admin-table tbody tr').first();
    await row.locator('td[data-col-key="name"]').focus();
    await page.keyboard.press('Enter');
    const editor = page.locator('td[data-inline-editing] input.inline-editor').first();
    await expect(editor).toBeVisible();
    await editor.fill('Inline-Draft-X');

    // Clicking Edit (same row → no premature flush) commits the draft on blur and
    // opens the modal seeded from it, so the modal shows the edit, not stale data.
    await row.locator('.admin-row-actions button.link-button').first().click();
    await expect(page.locator('.modal input[type="text"]').first()).toHaveValue('Inline-Draft-X');

    await page.waitForTimeout(600);
    expect(saved).toBe(false); // the modal took over; no inline save fired
  });
});

test.describe('Admin list — inactive filter & CSV export', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, 'i.myself', 'myself123');
    await page.goto('/ui/admin'); // first entity = Customers (has an `active` flag)
    await page.waitForSelector('table.admin-table [role="gridcell"]', { timeout: 15000 });
  });

  test('hides inactive records by default and the toggle reveals more', async ({ page }) => {
    const toggle = page.getByRole('checkbox', { name: /inactive|inaktive/i });
    await expect(toggle).toBeVisible();
    const hidden = await page.locator('table.admin-table tbody tr').count();
    await toggle.click();
    await expect.poll(async () => page.locator('table.admin-table tbody tr').count()).toBeGreaterThanOrEqual(hidden);
  });

  test('exports the table as a CSV download', async ({ page }) => {
    const [download] = await Promise.all([
      page.waitForEvent('download', { timeout: 8000 }),
      page.getByRole('button', { name: /CSV/i }).click(),
    ]);
    expect(download.suggestedFilename()).toBe('customers.csv');
  });
});
