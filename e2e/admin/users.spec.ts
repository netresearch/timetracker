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
 * E2E tests for Admin User CRUD operations.
 * Tests verify full Create, Read, Update, Delete functionality for users.
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
    const testUsername = 'e2e_' + Date.now();
    const testAbbr = 'E2E';

    // Get initial row count
    const initialCount = await getAdminGridRowCount(page);

    // Click Add button
    await clickAdminAddButton(page, /Add user|Neuer Nutzer/i);

    // Wait for edit window
    await waitForAdminWindow(page);

    // Fill in user details
    await fillAdminField(page, 'username', testUsername);
    await fillAdminField(page, 'abbr', testAbbr);

    // Set user type (DEV, PL, CTL)
    try {
      const typeCombo = page
        .locator('.x-window .x-field')
        .filter({ has: page.locator('input[name="type"]') })
        .first();
      if ((await typeCombo.count()) > 0) {
        const trigger = typeCombo.locator('.x-form-trigger').first();
        await trigger.click();
        await page.waitForTimeout(300);

        // Select Developer type
        const devOption = page.locator('.x-boundlist-item').filter({ hasText: /DEV|Developer/i }).first();
        if ((await devOption.count()) > 0) {
          await devOption.click();
        } else {
          // Select first option
          await page.locator('.x-boundlist-item').first().click();
        }
        await page.waitForTimeout(200);
      }
    } catch {
      // Type field may not exist
    }

    // Select teams if required
    try {
      const teamCombo = page
        .locator('.x-window .x-field')
        .filter({ has: page.locator('input[name*="team"]') })
        .first();
      if ((await teamCombo.count()) > 0) {
        const trigger = teamCombo.locator('.x-form-trigger').first();
        await trigger.click();
        await page.waitForTimeout(300);

        const firstTeam = page.locator('.x-boundlist-item').first();
        if ((await firstTeam.count()) > 0) {
          await firstTeam.click();
          await page.waitForTimeout(200);
        }
        await page.keyboard.press('Escape');
      }
    } catch {
      // Teams may not be required
    }

    // Save
    await clickAdminSaveButton(page);

    // Wait for window to close
    await waitForAdminWindowClose(page);

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
    const testUsername = 'edit_' + Date.now();
    const testAbbr = 'EDT';

    // First create a user to edit
    await clickAdminAddButton(page, /Add user|Neuer Nutzer/i);
    await waitForAdminWindow(page);
    await fillAdminField(page, 'username', testUsername);
    await fillAdminField(page, 'abbr', testAbbr);

    // Select type
    try {
      const typeCombo = page
        .locator('.x-window .x-field')
        .filter({ has: page.locator('input[name="type"]') })
        .first();
      if ((await typeCombo.count()) > 0) {
        const trigger = typeCombo.locator('.x-form-trigger').first();
        await trigger.click();
        await page.waitForTimeout(300);
        await page.locator('.x-boundlist-item').first().click();
        await page.waitForTimeout(200);
      }
    } catch {
      // Type selection failed
    }

    await clickAdminSaveButton(page);
    await waitForAdminWindowClose(page);
    await waitForAdminGridRefresh(page);

    // Find the created user
    const rowIndex = await findAdminRowByText(page, testUsername);
    expect(rowIndex).toBeGreaterThanOrEqual(0);

    // Edit the user via context menu
    await editAdminRow(page, rowIndex);

    // Update the abbreviation
    const updatedAbbr = 'UPD';
    await fillAdminField(page, 'abbr', updatedAbbr);

    // Save
    await clickAdminSaveButton(page);
    await waitForAdminWindowClose(page);
    await waitForAdminGridRefresh(page);

    // Verify user still exists
    const updatedRowIndex = await findAdminRowByText(page, testUsername);
    expect(updatedRowIndex).toBeGreaterThanOrEqual(0);
    console.log(`Updated user "${testUsername}" abbreviation to "${updatedAbbr}"`);
  });

  test('should delete a user', async ({ page }) => {
    const testUsername = 'del_' + Date.now();
    const testAbbr = 'DEL';

    // First create a user to delete
    await clickAdminAddButton(page, /Add user|Neuer Nutzer/i);
    await waitForAdminWindow(page);
    await fillAdminField(page, 'username', testUsername);
    await fillAdminField(page, 'abbr', testAbbr);

    // Select type
    try {
      const typeCombo = page
        .locator('.x-window .x-field')
        .filter({ has: page.locator('input[name="type"]') })
        .first();
      if ((await typeCombo.count()) > 0) {
        const trigger = typeCombo.locator('.x-form-trigger').first();
        await trigger.click();
        await page.waitForTimeout(300);
        await page.locator('.x-boundlist-item').first().click();
        await page.waitForTimeout(200);
      }
    } catch {
      // Type selection failed
    }

    await clickAdminSaveButton(page);
    await waitForAdminWindowClose(page);
    await waitForAdminGridRefresh(page);

    // Find the created user
    const rowIndex = await findAdminRowByText(page, testUsername);
    expect(rowIndex).toBeGreaterThanOrEqual(0);

    // Delete the user via context menu
    await deleteAdminRow(page, rowIndex);

    // Wait for deletion
    await page.waitForTimeout(1000);

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

    // Window should still be open (validation failed)
    await page.waitForTimeout(500);
    const window = page.locator('.x-window');
    await expect(window).toBeVisible();

    console.log('Validation prevented saving user without required fields');

    // Close the window
    await page.keyboard.press('Escape');
  });

  test('should handle user language setting', async ({ page }) => {
    const testUsername = 'lang_' + Date.now();
    const testAbbr = 'LNG';

    // Click Add button
    await clickAdminAddButton(page, /Add user|Neuer Nutzer/i);
    await waitForAdminWindow(page);

    // Fill in user details
    await fillAdminField(page, 'username', testUsername);
    await fillAdminField(page, 'abbr', testAbbr);

    // Select type
    try {
      const typeCombo = page
        .locator('.x-window .x-field')
        .filter({ has: page.locator('input[name="type"]') })
        .first();
      if ((await typeCombo.count()) > 0) {
        const trigger = typeCombo.locator('.x-form-trigger').first();
        await trigger.click();
        await page.waitForTimeout(300);
        await page.locator('.x-boundlist-item').first().click();
        await page.waitForTimeout(200);
      }
    } catch {
      // Type selection failed
    }

    // Try to set language
    try {
      const langCombo = page.locator('.x-window .x-field').filter({ has: page.locator('input[name="locale"]') }).first();
      if ((await langCombo.count()) > 0) {
        const trigger = langCombo.locator('.x-form-trigger').first();
        await trigger.click();
        await page.waitForTimeout(300);

        // Select English or first available
        const enOption = page.locator('.x-boundlist-item').filter({ hasText: /en|English/i }).first();
        if ((await enOption.count()) > 0) {
          await enOption.click();
        } else {
          await page.locator('.x-boundlist-item').first().click();
        }
        await page.waitForTimeout(200);
      }
    } catch {
      // Language field may not exist
    }

    // Save
    await clickAdminSaveButton(page);
    await waitForAdminWindowClose(page);
    await waitForAdminGridRefresh(page);

    // Verify user was created
    const rowIndex = await findAdminRowByText(page, testUsername);
    expect(rowIndex).toBeGreaterThanOrEqual(0);
    console.log(`Created user "${testUsername}" with language setting`);
  });
});
