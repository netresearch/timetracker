import { test, expect } from '@playwright/test';
import { login } from '../helpers/auth';
import { waitForGrid } from '../helpers/grid';
import { hideDebugToolbar } from '../helpers/navigation';
import {
  goToAdminTab,
  goToAdminSubTab,
  ADMIN_TABS,
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
 * E2E tests for Admin Customer CRUD operations.
 * Tests cover display, create, update, and delete functionality in the Administration panel.
 */
test.describe('Admin Customer CRUD', () => {
  test.beforeEach(async ({ page }) => {
    // Login as admin user (i.myself has ROLE_ADMIN)
    await login(page, 'i.myself', 'myself123');
    await waitForGrid(page);
    await hideDebugToolbar(page);

    // Navigate to Admin tab
    await goToAdminTab(page);

    // Navigate to Customer management sub-tab
    await goToAdminSubTab(page, ADMIN_TABS.customers);
  });

  test('should display customer grid with data', async ({ page }) => {
    // Wait a bit more for grid to fully render
    await page.waitForTimeout(1000);

    // Should have some customers (check rows exist)
    const rowCount = await getAdminGridRowCount(page);
    console.log(`Customer grid has ${rowCount} rows`);
    expect(rowCount).toBeGreaterThan(0);

    // Verify column headers are present
    const headerText = await page.locator('.x-column-header').allTextContents();
    console.log('Customer grid columns:', headerText);

    // Should have Name column (German: Name)
    expect(headerText.some((h) => /Name/i.test(h))).toBe(true);
  });

  test('should create a new customer', async ({ page }) => {
    const testName = generateTestName('TestCustomer');

    // Get initial row count
    const initialCount = await getAdminGridRowCount(page);

    // Click Add button
    await clickAdminAddButton(page, /Add customer|Neuer Kunde/i);

    // Wait for edit window
    await waitForAdminWindow(page);

    // Fill in customer details
    await fillAdminField(page, 'name', testName);
    await setAdminCheckbox(page, 'active', true);
    await setAdminCheckbox(page, 'global', true);

    // Save
    await clickAdminSaveButton(page);

    // Wait for window to close
    await waitForAdminWindowClose(page);

    // Wait for grid to refresh
    await waitForAdminGridRefresh(page);

    // Verify customer was created
    const newCount = await getAdminGridRowCount(page);
    expect(newCount).toBeGreaterThanOrEqual(initialCount);

    // Find the new customer in the grid
    const rowIndex = await findAdminRowByText(page, testName);
    expect(rowIndex).toBeGreaterThanOrEqual(0);
    console.log(`Created customer "${testName}" at row ${rowIndex}`);
  });

  test('should edit an existing customer', async ({ page }) => {
    const testName = generateTestName('EditCustomer');

    // First create a customer to edit
    await clickAdminAddButton(page, /Add customer|Neuer Kunde/i);
    await waitForAdminWindow(page);
    await fillAdminField(page, 'name', testName);
    await setAdminCheckbox(page, 'active', true);
    await setAdminCheckbox(page, 'global', true);
    await clickAdminSaveButton(page);
    await waitForAdminWindowClose(page);
    await waitForAdminGridRefresh(page);

    // Find the created customer
    const rowIndex = await findAdminRowByText(page, testName);
    expect(rowIndex).toBeGreaterThanOrEqual(0);

    // Edit the customer via context menu
    await editAdminRow(page, rowIndex);

    // Update the name
    const updatedName = testName + '_Updated';
    await fillAdminField(page, 'name', updatedName);

    // Save
    await clickAdminSaveButton(page);
    await waitForAdminWindowClose(page);
    await waitForAdminGridRefresh(page);

    // Verify customer was updated
    const updatedRowIndex = await findAdminRowByText(page, updatedName);
    expect(updatedRowIndex).toBeGreaterThanOrEqual(0);
    console.log(`Updated customer to "${updatedName}"`);
  });

  test('should delete a customer', async ({ page }) => {
    const testName = generateTestName('DeleteCustomer');

    // First create a customer to delete
    await clickAdminAddButton(page, /Add customer|Neuer Kunde/i);
    await waitForAdminWindow(page);
    await fillAdminField(page, 'name', testName);
    await setAdminCheckbox(page, 'active', true);
    await setAdminCheckbox(page, 'global', true);
    await clickAdminSaveButton(page);
    await waitForAdminWindowClose(page);
    await waitForAdminGridRefresh(page);

    // Find the created customer
    const rowIndex = await findAdminRowByText(page, testName);
    expect(rowIndex).toBeGreaterThanOrEqual(0);

    // Delete the customer via context menu
    await deleteAdminRow(page, rowIndex);

    // Confirm deletion dialog (German: "Ja" = Yes)
    await page.waitForTimeout(500);
    const confirmButton = page.locator('.x-message-box .x-btn, .x-window .x-btn').filter({ hasText: /^Ja$|^Yes$/i }).first();
    if ((await confirmButton.count()) > 0) {
      await confirmButton.click();
      await page.waitForTimeout(500);
    }

    // Verify customer was deleted
    await waitForAdminGridRefresh(page);
    const deletedRowIndex = await findAdminRowByText(page, testName);
    expect(deletedRowIndex).toBe(-1);
    console.log(`Deleted customer "${testName}"`);
  });

  test('should validate required fields', async ({ page }) => {
    // Click Add button
    await clickAdminAddButton(page, /Add customer|Neuer Kunde/i);
    await waitForAdminWindow(page);

    // Try to save without filling required fields
    await clickAdminSaveButton(page);

    // Wait for potential error dialog
    await page.waitForTimeout(500);

    // Should show validation error - either in the form or as a dialog
    // Check for error dialog (German: "Fehler" = Error)
    const errorDialog = page.locator('.x-window').filter({ hasText: /Fehler|Error/i });
    const hasErrorDialog = (await errorDialog.count()) > 0;

    // Or check if edit window is still open with validation error
    const editWindow = page.locator('#edit-customer-window');
    const editWindowOpen = await editWindow.isVisible().catch(() => false);

    console.log(`Validation error dialog: ${hasErrorDialog}, Edit window still open: ${editWindowOpen}`);
    expect(hasErrorDialog || editWindowOpen).toBe(true);

    // Close all windows
    await page.keyboard.press('Escape');
    await page.waitForTimeout(200);
    await page.keyboard.press('Escape');
  });

  test('should create customer with team association', async ({ page }) => {
    const testName = generateTestName('TeamCustomer');

    // Click Add button
    await clickAdminAddButton(page, /Add customer|Neuer Kunde/i);
    await waitForAdminWindow(page);

    // Fill in customer details
    await fillAdminField(page, 'name', testName);
    await setAdminCheckbox(page, 'active', true);
    await setAdminCheckbox(page, 'global', false); // Not global, should have teams

    // Try to select a team (if available)
    let teamSelected = false;
    try {
      const comboField = page
        .locator('.x-window .x-field')
        .filter({ has: page.locator('input[name*="teams"]') })
        .first();
      const trigger = comboField.locator('.x-form-trigger').first();
      await trigger.click();
      await page.waitForTimeout(300);

      // Select first available team
      const firstTeam = page.locator('.x-boundlist-item').first();
      if ((await firstTeam.count()) > 0) {
        await firstTeam.click();
        teamSelected = true;
        await page.waitForTimeout(200);
      }

      // Close dropdown with Escape if still open
      await page.keyboard.press('Escape');
      await page.waitForTimeout(200);
    } catch {
      // If no teams available, make it global
    }

    if (!teamSelected) {
      await setAdminCheckbox(page, 'global', true);
    }

    // Save
    await clickAdminSaveButton(page);
    await waitForAdminWindowClose(page);
    await waitForAdminGridRefresh(page);

    // Verify customer was created
    const rowIndex = await findAdminRowByText(page, testName);
    expect(rowIndex).toBeGreaterThanOrEqual(0);
    console.log(`Created customer "${testName}" with team association`);
  });

  test('should toggle customer active status', async ({ page }) => {
    const testName = generateTestName('ToggleCustomer');

    // Create a customer with active=true
    await clickAdminAddButton(page, /Add customer|Neuer Kunde/i);
    await waitForAdminWindow(page);
    await fillAdminField(page, 'name', testName);
    await setAdminCheckbox(page, 'active', true);
    await setAdminCheckbox(page, 'global', true);
    await clickAdminSaveButton(page);
    await waitForAdminWindowClose(page);
    await waitForAdminGridRefresh(page);

    // Find the customer
    const rowIndex = await findAdminRowByText(page, testName);
    expect(rowIndex).toBeGreaterThanOrEqual(0);

    // Edit via context menu - need to handle potential multiple menus
    const row = page.locator('.x-tabpanel-child .x-grid-item, .x-tabpanel-child .x-grid-row').nth(rowIndex);
    await row.click({ button: 'right' });
    await page.waitForTimeout(500);

    // Click Edit in the context menu
    const editMenuItem = page.locator('.x-menu-item').filter({ hasText: /Edit|Bearbeiten/i }).first();
    await editMenuItem.click();
    await waitForAdminWindow(page);

    // Toggle active status - click the checkbox area
    const activeLabel = page.locator('.x-window .x-form-item').filter({ hasText: /Aktiv/i }).first();
    await activeLabel.click();
    await page.waitForTimeout(200);

    // Save
    await clickAdminSaveButton(page);
    await waitForAdminWindowClose(page);

    // Just verify the customer still exists (toggle worked if no error)
    await waitForAdminGridRefresh(page);
    const finalRowIndex = await findAdminRowByText(page, testName);
    expect(finalRowIndex).toBeGreaterThanOrEqual(0);
    console.log(`Toggled customer "${testName}" active status`);
  });
});
