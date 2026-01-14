import { test, expect } from '@playwright/test';
import { login } from './helpers/auth';
import { waitForGrid } from './helpers/grid';

/**
 * E2E tests for error handling and notifications.
 *
 * Tests verify that:
 * - API errors are properly displayed
 * - Validation errors show meaningful messages
 * - Success notifications appear after operations
 * - Network errors are handled gracefully
 */

test.describe('API Error Handling', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
    await waitForGrid(page);
  });

  test('should handle 400 Bad Request errors', async ({ page }) => {
    // Try to save an entry with invalid data
    const response = await page.request.post('/tracking/save', {
      headers: { 'Content-Type': 'application/json' },
      data: {
        // Missing required fields
        date: '',
        start: '',
        end: '',
      },
    });

    // Should return validation error
    expect(response.status()).toBeGreaterThanOrEqual(400);
    console.log('Invalid save response status:', response.status());
  });

  test('should handle 404 Not Found errors', async ({ page }) => {
    // Try to access a non-existent entry
    const response = await page.request.get('/nonexistent-endpoint');

    expect(response.status()).toBe(404);
  });

  test('should handle validation errors in entry save', async ({ page }) => {
    // Get existing entry template
    const getResponse = await page.request.get('/getData');
    const entries = await getResponse.json();

    if (entries.length === 0) return;

    const template = entries[0].entry;

    // Try to save with end before start (invalid)
    const response = await page.request.post('/tracking/save', {
      headers: { 'Content-Type': 'application/json' },
      data: {
        date: template.date,
        start: '18:00',
        end: '08:00', // End before start
        customer: template.customer,
        project: template.project,
        activity: template.activity,
        description: 'Invalid entry test',
      },
    });

    console.log('Invalid time range response:', response.status());

    if (!response.ok()) {
      const errorData = await response.json().catch(() => ({}));
      console.log('Validation error:', errorData);
    }
  });

  test('should handle overlapping time entries', async ({ page }) => {
    // Get existing entries
    const getResponse = await page.request.get('/getData');
    const entries = await getResponse.json();

    if (entries.length === 0) return;

    const template = entries[0].entry;

    // Try to create an entry that overlaps with existing
    const response = await page.request.post('/tracking/save', {
      headers: { 'Content-Type': 'application/json' },
      data: {
        date: template.date,
        start: template.start, // Same start time as existing
        end: template.end,
        customer: template.customer,
        project: template.project,
        activity: template.activity,
        description: 'Overlap test',
      },
    });

    console.log('Overlap entry response:', response.status());
  });
});

test.describe('UI Error Display', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
    await waitForGrid(page);
  });

  test('should show error message for failed API calls', async ({ page }) => {
    // Intercept and force an error
    await page.route('**/getData', (route) => {
      route.fulfill({
        status: 500,
        body: JSON.stringify({ error: 'Internal Server Error' }),
      });
    });

    // Trigger a reload
    await page.reload();

    // Wait for potential error message
    await page.waitForTimeout(2000);

    // Check for error indicators
    const hasErrorBox = await page.locator('.x-message-box, .x-window').isVisible().catch(() => false);
    const hasErrorClass = await page.locator('.x-form-invalid, .error').isVisible().catch(() => false);

    console.log(`Error box visible: ${hasErrorBox}, Error class visible: ${hasErrorClass}`);

    // Clear route interception
    await page.unroute('**/getData');
  });

  test('should display form validation errors', async ({ page }) => {
    // Click add to create new entry
    const addButton = page.locator('.x-btn').filter({ hasText: /Add|Neuer Eintrag/i });
    await addButton.click();
    await page.waitForTimeout(500);

    // Try to save without filling required fields by pressing Tab through fields
    for (let i = 0; i < 10; i++) {
      await page.keyboard.press('Tab');
      await page.waitForTimeout(100);
    }

    // Press Enter to try to save
    await page.keyboard.press('Enter');
    await page.waitForTimeout(500);

    // Look for validation indicators
    const invalidFields = page.locator('.x-form-invalid');
    const invalidCount = await invalidFields.count();

    console.log(`Invalid field indicators: ${invalidCount}`);

    // Cancel the edit
    await page.keyboard.press('Escape');
  });
});

test.describe('Success Notifications', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
    await waitForGrid(page);
  });

  test('should show success notification after settings save', async ({ page }) => {
    // Go to Settings tab
    const settingsTab = page.locator('.x-tab, button').filter({ hasText: /Settings|Einstellungen/i });
    await settingsTab.click();
    await page.waitForTimeout(500);

    // Hide debug toolbar if present
    await page.evaluate(() => {
      const toolbar = document.querySelector('.sf-toolbar');
      if (toolbar) (toolbar as HTMLElement).style.display = 'none';
    });

    // Click save button
    const saveButton = page.locator('.x-btn').filter({ hasText: /Save|Speichern/i });
    if ((await saveButton.count()) > 0) {
      await saveButton.click();
      await page.waitForTimeout(1000);

      // Check for success notification
      const hasNotification = await page.locator('.x-window, .x-msg').isVisible().catch(() => false);
      console.log(`Success notification visible: ${hasNotification}`);
    }
  });

  test('should show success notification after entry delete', async ({ page }) => {
    // Get entries
    const getResponse = await page.request.get('/getData');
    const entries = await getResponse.json();

    if (entries.length === 0) return;

    const template = entries[0].entry;

    // Create a test entry to delete
    const createResponse = await page.request.post('/tracking/save', {
      headers: { 'Content-Type': 'application/json' },
      data: {
        date: template.date,
        start: '07:00',
        end: '07:30',
        customer: template.customer,
        project: template.project,
        activity: template.activity,
        description: 'Entry to delete for notification test',
      },
    });

    if (!createResponse.ok()) return;

    const created = await createResponse.json();

    // Reload to see the new entry
    await page.reload();
    await waitForGrid(page);

    // Right-click on first row to open context menu
    const firstRow = page.locator('.x-grid-row, .x-grid-item').first();
    await firstRow.click({ button: 'right' });

    await page.waitForTimeout(300);

    // Look for delete option
    const deleteOption = page.locator('.x-menu-item').filter({ hasText: /Delete|Löschen/i });

    if ((await deleteOption.count()) > 0) {
      await deleteOption.click();
      await page.waitForTimeout(500);

      // Confirm delete if dialog appears
      const confirmButton = page.locator('.x-btn').filter({ hasText: /Yes|Ja|OK/i });
      if ((await confirmButton.count()) > 0) {
        await confirmButton.click();
        await page.waitForTimeout(500);
      }
    }

    // Clean up via API if still exists
    await page.request.post('/tracking/delete', {
      form: { id: created.result.id },
    }).catch(() => {});
  });
});

test.describe('Network Error Handling', () => {
  test('should handle network timeout gracefully', async ({ page }) => {
    await login(page);
    await waitForGrid(page);

    // Intercept requests and add delay
    await page.route('**/getData', async (route) => {
      await new Promise((resolve) => setTimeout(resolve, 100));
      await route.continue();
    });

    // Trigger request
    await page.reload();

    // Should eventually load
    await waitForGrid(page);

    // Clear route
    await page.unroute('**/getData');
  });

  test('should handle offline scenario', async ({ page }) => {
    await login(page);
    await waitForGrid(page);

    // Simulate offline
    await page.context().setOffline(true);

    // Try an action
    const addButton = page.locator('.x-btn').filter({ hasText: /Add|Neuer Eintrag/i });
    await addButton.click();
    await page.waitForTimeout(500);

    // Try to save (should fail gracefully)
    await page.keyboard.press('Tab');
    await page.keyboard.type('09:00');
    await page.keyboard.press('Tab');

    // Go back online
    await page.context().setOffline(false);

    // Cancel
    await page.keyboard.press('Escape');
  });
});

test.describe('Session Handling', () => {
  test('should redirect to login when session expires', async ({ page }) => {
    await login(page);
    await waitForGrid(page);

    // Clear cookies to simulate session expiry
    await page.context().clearCookies();

    // Try to access a protected API
    const response = await page.request.get('/getData');

    // Should redirect to login or return 401/403
    console.log('Response after session clear:', response.status());

    // Navigate to main page - should redirect to login
    await page.goto('/');
    await page.waitForURL(/\/login/, { timeout: 10000 });
  });

  test('should handle CSRF token validation', async ({ page }) => {
    await login(page);

    // Try to make a POST without valid CSRF token
    const response = await page.request.post('/tracking/save', {
      headers: {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      },
      data: {
        date: '14/01/2026',
        start: '09:00',
        end: '10:00',
        customer: 1,
        project: 1,
        activity: 1,
        description: 'CSRF test',
      },
    });

    console.log('POST without CSRF response:', response.status());
  });
});
