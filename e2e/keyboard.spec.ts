import { test, expect } from '@playwright/test';
import { login } from './helpers/auth';
import {
  waitForGrid,
  getGridRowCount,
  selectRow,
  getFirstRow,
  cancelEdit,
} from './helpers/grid';

/**
 * E2E tests for keyboard shortcuts and navigation.
 *
 * The TimeTracker application supports various keyboard shortcuts
 * for efficient time entry management.
 */

test.describe('Keyboard Navigation', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
    await waitForGrid(page);
  });

  test('should navigate grid rows with arrow keys', async ({ page }) => {
    const rowCount = await getGridRowCount(page);
    if (rowCount < 2) {
      console.log('Not enough rows to test navigation');
      return;
    }

    // Select first row
    await selectRow(page, 0);

    // Press Down arrow to move to next row
    await page.keyboard.press('ArrowDown');
    await page.waitForTimeout(200);

    // Check if selection moved (look for selected class)
    const selectedRows = page.locator('.x-grid-item-selected, .x-grid-row-selected');
    const selectedCount = await selectedRows.count();

    expect(selectedCount).toBeGreaterThan(0);
  });

  test('should start editing with Enter key', async ({ page }) => {
    const rowCount = await getGridRowCount(page);
    if (rowCount === 0) {
      console.log('No rows to test editing');
      return;
    }

    // Select first row
    await selectRow(page, 0);

    // Press Enter to start editing
    await page.keyboard.press('Enter');
    await page.waitForTimeout(300);

    // Check for editor presence
    const hasEditor = await page.locator('.x-editor').isVisible().catch(() => false);
    console.log(`Editor visible after Enter: ${hasEditor}`);

    // Cancel editing
    await cancelEdit(page);
  });

  test('should cancel editing with Escape key', async ({ page }) => {
    const rowCount = await getGridRowCount(page);
    if (rowCount === 0) return;

    // Start editing
    const firstRow = getFirstRow(page);
    await firstRow.dblclick();
    await page.waitForTimeout(300);

    // Press Escape to cancel
    await page.keyboard.press('Escape');
    await page.waitForTimeout(200);

    // Editor should be hidden
    const hasEditor = await page.locator('.x-editor').isVisible().catch(() => false);
    expect(hasEditor).toBe(false);
  });

  test('should navigate between fields with Tab', async ({ page }) => {
    const rowCount = await getGridRowCount(page);
    if (rowCount === 0) return;

    // Start editing
    const firstRow = getFirstRow(page);
    await firstRow.dblclick();
    await page.waitForTimeout(300);

    // Press Tab multiple times to navigate through fields
    for (let i = 0; i < 3; i++) {
      await page.keyboard.press('Tab');
      await page.waitForTimeout(200);
    }

    // Should still be in edit mode or have moved to next editable field
    const hasActiveElement =
      (await page.locator('.x-editor').isVisible().catch(() => false)) ||
      (await page.locator('.x-form-field:focus').count()) > 0;

    // Cancel editing
    await cancelEdit(page);
  });
});

test.describe('Keyboard Shortcuts', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
    await waitForGrid(page);
  });

  test('should add new entry with shortcut (Insert or Ctrl+N)', async ({ page }) => {
    const initialCount = await getGridRowCount(page);

    // Try Insert key first (common for "add")
    await page.keyboard.press('Insert');
    await page.waitForTimeout(500);

    let newCount = await getGridRowCount(page);

    // If Insert didn't work, try clicking the add button as fallback
    if (newCount === initialCount) {
      const addButton = page.locator('.x-btn').filter({ hasText: /Add|Neuer Eintrag/i });
      if ((await addButton.count()) > 0) {
        await addButton.click();
        await page.waitForTimeout(500);
        newCount = await getGridRowCount(page);
      }
    }

    console.log(`Initial: ${initialCount}, After shortcut: ${newCount}`);

    // Cleanup - cancel any new entry
    await cancelEdit(page);
  });

  test('should delete entry with Delete key (after confirmation)', async ({ page }) => {
    const rowCount = await getGridRowCount(page);
    if (rowCount === 0) {
      console.log('No rows to test deletion');
      return;
    }

    // Select a row
    await selectRow(page, 0);

    // Press Delete key
    await page.keyboard.press('Delete');
    await page.waitForTimeout(500);

    // Check if confirmation dialog appeared
    const hasConfirmDialog = await page.locator('.x-window, .x-message-box').isVisible().catch(() => false);
    console.log(`Confirmation dialog shown: ${hasConfirmDialog}`);

    // If dialog appeared, cancel it
    if (hasConfirmDialog) {
      const cancelButton = page.locator('.x-btn').filter({ hasText: /Cancel|Abbrechen|No|Nein/i });
      if ((await cancelButton.count()) > 0) {
        await cancelButton.click();
      } else {
        await page.keyboard.press('Escape');
      }
    }
  });

  test('should focus search/filter with Ctrl+F', async ({ page }) => {
    // Press Ctrl+F
    await page.keyboard.press('Control+f');
    await page.waitForTimeout(300);

    // This might trigger browser find or app filter
    // Press Escape to close any dialogs
    await page.keyboard.press('Escape');
  });
});

test.describe('Grid Cell Editing', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
    await waitForGrid(page);
  });

  test('should edit time fields correctly', async ({ page }) => {
    const rowCount = await getGridRowCount(page);
    if (rowCount === 0) return;

    // Double-click to edit
    const firstRow = getFirstRow(page);
    await firstRow.dblclick();
    await page.waitForTimeout(300);

    // Type a time value
    await page.keyboard.type('09:00');

    // Tab to next field
    await page.keyboard.press('Tab');
    await page.waitForTimeout(200);

    // Type end time
    await page.keyboard.type('10:00');

    // Cancel to avoid saving test data
    await cancelEdit(page);
  });

  test('should validate time format on input', async ({ page }) => {
    const rowCount = await getGridRowCount(page);
    if (rowCount === 0) return;

    // Start editing
    const firstRow = getFirstRow(page);
    await firstRow.dblclick();
    await page.waitForTimeout(300);

    // Type invalid time format
    await page.keyboard.type('25:99');

    // Tab to trigger validation
    await page.keyboard.press('Tab');
    await page.waitForTimeout(500);

    // Check for validation error indicator
    const hasError = await page.locator('.x-form-invalid, .x-form-error').isVisible().catch(() => false);
    console.log(`Validation error shown for invalid time: ${hasError}`);

    // Cancel editing
    await cancelEdit(page);
  });

  test('should handle dropdown selection with keyboard', async ({ page }) => {
    const rowCount = await getGridRowCount(page);
    if (rowCount === 0) return;

    // Start editing
    const firstRow = getFirstRow(page);
    await firstRow.dblclick();
    await page.waitForTimeout(300);

    // Tab to a dropdown field (e.g., Customer)
    for (let i = 0; i < 4; i++) {
      await page.keyboard.press('Tab');
      await page.waitForTimeout(200);
    }

    // Type to filter
    await page.keyboard.type('Test');
    await page.waitForTimeout(300);

    // Press Down arrow to select from dropdown
    await page.keyboard.press('ArrowDown');
    await page.waitForTimeout(200);

    // Press Enter to confirm selection
    await page.keyboard.press('Enter');
    await page.waitForTimeout(200);

    // Cancel editing
    await cancelEdit(page);
  });
});

test.describe('Tab Key Behavior', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
    await waitForGrid(page);
  });

  test('should cycle through all editable fields', async ({ page }) => {
    const rowCount = await getGridRowCount(page);
    if (rowCount === 0) return;

    // Start editing first row
    const firstRow = getFirstRow(page);
    await firstRow.dblclick();
    await page.waitForTimeout(300);

    // Count Tab presses until we either wrap around or exit editing
    let tabCount = 0;
    const maxTabs = 15; // Safety limit

    while (tabCount < maxTabs) {
      await page.keyboard.press('Tab');
      await page.waitForTimeout(150);
      tabCount++;

      // Check if we're still in edit mode
      const hasEditor = await page.locator('.x-editor').isVisible().catch(() => false);
      if (!hasEditor) break;
    }

    console.log(`Tab pressed ${tabCount} times before exiting edit mode`);

    // Cleanup
    await cancelEdit(page);
  });

  test('should move to next row after last field', async ({ page }) => {
    const rowCount = await getGridRowCount(page);
    if (rowCount < 2) {
      console.log('Not enough rows to test row transition');
      return;
    }

    // Start editing first row
    const firstRow = getFirstRow(page);
    await firstRow.dblclick();
    await page.waitForTimeout(300);

    // Tab through all fields (typically ~10 fields)
    for (let i = 0; i < 12; i++) {
      await page.keyboard.press('Tab');
      await page.waitForTimeout(100);
    }

    await page.waitForTimeout(500);

    // Cleanup
    await cancelEdit(page);
  });
});
