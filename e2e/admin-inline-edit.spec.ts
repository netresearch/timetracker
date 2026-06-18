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
    // exact: the row's selection checkbox is labelled "Select <name>", so a
    // substring match would also hit the selection cell.
    await page.reload();
    await page.waitForSelector('table.admin-table [role="gridcell"]', { timeout: 15000 });
    await expect(page.getByRole('gridcell', { name: updated, exact: true })).toBeVisible();
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

  test('inline-edits a multiselect column as tag chips', async ({ page }) => {
    const teamsCell = page.locator('td[data-col-key="teams"]').first();
    await teamsCell.focus();
    await page.keyboard.press('Enter');

    // The teams column opens an inline tag editor (chips + an add button that
    // opens a listbox menu), not the modal. (Add/remove behaviour is unit-tested
    // in Admin.test.tsx; here we just confirm the inline editor mounts.)
    const addBtn = teamsCell.locator('button.tag-add');
    await expect(addBtn).toBeVisible();
    await expect(page.locator('[role="dialog"]')).toHaveCount(0);
  });

  test('the Status sub-page shows read-only diagnostics', async ({ page }) => {
    await page.goto('/ui/admin/status');
    await expect(page.getByRole('heading', { name: 'PHP' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Database' })).toBeVisible();
    // The live PHP and DB server versions render (a dotted version string).
    await expect(page.locator('.status-group').filter({ hasText: 'PHP' })).toContainText(/\d+\.\d+/);
  });

  test('the Edit button opens the modal seeded with the in-progress inline value', async ({ page }) => {
    const row = page.locator('table.admin-table tbody tr').first();
    await row.locator('td[data-col-key="name"]').focus();
    await page.keyboard.press('Enter');
    const editor = page.locator('td[data-inline-editing] input.inline-editor').first();
    await expect(editor).toBeVisible();
    await editor.fill('Inline-Draft-X');

    // Clicking Edit commits the in-progress value and opens the modal seeded from
    // it (the complete row also auto-saves in the background), so the modal shows
    // the edit, not stale list data. Target Edit by name — the cell also has the
    // Delete and the (reserved) disk force-save button, so .first() could be the
    // wrong control.
    await row.getByRole('button', { name: /^(Bearbeiten|Edit)$/i }).click();
    await expect(page.locator('.modal input[type="text"]').first()).toHaveValue('Inline-Draft-X');
  });

  test('opening and closing the editor neither resizes the cell nor moves its border', async ({ page }) => {
    const cell = page.locator('td[data-col-key="name"]').first();
    const before = await cell.boundingBox();

    await cell.focus();
    await page.keyboard.press('Enter');
    const editor = page.locator('td[data-inline-editing] input.inline-editor').first();
    await expect(editor).toBeVisible();
    const during = await cell.boundingBox();
    const inputBox = await editor.boundingBox();
    const editingTd = page.locator('td[data-inline-editing]').first();
    const tdOutline = await editingTd.evaluate((el) => parseFloat(getComputedStyle(el).outlineWidth));
    const ed = await editor.evaluate((el) => {
      const s = getComputedStyle(el);
      return { outline: s.outlineStyle, border: parseFloat(s.borderTopWidth), radius: parseFloat(s.borderTopLeftRadius) };
    });

    await page.keyboard.press('Escape');
    await expect(page.locator('td[data-inline-editing]')).toHaveCount(0);
    const after = await cell.boundingBox();

    // No resize — width AND height — when the editor opens or closes. (Width
    // catches an editor that overflows and widens the column.)
    expect(Math.round(during!.width)).toBe(Math.round(before!.width));
    expect(Math.round(during!.height)).toBe(Math.round(before!.height));
    expect(Math.round(after!.width)).toBe(Math.round(before!.width));
    expect(Math.round(after!.height)).toBe(Math.round(before!.height));
    // The current-cell border is the cell's OWN 2px outline, kept while editing,
    // so it can't jump or round — and the editor draws no border/radius/outline.
    expect(tdOutline).toBe(2);
    expect(ed.outline).toBe('none');
    expect(ed.border).toBe(0);
    expect(ed.radius).toBe(0);
    // The editor stays within the cell (never overflows it).
    expect(inputBox!.x).toBeGreaterThanOrEqual(before!.x - 1);
    expect(inputBox!.x + inputBox!.width).toBeLessThanOrEqual(before!.x + before!.width + 1);
  });

  test('revealing the disk (force-save) icon shifts neither the Edit icon nor the cell width', async ({ page }) => {
    const actionsCell = page.locator('td.admin-row-actions').first();
    const editBtn = actionsCell.getByRole('button', { name: /^(Bearbeiten|Edit)$/i });
    const editX = (await editBtn.boundingBox())!.x;
    const cellWidth = (await actionsCell.boundingBox())!.width;

    // The disk button always occupies a reserved slot; revealing it (as a dirty
    // row would) must move neither the leading icons nor the cell's width.
    await actionsCell.locator('.is-unsaved').evaluate((el) => el.classList.remove('action-slot-hidden'));

    expect(Math.round((await editBtn.boundingBox())!.x)).toBe(Math.round(editX));
    expect(Math.round((await actionsCell.boundingBox())!.width)).toBe(Math.round(cellWidth));
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

  test('selecting a row shows the bulk bar and exports the selection', async ({ page }) => {
    // Non-destructive: select one row, confirm the bulk bar, export-selected.
    await page.locator('table.admin-table tbody tr td.admin-select-col input[type="checkbox"]').first().check();
    const bar = page.locator('.admin-bulk-bar');
    await expect(bar).toBeVisible();
    await expect(page.locator('.admin-bulk-count')).toHaveText(/1/);

    const [download] = await Promise.all([
      page.waitForEvent('download', { timeout: 8000 }),
      page.getByRole('button', { name: /selected|auswahl/i }).click(),
    ]);
    expect(download.suggestedFilename()).toBe('customers.csv');

    await page.getByRole('button', { name: /^(clear|aufheben)$/i }).click();
    await expect(bar).toBeHidden();
  });
});

test.describe('Admin URL-addressable sub-nav', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, 'i.myself', 'myself123');
    await page.goto('/ui/admin');
    await page.waitForSelector('table.admin-table [role="gridcell"]', { timeout: 15000 });
  });

  test('selecting an entity updates the URL and is deep-linkable', async ({ page }) => {
    await page.locator('.admin-subnav-link', { hasText: /Projekte|Projects/ }).click();
    await expect(page).toHaveURL(/\/ui\/admin\/projects/);

    // The selection survives a full reload (URL-driven, not client state).
    await page.reload();
    await page.waitForSelector('table.admin-table [role="gridcell"]', { timeout: 15000 });
    await expect(page).toHaveURL(/\/ui\/admin\/projects/);
    await expect(page.locator('.admin-subnav-link[aria-current="page"]')).toHaveText(/Projekte|Projects/);

    // Direct deep-link to another entity.
    await page.goto('/ui/admin/users');
    await page.waitForSelector('table.admin-table [role="gridcell"]', { timeout: 15000 });
    await expect(page.locator('.admin-subnav-link[aria-current="page"]')).toHaveText(/Nutzer|Users/);
  });

  test('a modal opened over Admin keeps the background on its entity', async ({ page }) => {
    await page.setViewportSize({ width: 1920, height: 1080 });
    await page.goto('/ui/admin/projects');
    await page.waitForSelector('table.admin-table [role="gridcell"]', { timeout: 15000 });

    // Client-side nav to a modal route (preserves the page underneath).
    await page.locator('a.header-icon-link[data-nav="settings"]').click();
    await expect(page).toHaveURL(/\/ui\/settings/);
    await expect(page.locator('.modal-page')).toBeVisible();

    // The dimmed background Admin stays on Projects, not reset to the first tab.
    await expect(page.locator('.admin-subnav-link[aria-current="page"]')).toHaveText(/Projekte|Projects/);
  });
});
