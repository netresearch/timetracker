import { expect, type Locator, type Page } from '@playwright/test';

/**
 * Shared Worklog-grid helpers. The seeded entries predate the default day range,
 * so tests create their own entry for *today* via the Add flow — picking the
 * first seed customer that actually has projects, then the first project/activity
 * — and locate it by a unique description stamp.
 */

export async function openEditor(page: Page, row: Locator, colKey: string): Promise<Locator> {
  await row.locator(`td[data-col-key="${colKey}"]`).focus();
  await page.keyboard.press('Enter');
  const editor = page.locator('td[data-inline-editing] input.inline-editor, td[data-inline-editing] select.inline-editor').first();
  await expect(editor).toBeVisible();
  return editor;
}

export function rowByStamp(page: Page, stamp: string): Locator {
  return page.locator('tr.tracking-row').filter({ hasText: stamp }).first();
}

/** Create one entry dated today and return its unique description stamp. */
export async function createWorklogEntry(page: Page): Promise<string> {
  const stamp = `e2e-${Date.now()}`;
  await page.getByRole('button', { name: /Add entry|Eintrag hinzufügen/i }).click();
  const row = page.locator('tr.tracking-row.is-new').first();
  await expect(row).toBeVisible();

  // Add opens the customer editor automatically. Pick the first real customer
  // that actually has projects, so the cascade-filtered project editor isn't
  // empty (some seed customers have none).
  let projectValue = '';
  for (let attempt = 0; attempt < 6 && projectValue === ''; attempt += 1) {
    const customer = page.locator('td[data-inline-editing] select.inline-editor').first();
    await expect(customer).toBeVisible();
    const options = customer.locator('option[value]:not([value=""])');
    await expect(options.first()).toBeAttached({ timeout: 10000 });
    const value = await options.nth(attempt).getAttribute('value');
    if (value === null) {
      break;
    }
    await customer.selectOption(value);
    await page.keyboard.press('Enter');

    const project = await openEditor(page, row, 'project');
    const realProject = project.locator('option[value]:not([value=""])').first();
    if (await realProject.count() > 0 && (await realProject.getAttribute('value')) !== null) {
      projectValue = (await realProject.getAttribute('value')) ?? '';
      await project.selectOption(projectValue);
      await page.keyboard.press('Enter');
      break;
    }
    await page.keyboard.press('Escape');
    await openEditor(page, row, 'customer');
  }
  expect(projectValue).not.toBe('');

  const activity = await openEditor(page, row, 'activity');
  await activity.selectOption((await activity.locator('option[value]:not([value=""])').first().getAttribute('value')));
  await page.keyboard.press('Enter');

  const description = await openEditor(page, row, 'description');
  await description.fill(stamp);
  await page.keyboard.press('Enter');

  const start = await openEditor(page, row, 'start');
  await start.fill('08:00');
  await page.keyboard.press('Enter');

  const saved = page.waitForResponse((r) => /\/tracking\/save$/.test(r.url()) && r.request().method() === 'POST');
  const end = await openEditor(page, row, 'end');
  await end.fill('09:00');
  await page.keyboard.press('Enter');
  await saved;

  await expect(page.getByRole('gridcell', { name: stamp })).toBeVisible();
  return stamp;
}
