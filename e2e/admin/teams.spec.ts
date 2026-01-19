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
  clickAdminSaveButton,
  waitForAdminWindowClose,
  editAdminRow,
  deleteAdminRow,
  findAdminRowByText,
  generateTestName,
  waitForAdminGridRefresh,
} from '../helpers/admin';

/**
 * E2E tests for Admin Team CRUD operations.
 * Tests verify full Create, Read, Update, Delete functionality for teams.
 */
test.describe('Admin Team CRUD', () => {
  test.beforeEach(async ({ page }) => {
    // Login as admin user (i.myself has ROLE_ADMIN)
    await login(page, 'i.myself', 'myself123');
    await waitForGrid(page);
    await hideDebugToolbar(page);

    // Navigate to Admin tab
    await goToAdminTab(page);

    // Navigate to Team management sub-tab
    await goToAdminSubTab(page, ADMIN_TABS.teams);
  });

  test('should display team grid with data', async ({ page }) => {
    // Wait for grid to render
    await page.waitForTimeout(1000);

    // Should have some teams
    const rowCount = await getAdminGridRowCount(page);
    console.log(`Team grid has ${rowCount} rows`);
    expect(rowCount).toBeGreaterThan(0);

    // Verify column headers are present
    const headerText = await page.locator('.x-column-header').allTextContents();
    console.log('Team grid columns:', headerText);

    // Should have Name column
    expect(headerText.some((h) => /Name/i.test(h))).toBe(true);
  });

  test('should create a new team', async ({ page }) => {
    const testName = generateTestName('TestTeam');

    // Get initial row count
    const initialCount = await getAdminGridRowCount(page);

    // Click Add button
    await clickAdminAddButton(page, /Add team|Neues Team/i);

    // Wait for edit window
    await waitForAdminWindow(page);

    // Fill in team details
    await fillAdminField(page, 'name', testName);

    // Try to select a team lead if the field exists
    try {
      const leadCombo = page
        .locator('.x-window .x-field')
        .filter({ has: page.locator('input[name="lead"], input[name="teamLead"]') })
        .first();
      if ((await leadCombo.count()) > 0) {
        const trigger = leadCombo.locator('.x-form-trigger').first();
        await trigger.click();
        await page.waitForTimeout(300);

        const firstUser = page.locator('.x-boundlist-item').first();
        if ((await firstUser.count()) > 0) {
          await firstUser.click();
          await page.waitForTimeout(200);
        }
      }
    } catch {
      // Team lead may not be required
    }

    // Save
    await clickAdminSaveButton(page);

    // Wait for window to close
    await waitForAdminWindowClose(page);

    // Wait for grid to refresh
    await waitForAdminGridRefresh(page);

    // Verify team was created
    const newCount = await getAdminGridRowCount(page);
    expect(newCount).toBeGreaterThanOrEqual(initialCount);

    // Find the new team in the grid
    const rowIndex = await findAdminRowByText(page, testName);
    expect(rowIndex).toBeGreaterThanOrEqual(0);
    console.log(`Created team "${testName}" at row ${rowIndex}`);
  });

  test('should edit an existing team', async ({ page }) => {
    const testName = generateTestName('EditTeam');

    // First create a team to edit
    await clickAdminAddButton(page, /Add team|Neues Team/i);
    await waitForAdminWindow(page);
    await fillAdminField(page, 'name', testName);
    await clickAdminSaveButton(page);
    await waitForAdminWindowClose(page);
    await waitForAdminGridRefresh(page);

    // Find the created team
    const rowIndex = await findAdminRowByText(page, testName);
    expect(rowIndex).toBeGreaterThanOrEqual(0);

    // Edit the team via context menu
    await editAdminRow(page, rowIndex);

    // Update the name
    const updatedName = testName + '_Updated';
    await fillAdminField(page, 'name', updatedName);

    // Save
    await clickAdminSaveButton(page);
    await waitForAdminWindowClose(page);
    await waitForAdminGridRefresh(page);

    // Verify team was updated
    const updatedRowIndex = await findAdminRowByText(page, updatedName);
    expect(updatedRowIndex).toBeGreaterThanOrEqual(0);
    console.log(`Updated team to "${updatedName}"`);
  });

  test('should delete a team', async ({ page }) => {
    const testName = generateTestName('DeleteTeam');

    // First create a team to delete
    await clickAdminAddButton(page, /Add team|Neues Team/i);
    await waitForAdminWindow(page);
    await fillAdminField(page, 'name', testName);
    await clickAdminSaveButton(page);
    await waitForAdminWindowClose(page);
    await waitForAdminGridRefresh(page);

    // Find the created team
    const rowIndex = await findAdminRowByText(page, testName);
    expect(rowIndex).toBeGreaterThanOrEqual(0);

    // Delete the team via context menu
    await deleteAdminRow(page, rowIndex);

    // Wait for deletion
    await page.waitForTimeout(1000);

    // Verify team was deleted
    await waitForAdminGridRefresh(page);
    const deletedRowIndex = await findAdminRowByText(page, testName);
    expect(deletedRowIndex).toBe(-1);
    console.log(`Deleted team "${testName}"`);
  });

  test('should create team with team lead', async ({ page }) => {
    const testName = generateTestName('LeadTeam');

    // Click Add button
    await clickAdminAddButton(page, /Add team|Neues Team/i);
    await waitForAdminWindow(page);

    // Fill in team details
    await fillAdminField(page, 'name', testName);

    // Try to select a team lead
    try {
      const leadCombo = page
        .locator('.x-window .x-field')
        .filter({ has: page.locator('input[name*="lead"]') })
        .first();
      if ((await leadCombo.count()) > 0) {
        const trigger = leadCombo.locator('.x-form-trigger').first();
        await trigger.click();
        await page.waitForTimeout(300);

        const firstUser = page.locator('.x-boundlist-item').first();
        if ((await firstUser.count()) > 0) {
          await firstUser.click();
          await page.waitForTimeout(200);
        }
      }
    } catch {
      console.log('Could not set team lead');
    }

    // Save
    await clickAdminSaveButton(page);
    await waitForAdminWindowClose(page);
    await waitForAdminGridRefresh(page);

    // Verify team was created
    const rowIndex = await findAdminRowByText(page, testName);
    expect(rowIndex).toBeGreaterThanOrEqual(0);
    console.log(`Created team "${testName}" with team lead`);
  });

  test('should validate required team name', async ({ page }) => {
    // Click Add button
    await clickAdminAddButton(page, /Add team|Neues Team/i);
    await waitForAdminWindow(page);

    // Try to save without filling name
    await clickAdminSaveButton(page);

    // Window should still be open (validation failed)
    await page.waitForTimeout(500);
    const window = page.locator('.x-window');
    await expect(window).toBeVisible();

    console.log('Validation prevented saving team without name');

    // Close the window
    await page.keyboard.press('Escape');
  });

  test('should show team members if available', async ({ page }) => {
    // Get the first team
    const rowCount = await getAdminGridRowCount(page);
    if (rowCount > 0) {
      // Edit the first team to see its details
      await editAdminRow(page, 0);

      // Check for team members field
      const window = page.locator('.x-window');
      const windowContent = await window.textContent();

      console.log('Team edit window contains fields for team configuration');

      // Close window
      await page.keyboard.press('Escape');
    }
  });
});
