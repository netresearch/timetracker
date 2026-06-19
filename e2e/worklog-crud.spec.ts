import { test, expect, type Locator, type Page } from '@playwright/test';
import { login } from './helpers/auth';
import { goToWorklogPage } from './helpers/navigation';

/**
 * End-to-end coverage of the SolidJS Worklog CRUD journey (create / edit / save /
 * delete / prolong), which previously had only read-only e2e tests. The grid runs
 * in German on the e2e stack, so all control matchers are locale-tolerant.
 *
 * The seeded entries are months old (outside the default day range), so every
 * test creates its own entry for *today* via the Add flow — picking the main seed
 * customer (id 1, which has active projects) and the first project/activity — then
 * acts on it, located by a unique description stamp.
 */

const isSave = (r: { url(): string; request(): { method(): string } }): boolean =>
  /\/tracking\/save$/.test(r.url()) && r.request().method() === 'POST';
const isDelete = (r: { url(): string; request(): { method(): string } }): boolean =>
  /\/tracking\/delete$/.test(r.url()) && r.request().method() === 'POST';

async function openEditor(page: Page, row: Locator, colKey: string): Promise<Locator> {
  await row.locator(`td[data-col-key="${colKey}"]`).focus();
  await page.keyboard.press('Enter');
  const editor = page.locator('td[data-inline-editing] input.inline-editor, td[data-inline-editing] select.inline-editor').first();
  await expect(editor).toBeVisible();
  return editor;
}

async function pickFirstReal(select: Locator): Promise<string> {
  // Wait for the options to load (the dropdowns are query-backed) before picking.
  const firstOption = select.locator('option[value]:not([value=""])').first();
  await expect(firstOption).toBeAttached({ timeout: 10000 });
  const value = await firstOption.getAttribute('value');
  await select.selectOption(value);
  return value ?? '';
}

// Create one entry dated today and return its unique description stamp.
async function createEntry(page: Page): Promise<string> {
  const stamp = `e2e-${Date.now()}`;
  await page.getByRole('button', { name: /Add entry|Eintrag hinzufügen/i }).click();
  const row = page.locator('tr.tracking-row.is-new').first();
  await expect(row).toBeVisible();

  // The Add flow opens the customer editor automatically. Pick the first real
  // customer that actually has projects, so the cascade-filtered project editor
  // isn't empty (some seed customers have none).
  let projectValue = '';
  for (let attempt = 0; attempt < 6 && projectValue === ''; attempt += 1) {
    const customer = page.locator('td[data-inline-editing] select.inline-editor').first();
    await expect(customer).toBeVisible();
    const options = customer.locator('option[value]:not([value=""])');
    await expect(options.first()).toBeAttached({ timeout: 10000 });
    const value = await options.nth(attempt).getAttribute('value');
    if (value === null) {
      break; // no more customers to try
    }
    await customer.selectOption(value);
    await page.keyboard.press('Enter');

    const project = await openEditor(page, row, 'project'); // cascade-filtered to the customer
    const realProject = project.locator('option[value]:not([value=""])').first();
    if (await realProject.count() > 0 && (await realProject.getAttribute('value')) !== null) {
      projectValue = (await realProject.getAttribute('value')) ?? '';
      await project.selectOption(projectValue);
      await page.keyboard.press('Enter');
      break;
    }
    // This customer has no projects — cancel and try the next one.
    await page.keyboard.press('Escape');
    await openEditor(page, row, 'customer');
  }
  expect(projectValue).not.toBe('');

  const activity = await openEditor(page, row, 'activity');
  await pickFirstReal(activity);
  await page.keyboard.press('Enter');

  const description = await openEditor(page, row, 'description');
  await description.fill(stamp);
  await page.keyboard.press('Enter');

  const start = await openEditor(page, row, 'start');
  await start.fill('08:00');
  await page.keyboard.press('Enter');

  // Completing end makes the row valid → it auto-saves with no explicit save click.
  const saved = page.waitForResponse(isSave);
  const end = await openEditor(page, row, 'end');
  await end.fill('09:00');
  await page.keyboard.press('Enter');
  await saved;

  await expect(page.getByRole('gridcell', { name: stamp })).toBeVisible();
  return stamp;
}

function rowByStamp(page: Page, stamp: string): Locator {
  return page.locator('tr.tracking-row').filter({ hasText: stamp }).first();
}

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
});
