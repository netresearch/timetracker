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

    // Enter commits and moves down a row → leaving the row saves the whole entity.
    const saved = page.waitForResponse((r) => /\/customer\/save$/.test(r.url()) && r.request().method() === 'POST');
    await page.keyboard.press('Enter');
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
});
