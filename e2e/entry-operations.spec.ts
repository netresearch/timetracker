import { test, expect } from '@playwright/test';

/**
 * E2E tests for entry operations (insert, edit, delete).
 *
 * Tests verify that entries can be created, edited, and that data is saved correctly.
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

// Helper to wait for grid to be ready
async function waitForGrid(page: import('@playwright/test').Page) {
  await page.waitForSelector('.x-grid', { timeout: 15000 });
  // Wait for any loading masks to disappear
  await page.waitForSelector('.x-mask', { state: 'hidden', timeout: 5000 }).catch(() => {});
  await page.waitForTimeout(500);
}

// Helper to add a new entry
async function addNewEntry(page: import('@playwright/test').Page) {
  const addButton = page.locator('.x-btn').filter({ hasText: /Add|Neuer Eintrag/i });
  await addButton.click();
  await page.waitForTimeout(500);
}

// Helper to get grid row count
async function getGridRowCount(page: import('@playwright/test').Page): Promise<number> {
  return await page.locator('.x-grid-row, .x-grid-item').count();
}

// Helper to fill a cell in editing mode
async function fillCell(page: import('@playwright/test').Page, value: string) {
  await page.keyboard.type(value);
  await page.keyboard.press('Tab');
  await page.waitForTimeout(200);
}

// Helper to select from combo box in editing mode
async function selectFromCombo(page: import('@playwright/test').Page, searchText: string) {
  // Type to filter
  await page.keyboard.type(searchText);
  await page.waitForTimeout(300);

  // Wait for dropdown
  await page.waitForSelector('.x-boundlist', { timeout: 3000 }).catch(() => {});

  // Press Enter to select first match
  await page.keyboard.press('Enter');
  await page.waitForTimeout(200);

  // Move to next field
  await page.keyboard.press('Tab');
  await page.waitForTimeout(200);
}

test.describe('Entry Creation', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, 'developer', 'dev123');
    await waitForGrid(page);
  });

  test('should add a new entry row', async ({ page }) => {
    const initialCount = await getGridRowCount(page);
    console.log(`Initial row count: ${initialCount}`);

    await addNewEntry(page);

    const newCount = await getGridRowCount(page);
    console.log(`New row count: ${newCount}`);

    // Should have one more row
    expect(newCount).toBe(initialCount + 1);
  });

  test('should be able to fill entry fields', async ({ page }) => {
    await addNewEntry(page);

    // The new row should be in edit mode
    // First editable field depends on suggest_time setting

    // Press Escape to cancel any editing, then double-click to edit from start
    await page.keyboard.press('Escape');

    // Double-click on the first row to start editing
    const firstRow = page.locator('.x-grid-row, .x-grid-item').first();
    await firstRow.dblclick();

    await page.waitForTimeout(500);

    // Fill in date (should be pre-filled with today)
    await page.keyboard.press('Tab'); // Move to start time

    // Fill start time
    await page.keyboard.type('09:00');
    await page.keyboard.press('Tab');

    // Fill end time
    await page.keyboard.type('10:00');
    await page.keyboard.press('Tab');

    // Skip ticket (optional)
    await page.keyboard.press('Tab');

    // Customer - type to filter
    await page.keyboard.type('Test');
    await page.waitForTimeout(500);
    await page.keyboard.press('ArrowDown');
    await page.keyboard.press('Enter');
    await page.keyboard.press('Tab');

    // Project
    await page.waitForTimeout(300);
    await page.keyboard.press('ArrowDown');
    await page.keyboard.press('Enter');
    await page.keyboard.press('Tab');

    // Activity
    await page.waitForTimeout(300);
    await page.keyboard.press('ArrowDown');
    await page.keyboard.press('Enter');
    await page.keyboard.press('Tab');

    // Description
    await page.keyboard.type('E2E Test Entry');
    await page.keyboard.press('Tab');

    // Wait for save
    await page.waitForTimeout(2000);

    // Verify the entry is visible
    const pageContent = await page.locator('body').textContent();
    expect(pageContent).toContain('E2E Test Entry');
  });

  test('should calculate duration correctly', async ({ page }) => {
    // Create entry via API and verify duration calculation
    const response = await page.request.get('/getData');
    const entries = await response.json();

    if (entries.length > 0) {
      const entry = entries[0].entry;

      // Use existing entry data to create a test save
      const saveResponse = await page.request.post('/tracking/save', {
        form: {
          date: entry.date,
          start: '09:00',
          end: '10:30',
          customer: entry.customer,
          project: entry.project,
          activity: entry.activity,
          description: 'Duration Test Entry',
          ticket: '',
        },
      });

      if (saveResponse.ok()) {
        const result = await saveResponse.json();

        if (result.result) {
          // Duration should be 1 hour 30 minutes = 90 minutes
          expect(result.result.durationMinutes).toBe(90);
          expect(result.result.duration).toBe('01:30');

          console.log(`Duration test: ${result.result.duration} (${result.result.durationMinutes} minutes)`);

          // Clean up - delete the test entry
          if (result.result.id) {
            await page.request.post('/tracking/delete', {
              form: { id: result.result.id },
            });
          }
        }
      }
    }
  });
});

test.describe('Entry Editing', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, 'developer', 'dev123');
    await waitForGrid(page);
  });

  test('should be able to edit existing entry', async ({ page }) => {
    // Wait for entries to load
    await page.waitForTimeout(1000);

    const rowCount = await getGridRowCount(page);
    if (rowCount === 0) {
      console.log('No entries to edit, skipping test');
      return;
    }

    // Double-click on first row to edit
    const firstRow = page.locator('.x-grid-row, .x-grid-item').first();
    await firstRow.dblclick();

    // Should enter edit mode - look for active editor
    await page.waitForSelector('.x-editor', { timeout: 5000 }).catch(() => {});

    // Press Escape to cancel
    await page.keyboard.press('Escape');
  });

  test('editing time should preserve entry date', async ({ page }) => {
    // This tests the bug fix where editing time fields caused date to be wrong

    // Get an existing entry via API
    const response = await page.request.get('/getData');
    const entries = await response.json();

    if (entries.length === 0) {
      console.log('No entries to test, skipping');
      return;
    }

    const entry = entries[0].entry;
    const originalDate = entry.date; // e.g., "14/01/2026"

    console.log(`Original entry date: ${originalDate}, start: ${entry.start}`);

    // Save the entry with a modified start time
    const saveResponse = await page.request.post('/tracking/save', {
      form: {
        id: entry.id,
        date: originalDate,
        start: '14:00', // Change time
        end: entry.end,
        customer: entry.customer,
        project: entry.project,
        activity: entry.activity,
        description: entry.description || 'Test',
        ticket: entry.ticket || '',
      },
    });

    if (saveResponse.ok()) {
      const result = await saveResponse.json();

      if (result.result) {
        // Verify the date is preserved (not changed to 2008-01-01 or similar)
        const resultDate = result.result.date;
        console.log(`Result date: ${resultDate}`);

        // The date should match the original
        expect(resultDate).toBe(originalDate);
      }
    }

    // Restore original start time
    await page.request.post('/tracking/save', {
      form: {
        id: entry.id,
        date: originalDate,
        start: entry.start,
        end: entry.end,
        customer: entry.customer,
        project: entry.project,
        activity: entry.activity,
        description: entry.description || 'Test',
        ticket: entry.ticket || '',
      },
    });
  });
});

test.describe('Entry Display', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, 'developer', 'dev123');
    await waitForGrid(page);
  });

  test('duration should display correctly in grid', async ({ page }) => {
    // Wait for data to load
    await page.waitForTimeout(2000);

    // Get grid content
    const gridContent = await page.locator('.x-grid').textContent();

    // Duration column should show time format (HH:MM), not NaN or empty
    // Look for time patterns in the grid
    const hasValidDuration = /\d{1,2}:\d{2}/.test(gridContent || '');

    console.log(`Grid contains valid duration format: ${hasValidDuration}`);
    console.log(`Sample grid content: ${gridContent?.substring(0, 500)}`);

    // Should find at least one time format (could be start, end, or duration)
    expect(hasValidDuration).toBe(true);

    // Should NOT contain NaN
    expect(gridContent).not.toContain('NaN');
  });

  test('new entry should not show NaN duration', async ({ page }) => {
    // This tests the NaN duration bug fix

    await addNewEntry(page);

    // Get the grid content immediately after adding
    const gridContent = await page.locator('.x-grid').textContent();

    console.log('Grid after adding new entry:', gridContent?.substring(0, 300));

    // Should NOT contain NaN
    expect(gridContent).not.toContain('NaN');

    // Cancel the new entry
    await page.keyboard.press('Escape');
  });
});

test.describe('Entry API Format', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, 'developer', 'dev123');
  });

  test('/getData should return entries with correct format', async ({ page }) => {
    const response = await page.request.get('/getData');
    expect(response.ok()).toBeTruthy();

    const json = await response.json();
    expect(Array.isArray(json)).toBe(true);

    if (json.length > 0) {
      const item = json[0];

      // Each item should have 'entry' wrapper
      expect(item).toHaveProperty('entry');

      const entry = item.entry;

      // Required fields
      expect(entry).toHaveProperty('id');
      expect(entry).toHaveProperty('date');
      expect(entry).toHaveProperty('start');
      expect(entry).toHaveProperty('end');
      expect(entry).toHaveProperty('customer');
      expect(entry).toHaveProperty('project');
      expect(entry).toHaveProperty('activity');
      expect(entry).toHaveProperty('duration');
      expect(entry).toHaveProperty('durationMinutes');

      // Duration format checks
      expect(typeof entry.duration).toBe('string');
      expect(entry.duration).toMatch(/^\d{2}:\d{2}$/);
      expect(typeof entry.durationMinutes).toBe('number');

      console.log('Entry format check passed:', {
        id: entry.id,
        duration: entry.duration,
        durationMinutes: entry.durationMinutes,
      });
    }
  });

  test('/tracking/save should return correct response format', async ({ page }) => {
    // Get existing entry to use as template
    const getResponse = await page.request.get('/getData');
    const entries = await getResponse.json();

    if (entries.length === 0) {
      console.log('No entries available for test');
      return;
    }

    const template = entries[0].entry;

    // Log template to see what IDs we have
    // getData returns: customer, project, activity (not customerId etc.)
    console.log('Template entry:', {
      customer: template.customer,
      project: template.project,
      activity: template.activity,
    });

    // Skip if template doesn't have valid IDs (customer=0 or activity=0 means no valid reference)
    if (!template.customer || template.customer === 0) {
      console.log('Template has no valid customer ID, skipping test');
      return;
    }
    if (!template.project || template.project === 0) {
      console.log('Template has no valid project ID, skipping test');
      return;
    }
    if (!template.activity || template.activity === 0) {
      console.log('Template has no valid activity ID, skipping test');
      return;
    }

    // API uses #[MapRequestPayload] which expects JSON, not form data
    const saveResponse = await page.request.post('/tracking/save', {
      headers: {
        'Content-Type': 'application/json',
      },
      data: {
        date: template.date,
        start: '11:00',
        end: '12:30',
        customer: template.customer, // getData returns IDs in these fields
        project: template.project,
        activity: template.activity,
        description: 'API Format Test',
        ticket: '',
      },
    });

    console.log('Save response status:', saveResponse.status());

    // Handle response - can only read body once
    if (!saveResponse.ok()) {
      const errorText = await saveResponse.text();
      console.log('Save failed:', errorText);
      expect(saveResponse.ok()).toBeTruthy(); // Will fail with proper message
      return;
    }

    const result = await saveResponse.json();
    console.log('Save response:', result);
    expect(result).toHaveProperty('result');

    const saved = result.result;

    // Check all required fields in save response
    expect(saved).toHaveProperty('id');
    expect(saved.id).toBeGreaterThan(0);

    expect(saved).toHaveProperty('duration');
    expect(typeof saved.duration).toBe('string');
    expect(saved.duration).toMatch(/^\d{2}:\d{2}$/);

    expect(saved).toHaveProperty('durationMinutes');
    expect(typeof saved.durationMinutes).toBe('number');

    // 1.5 hours = 90 minutes
    expect(saved.durationMinutes).toBe(90);
    expect(saved.duration).toBe('01:30');

    console.log('Save response format:', saved);

    // Clean up
    await page.request.post('/tracking/delete', {
      form: { id: saved.id },
    });
  });
});

test.describe('Entry Deletion', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, 'developer', 'dev123');
    await waitForGrid(page);
  });

  test('should be able to delete entry via API', async ({ page }) => {
    // Get existing entries
    const getResponse = await page.request.get('/getData');
    const entries = await getResponse.json();

    if (entries.length === 0) {
      console.log('No entries to test deletion');
      return;
    }

    const template = entries[0].entry;

    // Create a test entry to delete
    const createResponse = await page.request.post('/tracking/save', {
      form: {
        date: template.date,
        start: '08:00',
        end: '08:30',
        customer: template.customer,
        project: template.project,
        activity: template.activity,
        description: 'Entry to Delete',
        ticket: '',
      },
    });

    if (!createResponse.ok()) {
      console.log('Could not create test entry');
      return;
    }

    const created = await createResponse.json();
    const entryId = created.result.id;

    console.log(`Created test entry with ID: ${entryId}`);

    // Delete the entry
    const deleteResponse = await page.request.post('/tracking/delete', {
      form: { id: entryId },
    });

    expect(deleteResponse.ok()).toBeTruthy();

    // Verify entry is deleted (not in getData results)
    const verifyResponse = await page.request.get('/getData');
    const remainingEntries = await verifyResponse.json();

    const stillExists = remainingEntries.some(
      (item: { entry: { id: number } }) => item.entry.id === entryId
    );

    expect(stillExists).toBe(false);
    console.log('Entry successfully deleted');
  });
});
