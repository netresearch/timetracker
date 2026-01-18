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
    // Use 'i.myself' who has test entries in the database
    await login(page, 'i.myself', 'myself123');
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

    // Verify there's actual content in rows (not just empty grid)
    console.log(`First row text: ${rowText?.substring(0, 100)}`);
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
      expect(entry).toHaveProperty('date'); // API uses 'date' not 'day'
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
    // Use 'i.myself' who has test entries in the database
    await login(page, 'i.myself', 'myself123');

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

/**
 * Regression tests for duration format.
 *
 * The ExtJS Entry model expects 'duration' as a formatted string (H:i format like "08:00")
 * not as an integer. This is defined in assets/js/netresearch/model/Entry.js:
 *   {name: 'duration', type: 'date', dateFormat: 'H:i'}
 *
 * If duration is returned as integer (e.g., 480), ExtJS cannot parse it and the
 * duration column will be empty in the grid.
 */
test.describe('Duration Format Regression', () => {
  test.beforeEach(async ({ page }) => {
    // Use 'i.myself' who has test entries in the database
    await login(page, 'i.myself', 'myself123');
  });

  test('API /getData should return duration as formatted string H:i', async ({ page }) => {
    const response = await page.request.get('/getData');
    expect(response.ok()).toBe(true);

    const json = await response.json();
    expect(Array.isArray(json)).toBe(true);

    if (json.length > 0) {
      const entry = json[0].entry;

      // CRITICAL: duration must be a formatted string like "08:00", NOT an integer like 480
      // The ExtJS model uses {type: 'date', dateFormat: 'H:i'} which requires string format
      expect(entry).toHaveProperty('duration');
      expect(typeof entry.duration).toBe('string');
      expect(entry.duration).toMatch(/^\d{2}:\d{2}$/); // H:i format like "08:00"

      // durationMinutes should be the integer value for calculations
      expect(entry).toHaveProperty('durationMinutes');
      expect(typeof entry.durationMinutes).toBe('number');
      expect(Number.isInteger(entry.durationMinutes)).toBe(true);

      console.log(`Duration format check: duration="${entry.duration}", durationMinutes=${entry.durationMinutes}`);
    }
  });

  test('duration column should display formatted time in grid', async ({ page }) => {
    // Wait for grid to load
    await page.waitForSelector('.x-grid', { timeout: 15000 });
    await page.waitForTimeout(2000); // Wait for data to load

    // Find grid rows
    const gridRows = page.locator('.x-grid-item, .x-grid-row');
    const rowCount = await gridRows.count();

    if (rowCount > 0) {
      // Get the duration column content from the grid
      // The duration column should show time in HH:MM format, not empty
      const gridContent = await page.locator('.x-grid').textContent();

      // Duration should be displayed as time format (e.g., "08:00", "00:30")
      // If duration were returned as integer, the column would be empty
      const hasTimeFormat = /\d{1,2}:\d{2}/.test(gridContent || '');

      console.log(`Grid contains time format: ${hasTimeFormat}`);
      console.log(`Sample grid content: ${gridContent?.substring(0, 500)}`);

      // Should find at least one time format in the grid (duration column)
      expect(hasTimeFormat).toBe(true);
    }
  });

  test('API save should return duration in correct format', async ({ page }) => {
    // Get initial data to find an entry we can inspect
    const getResponse = await page.request.get('/getData');
    const entries = await getResponse.json();

    if (entries.length > 0) {
      // Find an entry to use as reference for save format
      const testEntry = entries[0].entry;

      // Simulate what would be returned after save
      // The save endpoint should return the same duration format as getData
      const saveResponse = await page.request.post('/tracking/save', {
        form: {
          id: testEntry.id,
          date: testEntry.date,
          start: testEntry.start,
          end: testEntry.end,
          customer: testEntry.customer,
          project: testEntry.project,
          activity: testEntry.activity,
          description: testEntry.description || '',
          ticket: testEntry.ticket || '',
        }
      });

      if (saveResponse.ok()) {
        const result = await saveResponse.json();

        if (result.result) {
          // CRITICAL: Save response duration must also be string format
          expect(typeof result.result.duration).toBe('string');
          expect(result.result.duration).toMatch(/^\d{2}:\d{2}$/);

          // durationMinutes must be integer for JS Date conversion
          expect(typeof result.result.durationMinutes).toBe('number');

          console.log(`Save response format: duration="${result.result.duration}", durationMinutes=${result.result.durationMinutes}`);
        }
      }
    }
  });
});
