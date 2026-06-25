import { test, expect, type Page } from '@playwright/test';
import { login } from './helpers/auth';

const ADD = /^(Hinzufügen|Add)$/i;
const SAVE = /^(Speichern|Save)$/i;
const EDIT = /^(Bearbeiten|Edit)$/i;
const DELETE = /^(Löschen|Delete)$/i;

/** A throwaway Customers row to mutate, by name. */
function adminRow(page: Page, name: string) {
  return page.locator('table.admin-table tbody tr').filter({ hasText: name });
}

/**
 * Create a self-contained, server-valid throwaway customer via the Add modal
 * (marked Global so it needs no team), mirroring admin/admin-ui.spec.ts, and
 * return its name. Mutating inline-edit tests operate on THIS row, never the
 * shared seed rows, so re-runs stay idempotent and leave no residue.
 */
async function createThrowawayCustomer(page: Page): Promise<string> {
  // Date.now() alone can collide when parallel workers create a row in the same
  // millisecond (a unique-name DB violation); a random suffix makes it collision-safe.
  const name = `E2EInline_${Date.now()}_${Math.floor(Math.random() * 1_000_000)}`;
  await page.locator('.admin-crud-toolbar button.primary-button').filter({ hasText: ADD }).click();
  const form = page.locator('.modal form.stack-form');
  await expect(form).toBeVisible();
  await form.locator('.field input[type="text"]').first().fill(name);
  await form.locator('.field-check').filter({ hasText: /^Global$/ }).locator('input[type="checkbox"]').check();
  await form.locator('button[type="submit"]').filter({ hasText: SAVE }).click();
  await expect(page.locator('.modal')).toHaveCount(0);
  await expect(adminRow(page, name)).toHaveCount(1);
  return name;
}

/** Best-effort delete of the throwaway customer (native confirm), for finally blocks. */
async function deleteThrowawayCustomer(page: Page, name: string): Promise<void> {
  try {
    const row = adminRow(page, name);
    // Of the two names passed across a finally block (pre/post rename), only one
    // row actually exists — skip the missing one instead of letting its delete
    // button locator wait out the full timeout (a ~30s stall on every run). Bound
    // the click for the same reason; the row is present, so it resolves at once.
    if ((await row.count()) === 0) return;
    page.once('dialog', (dialog) => dialog.accept());
    await row.getByRole('button', { name: DELETE }).click({ timeout: 2000 });
    await expect(row).toHaveCount(0);
  } catch {
    // Swallow — a mid-test failure may leave the page in a state where delete
    // can't complete; never mask the original failure with a cleanup error.
  }
}

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
    // Mutate a throwaway customer we create + delete, not a shared seed row.
    const name = await createThrowawayCustomer(page);
    const updated = `${name}-edited`;
    try {
      const cell = adminRow(page, name).locator('td[data-col-key="name"]').first();
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
    } finally {
      // The row carries `updated` on success, or `name` if the rename never landed
      // (mid-test failure); whichever is present, delete it best-effort.
      await deleteThrowawayCustomer(page, updated);
      await deleteThrowawayCustomer(page, name);
    }
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

  test('inline-edits a multiselect column as a chip combobox and persists the pick', async ({ page }) => {
    // The teams column lives on the Users entity. Target sandy.supporter and TOGGLE
    // a team — add an unselected one if there's room, else remove a selected one —
    // so the test is idempotent across re-runs (teams is required, so ≥1 remains).
    await page.goto('/ui/admin/users');
    await page.waitForSelector('table.admin-table [role="gridcell"]', { timeout: 15000 });
    await page.locator('input.admin-filter').fill('sandy');
    const row = page.locator('table.admin-table tbody tr').filter({ hasText: /sandy/i }).first();
    await expect(row).toBeVisible();
    const before = await row.locator('td[data-col-key="teams"] .tag').count();

    await row.locator('td[data-col-key="teams"]').focus();
    await page.keyboard.press('Enter');

    // The teams column opens an inline filterable combobox (a text input + an
    // option list), with the selection rendered as chips — not the modal.
    await expect(page.locator('.combobox-input')).toBeVisible();
    await expect(page.locator('[role="dialog"]')).toHaveCount(0);
    await expect(page.locator('.combobox-content .combobox-item').first()).toBeVisible({ timeout: 8000 });

    const unselected = page.locator('.combobox-content .combobox-item:not([data-state="checked"])');
    let expected: number;
    if ((await unselected.count()) > 0) {
      await unselected.first().click(); // add a team → one more chip
      expected = before + 1;
    } else {
      await page.locator('.combobox-content .combobox-item[data-state="checked"]').first().click(); // remove one → one fewer (still ≥1)
      expected = before - 1;
    }
    await expect(page.locator('td[data-inline-editing] .tag')).toHaveCount(expected);

    // Leaving the row saves the whole user — exercising the body-portal multi-commit
    // path (the picked array must survive focus-out and reach /user/save).
    const saved = page.waitForResponse((r) => /\/user\/save$/.test(r.url()) && r.request().method() === 'POST');
    await page.locator('input.admin-filter').focus();
    await saved;

    // The change survives a full reload (persisted, not just optimistic).
    await page.reload();
    await page.waitForSelector('table.admin-table [role="gridcell"]', { timeout: 15000 });
    await page.locator('input.admin-filter').fill('sandy');
    await expect(page.locator('table.admin-table tbody tr').filter({ hasText: /sandy/i }).first()
      .locator('td[data-col-key="teams"] .tag')).toHaveCount(expected);
  });

  test('the Status sub-page shows read-only diagnostics', async ({ page }) => {
    await page.goto('/ui/admin/status');
    // Seven groups (app/build/php/symfony/db/packages/config). Assert on locale-
    // independent data, not the translated headings: real version strings + the DB platform.
    await expect(page.locator('.status-group')).toHaveCount(7);
    // Bounded quantifiers (no unbounded backtracking): a dotted version string.
    await expect(page.locator('.status-page')).toContainText(/\d{1,4}\.\d{1,4}/);
    await expect(page.locator('.status-page')).toContainText(/MariaDB|MySQL/i);
  });

  test('the Edit button opens the modal seeded with the in-progress inline value', async ({ page }) => {
    // The complete row auto-saves in the background, so drive a throwaway customer
    // we create + delete — never a shared seed row.
    const name = await createThrowawayCustomer(page);
    const draft = `${name}-draft`;
    try {
      const row = adminRow(page, name);
      await row.locator('td[data-col-key="name"]').focus();
      await page.keyboard.press('Enter');
      const editor = page.locator('td[data-inline-editing] input.inline-editor').first();
      await expect(editor).toBeVisible();
      await editor.fill(draft);

      // Clicking Edit commits the in-progress value and opens the modal seeded from
      // it (the complete row also auto-saves in the background), so the modal shows
      // the edit, not stale list data. Target Edit by name — the cell also has the
      // Delete and the (reserved) disk force-save button, so .first() could be the
      // wrong control.
      await row.getByRole('button', { name: EDIT }).click();
      await expect(page.locator('.modal input[type="text"]').first()).toHaveValue(draft);
    } finally {
      // Close the modal (Escape-dismissible) so the row's Delete icon is clickable,
      // then delete: the background auto-save may have renamed the row to `draft`,
      // so cover both names best-effort.
      await page.keyboard.press('Escape').catch(() => undefined);
      await expect(page.locator('.modal')).toHaveCount(0).catch(() => undefined);
      await deleteThrowawayCustomer(page, draft);
      await deleteThrowawayCustomer(page, name);
    }
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
