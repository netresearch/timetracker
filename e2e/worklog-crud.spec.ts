import { test, expect } from '@playwright/test';
import { loginIsolated } from './helpers/auth';
import { goToWorklogPage } from './helpers/navigation';
import { cleanupWorklogEntries, createWorklogEntry, openTextEditor, rowByStamp } from './helpers/worklog';

/**
 * End-to-end coverage of the SolidJS Worklog CRUD journey (create / edit / save /
 * delete / prolong), which previously had only read-only e2e tests. The grid runs
 * in German on the e2e stack, so all control matchers are locale-tolerant. Entries
 * are created per-test (see helpers/worklog) since the seed data predates the
 * default day range.
 */

// Accept either a Playwright Request (from page.on('request')) or a Response (from
// waitForResponse): a Request exposes method() directly; a Response only via request().
type HttpLike = { url(): string; method(): string } | { url(): string; request(): { method(): string } };
const reqMethod = (r: HttpLike): string => ('method' in r ? r.method() : r.request().method());
const isSave = (r: HttpLike): boolean =>
  /\/tracking\/save$/.test(r.url()) && reqMethod(r) === 'POST';
const isDelete = (r: HttpLike): boolean =>
  /\/tracking\/delete$/.test(r.url()) && reqMethod(r) === 'POST';

const createEntry = createWorklogEntry;

test.describe('Worklog CRUD', () => {
  test.beforeEach(async ({ page }) => {
    await loginIsolated(page);
    await goToWorklogPage(page);
  });

  test.afterEach(async ({ page }) => {
    await cleanupWorklogEntries(page);
  });

  test('create → edit → delete journey, with a polite save announcement', async ({ page }) => {
    const stamp = await createEntry(page);

    // The successful create is announced into the worklog's polite live region
    // (the only confirmation an AT user gets that the row persisted). Scoped to
    // the section — the shared header also carries a role=status user badge.
    await expect(page.locator('section.tracking [role="status"]')).toHaveText(/saved|gespeichert/i);

    // EDIT: change the description; a complete row auto-saves on commit.
    const edited = `${stamp}-edited`;
    const editor = await openTextEditor(page, rowByStamp(page, stamp), 'description');
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
    // The day range is a freetext combobox now: type a value and commit on blur,
    // which fires the native change handler (applyDays).
    const days = page.locator('.tracking-days-input');
    await days.fill('35');
    await days.blur();
    await refetched;

    await expect(page.getByRole('link', { name: /Export CSV|CSV-Export/i })).toHaveAttribute('href', '/export/35');
  });

  test('a relation cell edits via a filterable combobox, without reflow, and Escape cancels', async ({ page }) => {
    const stamp = await createEntry(page);
    // Read mode: the customer cell renders its value as a single chip (not free text).
    const cell = rowByStamp(page, stamp).locator('td[data-col-key="customer"]');
    await expect(cell.locator('.inline-tags .tag')).toHaveCount(1);
    const original = ((await cell.locator('.inline-tags .tag').textContent()) ?? '').trim();
    const before = await cell.boundingBox();

    // Edit mode: a combobox opens with a filter input and an option list.
    await cell.focus();
    await page.keyboard.press('Enter');
    await expect(page.locator('.combobox-input')).toBeVisible();
    await expect(page.locator('.combobox-content .combobox-item').first()).toBeVisible({ timeout: 8000 });

    // The single-select editor overlays the cell — opening it must not widen the
    // column (the no-reflow contract the native-select editor used to uphold). Wait
    // for the cell to settle into edit mode before measuring, so a transient re-render
    // during the combobox open doesn't yield a null box.
    await expect(cell).toHaveAttribute('data-inline-editing', '');
    const during = await cell.boundingBox();
    expect(Math.abs(during!.width - before!.width)).toBeLessThanOrEqual(1);

    // Escape cancels: the editor closes, the chip is unchanged, and nothing is saved.
    let saveFired = false;
    page.on('request', (r) => { if (isSave(r)) saveFired = true; });
    await page.keyboard.press('Escape');
    await expect(page.locator('td[data-inline-editing]')).toHaveCount(0);
    await expect(cell.locator('.inline-tags .tag')).toHaveCount(1);
    await expect(cell.locator('.inline-tags .tag')).toHaveText(original);
    expect(saveFired).toBe(false);
  });
});
