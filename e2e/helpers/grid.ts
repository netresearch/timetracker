import { Page, Locator } from '@playwright/test';

/**
 * Wait for ExtJS grid to be fully loaded
 */
export async function waitForGrid(page: Page, timeout: number = 15000): Promise<void> {
  await page.waitForSelector('.x-grid', { timeout });
  // Wait for any loading masks to disappear
  await page.waitForSelector('.x-mask', { state: 'hidden', timeout: 5000 }).catch(() => {});
  await page.waitForTimeout(500);
}

/**
 * Get all grid rows
 */
export function getGridRows(page: Page): Locator {
  return page.locator('.x-grid-row, .x-grid-item');
}

/**
 * Get grid row count
 */
export async function getGridRowCount(page: Page): Promise<number> {
  return await getGridRows(page).count();
}

/**
 * Get first grid row
 */
export function getFirstRow(page: Page): Locator {
  return getGridRows(page).first();
}

/**
 * Click the Add button to create a new entry
 */
export async function clickAddButton(page: Page): Promise<void> {
  const addButton = page.locator('.x-btn').filter({ hasText: /Add|Neuer Eintrag/i });
  await addButton.click();
  await page.waitForTimeout(500);
}

/**
 * Double-click on a grid row to edit it
 */
export async function editRow(page: Page, rowIndex: number = 0): Promise<void> {
  const row = getGridRows(page).nth(rowIndex);
  await row.dblclick();
  await page.waitForTimeout(300);
}

/**
 * Select a row by clicking on it
 */
export async function selectRow(page: Page, rowIndex: number = 0): Promise<void> {
  const row = getGridRows(page).nth(rowIndex);
  await row.click();
  await page.waitForTimeout(200);
}

/**
 * Get cell content from a row
 */
export async function getCellContent(page: Page, rowIndex: number, cellIndex: number): Promise<string | null> {
  const row = getGridRows(page).nth(rowIndex);
  const cell = row.locator('.x-grid-cell').nth(cellIndex);
  return await cell.textContent();
}

/**
 * Right-click on a row to open context menu
 */
export async function openContextMenu(page: Page, rowIndex: number = 0): Promise<void> {
  const row = getGridRows(page).nth(rowIndex);
  await row.click({ button: 'right' });
  await page.waitForSelector('.x-menu', { timeout: 3000 });
}

/**
 * Click a context menu item
 */
export async function clickContextMenuItem(page: Page, menuItemText: RegExp | string): Promise<void> {
  const menuItem = page.locator('.x-menu-item').filter({ hasText: menuItemText });
  await menuItem.click();
  await page.waitForTimeout(300);
}

/**
 * Wait for grid to refresh (loading mask appears and disappears)
 */
export async function waitForGridRefresh(page: Page): Promise<void> {
  try {
    await page.waitForSelector('.x-mask', { state: 'visible', timeout: 2000 });
    await page.waitForSelector('.x-mask', { state: 'hidden', timeout: 10000 });
  } catch {
    // Mask might not appear for quick operations
  }
  await page.waitForTimeout(300);
}

/**
 * Type into the currently active editor field
 */
export async function typeInEditor(page: Page, value: string): Promise<void> {
  await page.keyboard.type(value);
  await page.waitForTimeout(100);
}

/**
 * Move to next field (Tab)
 */
export async function nextField(page: Page): Promise<void> {
  await page.keyboard.press('Tab');
  await page.waitForTimeout(200);
}

/**
 * Cancel editing (Escape)
 */
export async function cancelEdit(page: Page): Promise<void> {
  await page.keyboard.press('Escape');
  await page.waitForTimeout(200);
}

/**
 * Select from a dropdown/combo by typing and selecting
 */
export async function selectFromDropdown(page: Page, searchText: string): Promise<void> {
  await page.keyboard.type(searchText);
  await page.waitForTimeout(300);

  // Wait for dropdown to appear
  try {
    await page.waitForSelector('.x-boundlist', { timeout: 2000 });
    await page.keyboard.press('ArrowDown');
    await page.keyboard.press('Enter');
  } catch {
    // Dropdown might not appear if exact match
    await page.keyboard.press('Enter');
  }
  await page.waitForTimeout(200);
}
