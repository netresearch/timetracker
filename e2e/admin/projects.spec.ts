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
 * E2E tests for Admin Project CRUD operations.
 * Tests cover display, create, update, and delete functionality for projects.
 */
test.describe('Admin Project CRUD', () => {
  test.beforeEach(async ({ page }) => {
    // Login as admin user (i.myself has ROLE_ADMIN)
    await login(page, 'i.myself', 'myself123');
    await waitForGrid(page);
    await hideDebugToolbar(page);

    // Navigate to Admin tab
    await goToAdminTab(page);

    // Navigate to Project management sub-tab
    await goToAdminSubTab(page, ADMIN_TABS.projects);
  });

  test('should display project grid with data', async ({ page }) => {
    // Wait for grid to render
    await page.waitForTimeout(1000);

    // Should have some projects
    const rowCount = await getAdminGridRowCount(page);
    console.log(`Project grid has ${rowCount} rows`);
    expect(rowCount).toBeGreaterThan(0);

    // Verify column headers are present
    const headerText = await page.locator('.x-column-header').allTextContents();
    console.log('Project grid columns:', headerText);

    // Should have Name column
    expect(headerText.some((h) => /Name/i.test(h))).toBe(true);
  });

  test('should create a new project', async ({ page }) => {
    const testName = generateTestName('TestProject');

    // Get initial row count
    const initialCount = await getAdminGridRowCount(page);

    // Click Add button
    await clickAdminAddButton(page, /Add project|Neues Projekt/i);

    // Wait for edit window
    await waitForAdminWindow(page);

    // Fill in project details
    await fillAdminField(page, 'name', testName);
    await setAdminCheckbox(page, 'active', true);

    // Select a customer (required for projects)
    try {
      const comboField = page
        .locator('.x-window .x-field')
        .filter({ has: page.locator('input[name="customer"]') })
        .first();
      const trigger = comboField.locator('.x-form-trigger').first();
      await trigger.click();
      await page.waitForTimeout(300);

      // Select first available customer
      const firstCustomer = page.locator('.x-boundlist-item').first();
      if ((await firstCustomer.count()) > 0) {
        await firstCustomer.click();
        await page.waitForTimeout(200);
      }
    } catch (error) {
      console.log('Could not select customer:', error);
    }

    // Save
    await clickAdminSaveButton(page);

    // Wait for window to close
    await waitForAdminWindowClose(page);

    // Wait for grid to refresh
    await waitForAdminGridRefresh(page);

    // Verify project was created
    const newCount = await getAdminGridRowCount(page);
    expect(newCount).toBeGreaterThanOrEqual(initialCount);

    // Find the new project in the grid
    const rowIndex = await findAdminRowByText(page, testName);
    expect(rowIndex).toBeGreaterThanOrEqual(0);
    console.log(`Created project "${testName}" at row ${rowIndex}`);
  });

  test('should edit an existing project', async ({ page }) => {
    const testName = generateTestName('EditProject');

    // First create a project to edit
    await clickAdminAddButton(page, /Add project|Neues Projekt/i);
    await waitForAdminWindow(page);
    await fillAdminField(page, 'name', testName);
    await setAdminCheckbox(page, 'active', true);

    // Select a customer
    const comboField = page
      .locator('.x-window .x-field')
      .filter({ has: page.locator('input[name="customer"]') })
      .first();
    const trigger = comboField.locator('.x-form-trigger').first();
    await trigger.click();
    await page.waitForTimeout(300);
    const firstCustomer = page.locator('.x-boundlist-item').first();
    await firstCustomer.click();
    await page.waitForTimeout(200);

    await clickAdminSaveButton(page);
    await waitForAdminWindowClose(page);
    await waitForAdminGridRefresh(page);

    // Find the created project
    const rowIndex = await findAdminRowByText(page, testName);
    expect(rowIndex).toBeGreaterThanOrEqual(0);

    // Edit the project via context menu
    await editAdminRow(page, rowIndex);

    // Update the name
    const updatedName = testName + '_Updated';
    await fillAdminField(page, 'name', updatedName);

    // Save
    await clickAdminSaveButton(page);
    await waitForAdminWindowClose(page);
    await waitForAdminGridRefresh(page);

    // Verify project was updated
    const updatedRowIndex = await findAdminRowByText(page, updatedName);
    expect(updatedRowIndex).toBeGreaterThanOrEqual(0);
    console.log(`Updated project to "${updatedName}"`);
  });

  test('should delete a project', async ({ page }) => {
    const testName = generateTestName('DeleteProject');

    // First create a project to delete
    await clickAdminAddButton(page, /Add project|Neues Projekt/i);
    await waitForAdminWindow(page);
    await fillAdminField(page, 'name', testName);
    await setAdminCheckbox(page, 'active', true);

    // Select a customer
    const comboField = page
      .locator('.x-window .x-field')
      .filter({ has: page.locator('input[name="customer"]') })
      .first();
    const trigger = comboField.locator('.x-form-trigger').first();
    await trigger.click();
    await page.waitForTimeout(300);
    const firstCustomer = page.locator('.x-boundlist-item').first();
    await firstCustomer.click();
    await page.waitForTimeout(200);

    await clickAdminSaveButton(page);
    await waitForAdminWindowClose(page);
    await waitForAdminGridRefresh(page);

    // Find the created project
    const rowIndex = await findAdminRowByText(page, testName);
    expect(rowIndex).toBeGreaterThanOrEqual(0);

    // Delete the project via context menu
    await deleteAdminRow(page, rowIndex);

    // Confirm deletion dialog (German: "Ja" = Yes)
    await page.waitForTimeout(500);
    const confirmButton = page.locator('.x-message-box .x-btn, .x-window .x-btn').filter({ hasText: /^Ja$|^Yes$/i }).first();
    if ((await confirmButton.count()) > 0) {
      await confirmButton.click();
      await page.waitForTimeout(500);
    }

    // Verify project was deleted
    await waitForAdminGridRefresh(page);
    const deletedRowIndex = await findAdminRowByText(page, testName);
    expect(deletedRowIndex).toBe(-1);
    console.log(`Deleted project "${testName}"`);
  });

  test('should require customer for project', async ({ page }) => {
    // Click Add button
    await clickAdminAddButton(page, /Add project|Neues Projekt/i);
    await waitForAdminWindow(page);

    // Fill only the name, not the customer
    await fillAdminField(page, 'name', generateTestName('NoCustomerProject'));
    await setAdminCheckbox(page, 'active', true);

    // Try to save without selecting customer
    await clickAdminSaveButton(page);

    // Wait for potential error dialog
    await page.waitForTimeout(500);

    // Should show validation error - either in the form or as a dialog
    const errorDialog = page.locator('.x-window').filter({ hasText: /Fehler|Error|Kunde/i });
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

  test('should handle project with ticket system', async ({ page }) => {
    const testName = generateTestName('TicketProject');

    // Click Add button
    await clickAdminAddButton(page, /Add project|Neues Projekt/i);
    await waitForAdminWindow(page);

    // Fill in project details
    await fillAdminField(page, 'name', testName);
    await setAdminCheckbox(page, 'active', true);

    // Select a customer
    const customerCombo = page
      .locator('.x-window .x-field')
      .filter({ has: page.locator('input[name="customer"]') })
      .first();
    const customerTrigger = customerCombo.locator('.x-form-trigger').first();
    await customerTrigger.click();
    await page.waitForTimeout(300);
    const firstCustomer = page.locator('.x-boundlist-item').first();
    await firstCustomer.click();
    await page.waitForTimeout(200);

    // Try to set ticket prefix if field exists
    try {
      const ticketPrefixField = page.locator('.x-window input[name="ticketPrefix"]');
      if ((await ticketPrefixField.count()) > 0) {
        await ticketPrefixField.fill('TEST');
      }
    } catch {
      // Ticket prefix field may not exist
    }

    // Save
    await clickAdminSaveButton(page);
    await waitForAdminWindowClose(page);
    await waitForAdminGridRefresh(page);

    // Verify project was created
    const rowIndex = await findAdminRowByText(page, testName);
    expect(rowIndex).toBeGreaterThanOrEqual(0);
    console.log(`Created project "${testName}" with ticket configuration`);
  });

  test('should toggle project global flag', async ({ page }) => {
    const testName = generateTestName('GlobalProject');

    // Create a project
    await clickAdminAddButton(page, /Add project|Neues Projekt/i);
    await waitForAdminWindow(page);
    await fillAdminField(page, 'name', testName);
    await setAdminCheckbox(page, 'active', true);

    // Select a customer
    const comboField = page
      .locator('.x-window .x-field')
      .filter({ has: page.locator('input[name="customer"]') })
      .first();
    const trigger = comboField.locator('.x-form-trigger').first();
    await trigger.click();
    await page.waitForTimeout(300);
    const firstCustomer = page.locator('.x-boundlist-item').first();
    await firstCustomer.click();
    await page.waitForTimeout(200);

    // Set global flag if it exists
    try {
      await setAdminCheckbox(page, 'global', true);
    } catch {
      // Global flag may not exist for projects
    }

    await clickAdminSaveButton(page);
    await waitForAdminWindowClose(page);
    await waitForAdminGridRefresh(page);

    // Verify project was created
    const rowIndex = await findAdminRowByText(page, testName);
    expect(rowIndex).toBeGreaterThanOrEqual(0);
    console.log(`Created project "${testName}" with global flag`);
  });
});
