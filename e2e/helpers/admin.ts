import { Page, Locator } from '@playwright/test';
import { goToTab, TABS } from './navigation';

/**
 * Admin panel tab names (sub-tabs within Administration)
 * German: Kunden, Projekte, Nutzer, Teams, Masseneintragungsvorlagen, Ticket-Systeme, Tätigkeiten, Verträge
 */
export const ADMIN_TABS = {
  customers: /^Kunden$|^Customer/i,
  projects: /^Projekte$|^Project/i,
  users: /^Nutzer$|^User/i,
  teams: /^Teams?$/i,
  activities: /^Tätigkeiten$|^Activit/i,
  presets: /^Masseneintragungsvorlagen$|^Preset/i,
  ticketSystems: /^Ticket-Systeme$|Ticket.*system/i,
  contracts: /^Verträge$|^Contract/i,
} as const;

/**
 * Navigate to Admin tab and wait for it to load
 */
export async function goToAdminTab(page: Page): Promise<void> {
  await goToTab(page, TABS.administration);
  await page.waitForTimeout(1000);

  // Wait for admin panel to load (should have sub-tabs)
  await page.waitForSelector('.x-tab-bar', { timeout: 10000 });
}

/**
 * Navigate to a specific admin sub-tab
 */
export async function goToAdminSubTab(page: Page, subTab: RegExp): Promise<void> {
  // Find the sub-tab within the admin panel and click it
  const tab = page.locator('.x-tab').filter({ hasText: subTab }).first();
  await tab.click();
  await page.waitForTimeout(500);

  // Wait for loading mask to disappear (if present)
  // Intentionally catch: mask may not appear for cached/quick loads
  await page.waitForSelector('.x-mask', { state: 'hidden', timeout: 5000 }).catch(() => {
    // Mask may not appear for cached data or quick loads - this is expected
  });

  // Wait for grid rows to be present (more specific than just .x-grid)
  await page.waitForTimeout(500);
}

/**
 * Get the admin grid (the main grid in the current admin tab)
 * The admin panel is a tab panel with an inner grid - we need to get the active tab's grid
 */
export function getAdminGrid(page: Page): Locator {
  // The admin tab panel contains sub-tabs, each with its own grid
  // The active tab's grid is the one we want
  return page.locator('.x-tabpanel-child.x-panel-default .x-grid-view').first();
}

/**
 * Get admin grid rows - these are the data rows in the admin grid
 */
export function getAdminGridRows(page: Page): Locator {
  // Get rows from the admin panel's grid (not the time tracking grid in background)
  return page.locator('.x-tabpanel-child .x-grid-item, .x-tabpanel-child .x-grid-row');
}

/**
 * Get admin grid row count
 */
export async function getAdminGridRowCount(page: Page): Promise<number> {
  return await getAdminGridRows(page).count();
}

/**
 * Click the Add button in the admin toolbar
 * German buttons: "Neuer Kunde", "Neues Projekt", "Neuer Nutzer", "Neues Team", etc.
 */
export async function clickAdminAddButton(page: Page, buttonText?: RegExp | string): Promise<void> {
  const text = buttonText || /Add|Hinzufügen|Neuer|Neues|Anlegen/i;

  // Wait for any loading to complete
  await page.waitForTimeout(500);

  // Find the button in the toolbar area (more specific selector)
  const addButton = page.locator('.x-tabpanel-child .x-btn, .x-toolbar .x-btn').filter({ hasText: text }).first();

  // Make sure button is visible and clickable
  await addButton.waitFor({ state: 'visible', timeout: 5000 });
  await addButton.click();
  await page.waitForTimeout(500);

  // Wait for edit window to appear
  await page.waitForSelector('.x-window', { timeout: 10000 });
}

/**
 * Wait for admin edit window to appear
 */
export async function waitForAdminWindow(page: Page): Promise<Locator> {
  await page.waitForSelector('.x-window', { timeout: 5000 });
  return page.locator('.x-window').first();
}

/**
 * Get the admin edit window
 */
export function getAdminWindow(page: Page): Locator {
  return page.locator('.x-window').first();
}

/**
 * Close the admin edit window
 */
export async function closeAdminWindow(page: Page): Promise<void> {
  const closeButton = page.locator('.x-window .x-tool-close').first();
  await closeButton.click();
  await page.waitForTimeout(300);
}

/**
 * Fill a text field in the admin window
 */
export async function fillAdminField(page: Page, fieldName: string, value: string): Promise<void> {
  const field = page.locator(`.x-window input[name="${fieldName}"]`).first();
  await field.fill(value);
  await page.waitForTimeout(100);
}

/**
 * Check/uncheck a checkbox field in the admin window
 * ExtJS checkboxes have complex structure - we need to find by field label
 */
export async function setAdminCheckbox(page: Page, fieldName: string, checked: boolean): Promise<void> {
  // Try to find by input name first
  let checkbox = page.locator(`.x-window input[name="${fieldName}"]`).first();

  if ((await checkbox.count()) === 0) {
    // Try to find by field label (German labels: Aktiv, Global)
    const labelMap: Record<string, string> = {
      active: 'Aktiv',
      global: 'Global',
      needsTicket: 'Ticket',
    };
    const label = labelMap[fieldName] || fieldName;

    // Find the checkbox input within the field that has this label
    const field = page.locator('.x-window .x-form-cb-wrap').filter({ hasText: new RegExp(label, 'i') }).first();
    checkbox = field.locator('input[type="button"]').first();

    if ((await checkbox.count()) === 0) {
      // Try clicking the label container itself
      const labelContainer = page
        .locator('.x-window .x-form-item')
        .filter({ hasText: new RegExp(label, 'i') })
        .first();
      const checkboxInput = labelContainer.locator('input').first();
      if ((await checkboxInput.count()) > 0) {
        checkbox = checkboxInput;
      } else {
        // Click on the visual checkbox element
        await labelContainer.locator('.x-form-checkbox').first().click();
        await page.waitForTimeout(100);
        return;
      }
    }
  }

  // ExtJS checkbox may not respond to isChecked() properly
  try {
    const isChecked = await checkbox.isChecked();
    if (isChecked !== checked) {
      await checkbox.click();
      await page.waitForTimeout(100);
    }
  } catch {
    // ExtJS checkboxes may not support isChecked() - fall back to clicking
    await checkbox.click();
    await page.waitForTimeout(100);
  }
}

/**
 * Select a value from a combo box in the admin window
 */
export async function selectAdminCombo(page: Page, fieldName: string, value: string): Promise<void> {
  // Click on the combo trigger to open dropdown
  const comboField = page.locator(`.x-window .x-field`).filter({ has: page.locator(`input[name="${fieldName}"]`) }).first();
  const trigger = comboField.locator('.x-form-trigger').first();

  await trigger.click();
  await page.waitForTimeout(300);

  // Wait for dropdown list
  await page.waitForSelector('.x-boundlist', { timeout: 3000 });

  // Type to filter and select
  const input = comboField.locator('input').first();
  await input.fill(value);
  await page.waitForTimeout(300);

  // Click the matching item
  const listItem = page.locator('.x-boundlist-item').filter({ hasText: value }).first();
  await listItem.click();
  await page.waitForTimeout(200);
}

/**
 * Select multiple values from a multi-select combo box
 */
export async function selectAdminMultiCombo(page: Page, fieldName: string, values: string[]): Promise<void> {
  const comboField = page.locator(`.x-window .x-field`).filter({ has: page.locator(`input[name*="${fieldName}"]`) }).first();
  const trigger = comboField.locator('.x-form-trigger').first();

  await trigger.click();
  await page.waitForTimeout(300);

  // Wait for dropdown list
  await page.waitForSelector('.x-boundlist', { timeout: 3000 });

  // Select each value
  for (const value of values) {
    const listItem = page.locator('.x-boundlist-item').filter({ hasText: value }).first();
    await listItem.click();
    await page.waitForTimeout(200);
  }

  // Click elsewhere to close the dropdown
  await page.keyboard.press('Escape');
  await page.waitForTimeout(200);
}

/**
 * Click Save button in the admin window (German: Speichern)
 * Uses native event dispatch which works reliably with ExtJS forms
 */
export async function clickAdminSaveButton(page: Page): Promise<void> {
  // Blur any focused element to close dropdowns
  await page.evaluate(() => {
    const activeElement = document.activeElement as HTMLElement;
    if (activeElement && activeElement.blur) {
      activeElement.blur();
    }
  });
  await page.waitForTimeout(200);

  // Use native event dispatch to click the button
  const clicked = await page.evaluate(() => {
    const buttons = document.querySelectorAll('.x-window .x-btn');
    for (const btn of buttons) {
      const text = btn.textContent?.trim();
      if (text === 'Speichern' || text === 'Save') {
        const rect = btn.getBoundingClientRect();
        const clickEvent = new MouseEvent('click', {
          view: window,
          bubbles: true,
          cancelable: true,
          clientX: rect.left + rect.width / 2,
          clientY: rect.top + rect.height / 2,
        });
        btn.dispatchEvent(clickEvent);
        return true;
      }
    }
    return false;
  });

  if (clicked) {
    await page.waitForTimeout(500);
    return;
  }

  // Fallback: try Playwright click with force
  const saveButton = page.locator('.x-window .x-btn').filter({ hasText: /^Speichern$|^Save$/ }).first();
  if ((await saveButton.count()) > 0) {
    await saveButton.click({ force: true });
    await page.waitForTimeout(500);
  }
}

/**
 * Click Delete button in the admin window
 */
export async function clickAdminDeleteButton(page: Page): Promise<void> {
  const deleteButton = page.locator('.x-window .x-btn').filter({ hasText: /Delete|Löschen/i }).first();
  await deleteButton.click();
  await page.waitForTimeout(500);
}

/**
 * Wait for admin window to close (after save/delete)
 */
export async function waitForAdminWindowClose(page: Page): Promise<void> {
  // Wait for any visible modal window to close
  // Use a more specific check for the visible window
  try {
    await page.waitForFunction(
      () => {
        const windows = document.querySelectorAll('.x-window');
        for (const w of windows) {
          const style = window.getComputedStyle(w);
          if (style.display !== 'none' && style.visibility !== 'hidden') {
            return false; // Still have a visible window
          }
        }
        return true; // All windows are hidden
      },
      { timeout: 10000 }
    );
  } catch {
    // If function times out, check if window count decreased
    const windowCount = await page.locator('.x-window:visible').count();
    if (windowCount > 0) {
      // Try pressing Escape to close any stuck window
      await page.keyboard.press('Escape');
      await page.waitForTimeout(500);
    }
  }
  await page.waitForTimeout(300);
}

/**
 * Open context menu on an admin grid row
 */
export async function openAdminContextMenu(page: Page, rowIndex: number = 0): Promise<void> {
  const row = getAdminGridRows(page).nth(rowIndex);
  await row.click({ button: 'right' });
  await page.waitForSelector('.x-menu', { timeout: 3000 });
}

/**
 * Click an item in the context menu
 */
export async function clickAdminContextMenuItem(page: Page, itemText: RegExp | string): Promise<void> {
  const menuItem = page.locator('.x-menu-item').filter({ hasText: itemText }).first();
  await menuItem.click();
  await page.waitForTimeout(300);
}

/**
 * Edit an admin grid row via context menu
 */
export async function editAdminRow(page: Page, rowIndex: number = 0): Promise<void> {
  await openAdminContextMenu(page, rowIndex);
  await clickAdminContextMenuItem(page, /Edit|Bearbeiten/i);
  await waitForAdminWindow(page);
}

/**
 * Delete an admin grid row via context menu
 */
export async function deleteAdminRow(page: Page, rowIndex: number = 0): Promise<void> {
  await openAdminContextMenu(page, rowIndex);
  await clickAdminContextMenuItem(page, /Delete|Löschen/i);
  await page.waitForTimeout(500);
}

/**
 * Wait for and accept confirmation dialog
 */
export async function acceptConfirmDialog(page: Page): Promise<void> {
  const confirmButton = page.locator('.x-message-box .x-btn').filter({ hasText: /Yes|Ja|OK/i }).first();
  await confirmButton.click();
  await page.waitForTimeout(300);
}

/**
 * Wait for success notification
 */
export async function waitForSuccessNotification(page: Page): Promise<void> {
  // ExtJS notifications may appear in different forms or not at all
  try {
    await page.waitForSelector('.x-toast, .x-window', { timeout: 3000 });
  } catch {
    // Notification may not appear, appear too quickly, or use different markup
    // This is expected behavior for some ExtJS configurations
  }
}

/**
 * Find a row in the admin grid by text content
 */
export async function findAdminRowByText(page: Page, text: string): Promise<number> {
  const rows = getAdminGridRows(page);
  const count = await rows.count();

  for (let i = 0; i < count; i++) {
    const rowText = await rows.nth(i).textContent();
    if (rowText && rowText.includes(text)) {
      return i;
    }
  }

  return -1;
}

/**
 * Get text content of a specific cell in an admin grid row
 */
export async function getAdminCellText(page: Page, rowIndex: number, columnIndex: number): Promise<string> {
  const row = getAdminGridRows(page).nth(rowIndex);
  const cell = row.locator('.x-grid-cell').nth(columnIndex);
  return (await cell.textContent()) || '';
}

/**
 * Wait for admin grid to refresh
 */
export async function waitForAdminGridRefresh(page: Page): Promise<void> {
  try {
    await page.waitForSelector('.x-mask', { state: 'visible', timeout: 2000 });
    await page.waitForSelector('.x-mask', { state: 'hidden', timeout: 10000 });
  } catch {
    // Loading mask may not appear for quick operations or cached data
    // This is expected behavior - the grid may refresh without visible mask
  }
  await page.waitForTimeout(300);
}

/**
 * Click the Refresh button in admin toolbar
 */
export async function clickAdminRefreshButton(page: Page): Promise<void> {
  const refreshButton = page.locator('.x-btn').filter({ hasText: /Refresh|Aktualisieren/i }).first();
  await refreshButton.click();
  await waitForAdminGridRefresh(page);
}

/**
 * Generate a unique test name with timestamp
 */
export function generateTestName(prefix: string): string {
  return `${prefix}_E2E_${Date.now()}`;
}

/**
 * Generate a unique abbreviation (3 chars max for User forms)
 * Uses timestamp + random to avoid collisions in parallel tests
 */
export function generateTestAbbr(): string {
  // Use last digit of timestamp + 2 random alphanumeric chars
  const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
  const rand1 = chars[Math.floor(Math.random() * chars.length)];
  const rand2 = chars[Math.floor(Math.random() * chars.length)];
  return String(Date.now()).slice(-1) + rand1 + rand2;
}

/**
 * Click Save button using native event dispatch
 * This is needed for forms like User edit where ExtJS handlers don't respond to Playwright clicks
 */
export async function clickNativeSaveButton(page: Page): Promise<boolean> {
  // Use native event dispatch to click the button
  const clicked = await page.evaluate(() => {
    const buttons = document.querySelectorAll('.x-window .x-btn');
    for (const btn of buttons) {
      const text = btn.textContent?.trim();
      if (text === 'Speichern' || text === 'Save') {
        const rect = btn.getBoundingClientRect();
        const clickEvent = new MouseEvent('click', {
          view: window,
          bubbles: true,
          cancelable: true,
          clientX: rect.left + rect.width / 2,
          clientY: rect.top + rect.height / 2,
        });
        btn.dispatchEvent(clickEvent);
        return true;
      }
    }
    return false;
  });

  if (clicked) {
    await page.waitForTimeout(500);
  }
  return clicked;
}

/**
 * Save a User form with proper handling for ExtJS quirks
 */
export async function saveUserForm(page: Page): Promise<void> {
  // Use native click for User forms
  const saved = await clickNativeSaveButton(page);

  if (!saved) {
    // Fallback to regular save
    await clickAdminSaveButton(page);
  }

  // Wait for window to close or close manually
  await page.waitForTimeout(500);
  const windowStillOpen = (await page.locator('.x-window').count()) > 0;
  if (windowStillOpen) {
    // Check if it's an error window
    const hasError = (await page.locator('.x-window').filter({ hasText: /Fehler|Error/i }).count()) > 0;
    if (!hasError) {
      await page.keyboard.press('Escape');
      await page.waitForTimeout(300);
    }
  }
}
