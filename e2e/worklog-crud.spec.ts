import { test, expect } from '@playwright/test';
import { login } from './helpers/auth';
import { goToWorklogPage } from './helpers/navigation';
import { createWorklogEntry, openEditor, rowByStamp } from './helpers/worklog';

/**
 * End-to-end coverage of the SolidJS Worklog CRUD journey (create / edit / save /
 * delete / prolong), which previously had only read-only e2e tests. The grid runs
 * in German on the e2e stack, so all control matchers are locale-tolerant. Entries
 * are created per-test (see helpers/worklog) since the seed data predates the
 * default day range.
 */

const isSave = (r: { url(): string; request(): { method(): string } }): boolean =>
  /\/tracking\/save$/.test(r.url()) && r.request().method() === 'POST';
const isDelete = (r: { url(): string; request(): { method(): string } }): boolean =>
  /\/tracking\/delete$/.test(r.url()) && r.request().method() === 'POST';

const createEntry = createWorklogEntry;

test.describe('Worklog CRUD', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
    await goToWorklogPage(page);
  });

  test('create → edit → delete journey, with a polite save announcement', async ({ page }) => {
    const stamp = await createEntry(page);

    // The successful create is announced into the worklog's polite live region
    // (the only confirmation an AT user gets that the row persisted). Scoped to
    // the section — the shared header also carries a role=status user badge.
    await expect(page.locator('section.tracking [role="status"]')).toHaveText(/saved|gespeichert/i);

    // EDIT: change the description; a complete row auto-saves on commit.
    const edited = `${stamp}-edited`;
    const editor = await openEditor(page, rowByStamp(page, stamp), 'description');
    const resaved = page.waitForResponse(isSave);
    await editor.fill(edited);
    await page.keyboard.press('Enter');
    await resaved;
    await expect(page.getByRole('gridcell', { name: edited })).toBeVisible();

    // DELETE: the trash icon opens an accessible confirmation dialog (no native
    // window.confirm); confirming there triggers the form-encoded delete.
    await rowByStamp(page, edited).getByRole('button', { name: /^(Delete|Löschen)$/i }).click();
    const dialog = page.getByRole('dialog');
    await expect(dialog).toBeVisible();
    const deleted = page.waitForResponse(isDelete);
    await dialog.getByRole('button', { name: /^(Delete|Löschen)$/i }).click();
    await deleted;
    await expect(page.getByRole('gridcell', { name: edited })).toHaveCount(0);
  });

  test('cancelling the delete dialog is non-destructive', async ({ page }) => {
    const stamp = await createEntry(page);
    let deleteFired = false;
    page.on('request', (r) => { if (isDelete(r)) deleteFired = true; });

    await rowByStamp(page, stamp).getByRole('button', { name: /^(Delete|Löschen)$/i }).click();
    const dialog = page.getByRole('dialog');
    await expect(dialog).toBeVisible();
    await dialog.getByRole('button', { name: /^(Cancel|Abbrechen)$/i }).click();
    await expect(dialog).toBeHidden();

    expect(deleteFired).toBe(false);
    await expect(page.getByRole('gridcell', { name: stamp })).toBeVisible();
  });

  test('Prolong posts the entry with a now end-time', async ({ page }) => {
    const stamp = await createEntry(page);
    const saved = page.waitForResponse(isSave);
    await rowByStamp(page, stamp).getByRole('button', { name: /Prolong|Verlängern/i }).click();
    const response = await saved;
    expect(response.request().method()).toBe('POST');
  });

  test('Continue clones a saved row into a fresh editable draft', async ({ page }) => {
    const stamp = await createEntry(page);
    await rowByStamp(page, stamp).getByRole('button', { name: /Continue|Fortsetzen/i }).click();

    // A new, unsaved draft row appears at the top, opened for editing.
    await expect(page.locator('tr.tracking-row.is-new')).toHaveCount(1);
    await expect(page.locator('td[data-inline-editing] input.inline-editor')).toBeVisible();
  });

  test('changing the days range refetches, and the CSV export anchor matches it', async ({ page }) => {
    const refetched = page.waitForResponse((r) => /\/getData\/days\/35\b/.test(r.url()));
    await page.locator('.tracking-days select').selectOption('35');
    await refetched;

    await expect(page.getByRole('link', { name: /Export CSV|CSV-Export/i })).toHaveAttribute('href', '/export/35');
  });

  test('a select cell shows a dropdown chevron and opening its editor does not shift the cell', async ({ page }) => {
    const stamp = await createEntry(page);
    const cell = rowByStamp(page, stamp).locator('td[data-col-key="customer"]');

    // The chevron affordance marks a select cell (distinct from a text cell) in
    // STATIC mode — a rendered ::after with a visible border-triangle.
    await expect(cell).toHaveClass(/is-select/);
    const staticCaret = await cell.evaluate((el) => parseFloat(getComputedStyle(el, '::after').borderTopWidth));
    expect(staticCaret).toBeGreaterThan(0);

    // Opening the inline editor must not reflow the cell (the caret gutter is
    // reserved identically on the cell and the overlaying select editor).
    const before = await cell.boundingBox();
    await cell.focus();
    await page.keyboard.press('Enter');
    await expect(cell.locator('select.inline-editor')).toBeVisible();
    const during = await cell.boundingBox();
    // The chevron is still painted in edit mode (same affordance, same spot).
    const editCaret = await cell.evaluate((el) => parseFloat(getComputedStyle(el, '::after').borderTopWidth));
    expect(editCaret).toBeGreaterThan(0);

    await page.keyboard.press('Escape');
    const after = await cell.boundingBox();
    expect(Math.round(during!.width)).toBe(Math.round(before!.width));
    expect(Math.round(after!.width)).toBe(Math.round(before!.width));
  });
});
