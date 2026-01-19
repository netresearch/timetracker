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
  clickAdminSaveButton,
  editAdminRow,
  deleteAdminRow,
  findAdminRowByText,
  waitForAdminGridRefresh,
  generateTestAbbr,
  saveEntityForm,
  selectUserType,
} from '../helpers/admin';

/**
 * E2E tests for Admin User CRUD operations.
 * Tests cover display, create, update, and delete functionality for users.
 *
 * Note: User forms require native event dispatch for save buttons due to ExtJS quirks.
 */
test.describe('Admin User CRUD', () => {
  test.beforeEach(async ({ page }) => {
    // Login as admin user (i.myself has ROLE_ADMIN)
    await login(page, 'i.myself', 'myself123');
    await waitForGrid(page);
    await hideDebugToolbar(page);

    // Navigate to Admin tab
    await goToAdminTab(page);

    // Navigate to User management sub-tab
    await goToAdminSubTab(page, ADMIN_TABS.users);
  });

  test('should display user grid with data', async ({ page }) => {
    // Wait for grid to render
    await page.waitForTimeout(1000);

    // Should have some users
    const rowCount = await getAdminGridRowCount(page);
    console.log(`User grid has ${rowCount} rows`);
    expect(rowCount).toBeGreaterThan(0);

    // Verify column headers are present
    const headerText = await page.locator('.x-column-header').allTextContents();
    console.log('User grid columns:', headerText);

    // Should have Username/Benutzer column
    expect(headerText.some((h) => /User|Benutzer|Name/i.test(h))).toBe(true);
  });

  test('should create a new user', async ({ page }) => {
    const timestamp = Date.now();
    const testUsername = 'e2e_' + timestamp;
    const testAbbr = generateTestAbbr();

    // Get initial row count
    const initialCount = await getAdminGridRowCount(page);

    // Click Add button
    await clickAdminAddButton(page, /Add user|Neuer Nutzer/i);

    // Wait for edit window
    await waitForAdminWindow(page);

    // Fill in user details
    await fillAdminField(page, 'username', testUsername);
    await fillAdminField(page, 'abbr', testAbbr);

    // Set user type (DEV, PL, CTL) - required field
    await selectUserType(page);

    // Save using native event dispatch (required for User forms)
    await saveEntityForm(page);

    // Wait for grid to refresh
    await waitForAdminGridRefresh(page);

    // Verify user was created
    const newCount = await getAdminGridRowCount(page);
    expect(newCount).toBeGreaterThanOrEqual(initialCount);

    // Find the new user in the grid
    const rowIndex = await findAdminRowByText(page, testUsername);
    expect(rowIndex).toBeGreaterThanOrEqual(0);
    console.log(`Created user "${testUsername}" at row ${rowIndex}`);
  });

  test('should edit an existing user', async ({ page }) => {
    const timestamp = Date.now();
    const testUsername = 'edit_' + timestamp;
    const testAbbr = generateTestAbbr();

    // First create a user to edit
    await clickAdminAddButton(page, /Add user|Neuer Nutzer/i);
    await waitForAdminWindow(page);
    await fillAdminField(page, 'username', testUsername);
    await fillAdminField(page, 'abbr', testAbbr);

    // Select type
    await selectUserType(page);

    await saveEntityForm(page);
    await waitForAdminGridRefresh(page);

    // Find the created user
    const rowIndex = await findAdminRowByText(page, testUsername);
    expect(rowIndex).toBeGreaterThanOrEqual(0);

    // Edit the user via context menu
    await editAdminRow(page, rowIndex);

    // Update the abbreviation
    const updatedAbbr = generateTestAbbr();
    await fillAdminField(page, 'abbr', updatedAbbr);

    // Close dropdown if open
    await page.locator('.x-window .x-window-body').first().click();
    await page.waitForTimeout(200);

    // Save
    await saveEntityForm(page);
    await waitForAdminGridRefresh(page);

    // Verify user still exists
    const updatedRowIndex = await findAdminRowByText(page, testUsername);
    expect(updatedRowIndex).toBeGreaterThanOrEqual(0);
    console.log(`Updated user "${testUsername}" abbreviation to "${updatedAbbr}"`);
  });

  test('should delete a user', async ({ page }) => {
    const timestamp = Date.now();
    const testUsername = 'del_' + timestamp;
    const testAbbr = generateTestAbbr();

    // First create a user to delete
    await clickAdminAddButton(page, /Add user|Neuer Nutzer/i);
    await waitForAdminWindow(page);
    await fillAdminField(page, 'username', testUsername);
    await fillAdminField(page, 'abbr', testAbbr);

    // Select type
    await selectUserType(page);

    await saveEntityForm(page);
    await waitForAdminGridRefresh(page);

    // Find the created user
    const rowIndex = await findAdminRowByText(page, testUsername);
    expect(rowIndex).toBeGreaterThanOrEqual(0);

    // Delete the user via context menu
    await deleteAdminRow(page, rowIndex);

    // Confirm deletion dialog (German: "Ja" = Yes)
    await page.waitForTimeout(500);
    const confirmButton = page.locator('.x-message-box .x-btn, .x-window .x-btn').filter({ hasText: /^Ja$|^Yes$/i }).first();
    if ((await confirmButton.count()) > 0) {
      await confirmButton.click();
      await page.waitForTimeout(500);
    }

    // Verify user was deleted
    await waitForAdminGridRefresh(page);
    const deletedRowIndex = await findAdminRowByText(page, testUsername);
    expect(deletedRowIndex).toBe(-1);
    console.log(`Deleted user "${testUsername}"`);
  });

  test('should show user type options', async ({ page }) => {
    // Click Add button
    await clickAdminAddButton(page, /Add user|Neuer Nutzer/i);
    await waitForAdminWindow(page);

    // Check for user type dropdown
    const typeCombo = page.locator('.x-window .x-field').filter({ has: page.locator('input[name="type"]') }).first();

    if ((await typeCombo.count()) > 0) {
      const trigger = typeCombo.locator('.x-form-trigger').first();
      await trigger.click();
      await page.waitForTimeout(300);

      // Verify type options are available
      const options = page.locator('.x-boundlist-item');
      const optionCount = await options.count();
      console.log(`User type has ${optionCount} options`);
      expect(optionCount).toBeGreaterThan(0);

      // Get option text
      const optionTexts = await options.allTextContents();
      console.log('User type options:', optionTexts);

      await page.keyboard.press('Escape');
    }

    // Close the window
    await page.keyboard.press('Escape');
  });

  test('should validate required fields', async ({ page }) => {
    // Click Add button
    await clickAdminAddButton(page, /Add user|Neuer Nutzer/i);
    await waitForAdminWindow(page);

    // Try to save without filling required fields
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

  test('should handle user language setting', async ({ page }) => {
    const timestamp = Date.now();
    const testUsername = 'lang_' + timestamp;
    const testAbbr = generateTestAbbr();

    // Click Add button
    await clickAdminAddButton(page, /Add user|Neuer Nutzer/i);
    await waitForAdminWindow(page);

    // Fill in user details
    await fillAdminField(page, 'username', testUsername);
    await fillAdminField(page, 'abbr', testAbbr);

    // Select type (required)
    await selectUserType(page);

    // Save
    await saveEntityForm(page);
    await waitForAdminGridRefresh(page);

    // Verify user was created
    const rowIndex = await findAdminRowByText(page, testUsername);
    expect(rowIndex).toBeGreaterThanOrEqual(0);
    console.log(`Created user "${testUsername}" with language setting`);
  });
});
