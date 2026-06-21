import { expect, type Locator, type Page } from '@playwright/test';

/**
 * Shared Worklog-grid helpers. The seeded entries predate the default day range,
 * so tests create their own entry for *today* via the Add flow. Relation cells
 * (customer/project/activity) are Ark Combobox chip editors — picked by opening
 * the cell, optionally filtering, and clicking the first option.
 */

export async function openTextEditor(page: Page, row: Locator, colKey: string): Promise<Locator> {
  await row.locator(`td[data-col-key="${colKey}"]`).focus();
  await page.keyboard.press('Enter');
  const editor = page.locator('td[data-inline-editing] input.inline-editor').first();
  await expect(editor).toBeVisible();
  return editor;
}

// Open a relation cell's combobox (Add already opens the first one) and pick its
// first available option, committing it.
export async function pickFirstOption(page: Page, row: Locator, colKey: string, alreadyOpen = false): Promise<void> {
  if (!alreadyOpen) {
    await row.locator(`td[data-col-key="${colKey}"]`).focus();
    await page.keyboard.press('Enter');
  }
  await expect(page.locator('.combobox-input').first()).toBeVisible();
  const option = page.locator('.combobox-content .combobox-item').first();
  await expect(option).toBeVisible({ timeout: 8000 });
  await option.click();
  await expect(page.locator('.combobox-content')).toBeHidden({ timeout: 4000 });
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

  // Add opens the customer combobox; pick the first bookable customer, then its
  // first (cascade-filtered) project, then the first activity.
  await pickFirstOption(page, row, 'customer', true);
  await pickFirstOption(page, row, 'project');
  await pickFirstOption(page, row, 'activity');

  const description = await openTextEditor(page, row, 'description');
  await description.fill(stamp);
  await page.keyboard.press('Enter');

  const start = await openTextEditor(page, row, 'start');
  await start.fill('08:00');
  await page.keyboard.press('Enter');

  const saved = page.waitForResponse((r) => /\/tracking\/save$/.test(r.url()) && r.request().method() === 'POST');
  const end = await openTextEditor(page, row, 'end');
  await end.fill('09:00');
  await page.keyboard.press('Enter');
  await saved;

  await expect(page.getByRole('gridcell', { name: stamp })).toBeVisible();
  return stamp;
}
