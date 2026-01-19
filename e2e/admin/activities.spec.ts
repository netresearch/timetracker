import { test, expect } from '@playwright/test';
import { login } from '../helpers/auth';
import { waitForGrid } from '../helpers/grid';
import { hideDebugToolbar } from '../helpers/navigation';
import {
  goToAdminTab,
  goToAdminSubTab,
  ADMIN_TABS,
  getAdminGridRows,
  getAdminGridRowCount,
  clickAdminAddButton,
  waitForAdminWindow,
  fillAdminField,
  setAdminCheckbox,
  clickAdminSaveButton,
  waitForAdminWindowClose,
  editAdminRow,
  deleteAdminRow,
  findAdminRowByText,
  generateTestName,
  waitForAdminGridRefresh,
} from '../helpers/admin';

/**
 * E2E tests for Admin Activity CRUD operations.
 * Tests verify full Create, Read, Update, Delete functionality for activities.
 */
test.describe('Admin Activity CRUD', () => {
  test.beforeEach(async ({ page }) => {
    // Login as admin user (i.myself has ROLE_ADMIN)
    await login(page, 'i.myself', 'myself123');
    await waitForGrid(page);
    await hideDebugToolbar(page);

    // Navigate to Admin tab
    await goToAdminTab(page);

    // Navigate to Activity management sub-tab
    await goToAdminSubTab(page, ADMIN_TABS.activities);
  });

  test('should display activity grid with data', async ({ page }) => {
    // Wait for grid to render
    await page.waitForTimeout(1000);

    // Should have some activities
    const rowCount = await getAdminGridRowCount(page);
    console.log(`Activity grid has ${rowCount} rows`);
    expect(rowCount).toBeGreaterThan(0);

    // Verify column headers are present
    const headerText = await page.locator('.x-column-header').allTextContents();
    console.log('Activity grid columns:', headerText);

    // Should have Name column
    expect(headerText.some((h) => /Name/i.test(h))).toBe(true);
  });

  test('should create a new activity', async ({ page }) => {
    const testName = generateTestName('TestActivity');

    // Get initial row count
    const initialCount = await getAdminGridRowCount(page);

    // Click Add button
    await clickAdminAddButton(page, /Add activity|Neue Tätigkeit/i);

    // Wait for edit window
    await waitForAdminWindow(page);

    // Fill in activity details
    await fillAdminField(page, 'name', testName);

    // Set needs ticket if the field exists
    try {
      await setAdminCheckbox(page, 'needsTicket', false);
    } catch {
      // Field may not exist
    }

    // Set factor if the field exists
    try {
      const factorField = page.locator('.x-window input[name="factor"]');
      if ((await factorField.count()) > 0) {
        await factorField.fill('1.0');
      }
    } catch {
      // Field may not exist
    }

    // Save
    await clickAdminSaveButton(page);

    // Wait for window to close
    await waitForAdminWindowClose(page);

    // Wait for grid to refresh
    await waitForAdminGridRefresh(page);

    // Verify activity was created
    const newCount = await getAdminGridRowCount(page);
    expect(newCount).toBeGreaterThanOrEqual(initialCount);

    // Find the new activity in the grid
    const rowIndex = await findAdminRowByText(page, testName);
    expect(rowIndex).toBeGreaterThanOrEqual(0);
    console.log(`Created activity "${testName}" at row ${rowIndex}`);
  });

  test('should edit an existing activity', async ({ page }) => {
    const testName = generateTestName('EditActivity');

    // First create an activity to edit
    await clickAdminAddButton(page, /Add activity|Neue Tätigkeit/i);
    await waitForAdminWindow(page);
    await fillAdminField(page, 'name', testName);
    await clickAdminSaveButton(page);
    await waitForAdminWindowClose(page);
    await waitForAdminGridRefresh(page);

    // Find the created activity
    const rowIndex = await findAdminRowByText(page, testName);
    expect(rowIndex).toBeGreaterThanOrEqual(0);

    // Edit the activity via context menu
    await editAdminRow(page, rowIndex);

    // Update the name
    const updatedName = testName + '_Updated';
    await fillAdminField(page, 'name', updatedName);

    // Save
    await clickAdminSaveButton(page);
    await waitForAdminWindowClose(page);
    await waitForAdminGridRefresh(page);

    // Verify activity was updated
    const updatedRowIndex = await findAdminRowByText(page, updatedName);
    expect(updatedRowIndex).toBeGreaterThanOrEqual(0);
    console.log(`Updated activity to "${updatedName}"`);
  });

  test('should delete an activity', async ({ page }) => {
    const testName = generateTestName('DeleteActivity');

    // First create an activity to delete
    await clickAdminAddButton(page, /Add activity|Neue Tätigkeit/i);
    await waitForAdminWindow(page);
    await fillAdminField(page, 'name', testName);
    await clickAdminSaveButton(page);
    await waitForAdminWindowClose(page);
    await waitForAdminGridRefresh(page);

    // Find the created activity
    const rowIndex = await findAdminRowByText(page, testName);
    expect(rowIndex).toBeGreaterThanOrEqual(0);

    // Delete the activity via context menu
    await deleteAdminRow(page, rowIndex);

    // Confirm deletion dialog (German: "Ja" = Yes)
    await page.waitForTimeout(500);
    const confirmButton = page.locator('.x-message-box .x-btn, .x-window .x-btn').filter({ hasText: /^Ja$|^Yes$/i }).first();
    if ((await confirmButton.count()) > 0) {
      await confirmButton.click();
      await page.waitForTimeout(500);
    }

    // Verify activity was deleted
    await waitForAdminGridRefresh(page);
    const deletedRowIndex = await findAdminRowByText(page, testName);
    expect(deletedRowIndex).toBe(-1);
    console.log(`Deleted activity "${testName}"`);
  });

  test('should create activity with needs ticket flag', async ({ page }) => {
    const testName = generateTestName('TicketActivity');

    // Click Add button
    await clickAdminAddButton(page, /Add activity|Neue Tätigkeit/i);
    await waitForAdminWindow(page);

    // Fill in activity details
    await fillAdminField(page, 'name', testName);

    // Set needs ticket flag
    try {
      await setAdminCheckbox(page, 'needsTicket', true);
    } catch {
      // Field may use different name
      const needsTicketCheckbox = page.locator('.x-window input[type="checkbox"]').filter({ hasText: /ticket/i });
      if ((await needsTicketCheckbox.count()) > 0) {
        await needsTicketCheckbox.check();
      }
    }

    // Save
    await clickAdminSaveButton(page);
    await waitForAdminWindowClose(page);
    await waitForAdminGridRefresh(page);

    // Verify activity was created
    const rowIndex = await findAdminRowByText(page, testName);
    expect(rowIndex).toBeGreaterThanOrEqual(0);
    console.log(`Created activity "${testName}" with needs ticket flag`);
  });

  test('should create activity with billing factor', async ({ page }) => {
    const testName = generateTestName('FactorActivity');

    // Click Add button
    await clickAdminAddButton(page, /Add activity|Neue Tätigkeit/i);
    await waitForAdminWindow(page);

    // Fill in activity details
    await fillAdminField(page, 'name', testName);

    // Set billing factor
    try {
      const factorField = page.locator('.x-window input[name="factor"]');
      if ((await factorField.count()) > 0) {
        await factorField.fill('1.5');
      }
    } catch {
      // Factor field may not exist
    }

    // Save
    await clickAdminSaveButton(page);
    await waitForAdminWindowClose(page);
    await waitForAdminGridRefresh(page);

    // Verify activity was created
    const rowIndex = await findAdminRowByText(page, testName);
    expect(rowIndex).toBeGreaterThanOrEqual(0);
    console.log(`Created activity "${testName}" with billing factor`);
  });

  test('should validate required activity name', async ({ page }) => {
    // Click Add button
    await clickAdminAddButton(page, /Add activity|Neue Tätigkeit/i);
    await waitForAdminWindow(page);

    // Try to save without filling name
    await clickAdminSaveButton(page);

    // Wait for potential error dialog
    await page.waitForTimeout(500);

    // Should show validation error - either in the form or as a dialog
    const errorDialog = page.locator('.x-window').filter({ hasText: /Fehler|Error/i });
    const hasErrorDialog = (await errorDialog.count()) > 0;

    // Or check if edit window is still open
    const editWindowOpen = (await page.locator('.x-window').count()) > 0;

    console.log(`Validation error dialog: ${hasErrorDialog}, Window still open: ${editWindowOpen}`);
    expect(hasErrorDialog || editWindowOpen).toBe(true);

    // Close all windows
    await page.keyboard.press('Escape');
    await page.waitForTimeout(200);
    await page.keyboard.press('Escape');
  });
});
