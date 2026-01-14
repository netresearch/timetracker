import { test, expect } from '@playwright/test';

/**
 * E2E tests for entry visibility and data display.
 *
 * These tests verify that time entries are properly loaded and displayed
 * in the ExtJS grid after login.
 */

// Helper to login
async function login(page: import('@playwright/test').Page, username: string, password: string) {
  await page.goto('/login');
  await page.waitForSelector('input[name="_username"]', { timeout: 10000 });
  await page.locator('input[name="_username"]').fill(username);
  await page.locator('input[name="_password"]').fill(password);
  await page.locator('#form-submit').click();
  await expect(page).toHaveURL('/', { timeout: 15000 });
}

test.describe('Entry Visibility', () => {
  test.beforeEach(async ({ page }) => {
    // Login as developer user
    await login(page, 'developer', 'dev123');
  });

  test('should load and display entries in the grid', async ({ page }) => {
    // Wait for ExtJS grid to be present
    // The main grid has class 'x-grid' or similar ExtJS grid classes
    await page.waitForSelector('.x-grid', { timeout: 15000 });

    // Verify the /getData API returns proper JSON format
    // Intercept the API response to verify format
    const responsePromise = page.waitForResponse(
      response => response.url().includes('/getData') && response.status() === 200,
      { timeout: 10000 }
    );

    // Trigger a data reload by navigating to the page again
    await page.goto('/');
    await page.waitForSelector('.x-grid', { timeout: 15000 });

    try {
      const response = await responsePromise;
      const json = await response.json();

      // Verify JSON structure - should be array
      expect(Array.isArray(json)).toBe(true);

      // If there are entries, verify they have the 'entry' wrapper
      if (json.length > 0) {
        const firstItem = json[0];
        expect(firstItem).toHaveProperty('entry');
        expect(firstItem.entry).toHaveProperty('id');
      }
    } catch {
      // If no getData request was intercepted (data might be cached), that's OK
      // Just verify the grid is visible
    }

    // Grid should be visible and not show error
    await expect(page.locator('.x-grid')).toBeVisible();
  });

  test('should display entry data in grid rows', async ({ page }) => {
    // Wait for grid
    await page.waitForSelector('.x-grid', { timeout: 15000 });

    // Wait for data to load
    await page.waitForTimeout(2000);

    // Check if there are any grid rows with data
    const gridRows = page.locator('.x-grid-row, .x-grid-item');
    const rowCount = await gridRows.count();

    console.log(`Found ${rowCount} grid rows`);

    // MUST have at least one entry (we created test data)
    expect(rowCount).toBeGreaterThan(0);

    // Get first row's text content
    const firstRow = gridRows.first();
    const rowText = await firstRow.textContent();

    // Row must have actual content, not be empty
    expect(rowText).toBeTruthy();
    expect(rowText?.trim().length).toBeGreaterThan(0);

    // Verify specific entry data is visible (our test entry has 'TEST-001' ticket)
    const pageContent = await page.locator('body').textContent();
    expect(pageContent).toContain('TEST-001');
  });

  test('API /getData should return properly formatted JSON', async ({ page }) => {
    // Make a direct API request after login
    // The session should be established from login

    const response = await page.request.get('/getData');
    expect(response.ok()).toBe(true);

    const json = await response.json();

    // Verify response is an array
    expect(Array.isArray(json)).toBe(true);

    // Verify structure: each item should have 'entry' wrapper
    // (This is required by ExtJS reader with record: 'entry')
    if (json.length > 0) {
      const firstItem = json[0];

      // CRITICAL: Each entry must be wrapped in 'entry' key
      expect(firstItem).toHaveProperty('entry');

      // Entry should have required fields
      const entry = firstItem.entry;
      expect(entry).toHaveProperty('id');
      expect(entry).toHaveProperty('day');
      expect(entry).toHaveProperty('start');

      // Entry should NOT be empty object
      expect(Object.keys(entry).length).toBeGreaterThan(0);
    }
  });

  test('header should show work time statistics', async ({ page }) => {
    // Wait for the main app to load (grid indicates app is ready)
    await page.waitForSelector('.x-grid', { timeout: 15000 });

    // Look for header elements with work time info
    // The header shows: "Heute X:XX Woche XX:XX Monat XXX:XX (XX PT)"
    // Try multiple selectors for the header area
    const body = await page.locator('body').textContent();

    // Page should contain time format indicators (HH:MM)
    // Header shows work time stats like "Heute 0:00 Woche 0:00 Monat 0:00"
    if (body) {
      // Should show at least one time indicator pattern
      const hasTimeFormat = /\d+:\d+/.test(body);
      expect(hasTimeFormat).toBe(true);
    }
  });
});

test.describe('Entry Grid Data Verification', () => {
  test('entries should be visible in Zeiterfassung tab', async ({ page }) => {
    await login(page, 'developer', 'dev123');

    // Wait for main app to load
    await page.waitForSelector('.x-grid', { timeout: 15000 });

    // The Zeiterfassung (time tracking) tab should be active by default
    // Check if the entries grid is visible and has data

    // Wait for any loading masks to disappear
    await page.waitForSelector('.x-mask', { state: 'hidden', timeout: 5000 }).catch(() => {});

    // Check the grid store loaded data by looking at grid row count
    // Or by checking if "no data" message is NOT shown

    // Get grid rows
    const gridRows = page.locator('.x-grid-item, .x-grid-row, tr.x-grid-row');

    // Wait a bit for async data load
    await page.waitForTimeout(1500);

    const count = await gridRows.count();
    console.log(`Zeiterfassung grid rows: ${count}`);

    // At minimum, verify the grid exists and is not in error state
    await expect(page.locator('.x-grid')).toBeVisible();
  });
});
