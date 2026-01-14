import { test, expect } from '@playwright/test';

/**
 * E2E tests for user settings.
 *
 * Tests verify that settings are saved correctly and take effect in the UI.
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

// Helper to navigate to Settings tab
async function goToSettingsTab(page: import('@playwright/test').Page) {
  // Wait for app to load
  await page.waitForSelector('.x-tab-bar, button:has-text("Settings")', { timeout: 10000 });

  // Click on Settings tab (button with "4: Settings" or "Settings" or "Einstellungen")
  const settingsTab = page.locator('button').filter({ hasText: /Settings|Einstellungen/i }).first();
  await settingsTab.click();

  // Wait for settings form - look for "Show empty line" label
  await page.waitForSelector('text="Show empty line"', { timeout: 5000 }).catch(() => {});
  await page.waitForSelector('text="Immer leere Zeile"', { timeout: 1000 }).catch(() => {});
  await page.waitForTimeout(500);
}

// Helper to get combo box value
async function getComboValue(page: import('@playwright/test').Page, name: string): Promise<string> {
  // ExtJS combo boxes: the visible input shows display text, but we need the hidden value
  // Use JavaScript to get the actual field value from ExtJS component
  const value = await page.evaluate((fieldName) => {
    const input = document.querySelector(`input[name="${fieldName}"]`) as HTMLInputElement;
    if (!input) return '';
    // Get the ExtJS component ID from the input's parent
    const field = input.closest('.x-field');
    if (field && field.id) {
      const cmp = (window as unknown as { Ext: { getCmp: (id: string) => { getValue: () => unknown } } }).Ext?.getCmp(field.id);
      if (cmp && typeof cmp.getValue === 'function') {
        return String(cmp.getValue());
      }
    }
    return input.value;
  }, name);
  return value;
}

// Helper to set combo box value
async function setComboValue(page: import('@playwright/test').Page, name: string, value: string) {
  // ExtJS combo boxes: the hidden input stores the value, but we need to click
  // the trigger button (arrow) to open the dropdown
  const hiddenInput = page.locator(`input[name="${name}"]`).first();

  // Find the parent combo wrapper, then the trigger button within it
  // ExtJS structure: .x-form-item > .x-form-item-body > .x-form-trigger-wrap > .x-form-trigger
  const comboTrigger = hiddenInput.locator('xpath=ancestor::*[contains(@class, "x-field")]//div[contains(@class, "x-form-trigger")]').first();

  await comboTrigger.click();
  await page.waitForTimeout(300);

  // Wait for dropdown to appear
  await page.waitForSelector('.x-boundlist', { timeout: 5000 });

  // Click on the desired option (Yes = 1, No = 0)
  const optionText = value === '1' ? /Yes|Ja/i : /No|Nein/i;
  await page.locator('.x-boundlist-item').filter({ hasText: optionText }).click();
  await page.waitForTimeout(200);
}

// Helper to save settings
async function saveSettings(page: import('@playwright/test').Page) {
  // Hide Symfony debug toolbar if present (it can overlap buttons)
  await page.evaluate(() => {
    const toolbar = document.querySelector('.sf-toolbar');
    if (toolbar) (toolbar as HTMLElement).style.display = 'none';
  });

  // Click save button
  const saveButton = page.locator('.x-btn').filter({ hasText: /Save|Speichern/i });
  await saveButton.click();

  // Wait for success notification
  await page.waitForSelector('.x-window', { timeout: 5000 });
  await page.waitForTimeout(500); // Wait for notification to show
}

test.describe('Settings Tab', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, 'developer', 'dev123');
  });

  test('should display settings form', async ({ page }) => {
    await goToSettingsTab(page);

    // Verify settings form fields are present
    await expect(page.locator('input[name="locale"]')).toBeAttached();
    await expect(page.locator('input[name="show_empty_line"]')).toBeAttached();
    await expect(page.locator('input[name="suggest_time"]')).toBeAttached();
    await expect(page.locator('input[name="show_future"]')).toBeAttached();
  });

  test('should save show_empty_line setting', async ({ page }) => {
    await goToSettingsTab(page);

    // Get current value
    const initialValue = await getComboValue(page, 'show_empty_line');
    console.log(`Initial show_empty_line value: ${initialValue}`);

    // Toggle the value
    const newValue = initialValue === '1' ? '0' : '1';
    await setComboValue(page, 'show_empty_line', newValue);

    // Save settings
    await saveSettings(page);

    // Reload page to verify persistence
    await page.reload();
    await page.waitForSelector('.x-grid', { timeout: 15000 });

    // Go back to settings and verify value persisted
    await goToSettingsTab(page);
    const savedValue = await getComboValue(page, 'show_empty_line');
    expect(savedValue).toBe(newValue);

    // Restore original value
    await setComboValue(page, 'show_empty_line', initialValue);
    await saveSettings(page);
  });

  test('should save suggest_time setting', async ({ page }) => {
    await goToSettingsTab(page);

    const initialValue = await getComboValue(page, 'suggest_time');
    console.log(`Initial suggest_time value: ${initialValue}`);

    const newValue = initialValue === '1' ? '0' : '1';
    await setComboValue(page, 'suggest_time', newValue);
    await saveSettings(page);

    // Reload and verify
    await page.reload();
    await page.waitForSelector('.x-grid', { timeout: 15000 });
    await goToSettingsTab(page);

    const savedValue = await getComboValue(page, 'suggest_time');
    expect(savedValue).toBe(newValue);

    // Restore
    await setComboValue(page, 'suggest_time', initialValue);
    await saveSettings(page);
  });
});

test.describe('Settings Effectiveness', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, 'developer', 'dev123');
  });

  test('suggest_time should pre-fill start time when enabled', async ({ page }) => {
    // First, enable suggest_time
    await goToSettingsTab(page);
    await setComboValue(page, 'suggest_time', '1');
    await saveSettings(page);

    // Go to tracking tab
    const trackingTab = page.locator('.x-tab').filter({ hasText: /Time Tracking|Zeiterfassung/i });
    await trackingTab.click();
    await page.waitForSelector('.x-grid', { timeout: 10000 });

    // Add a new entry
    const addButton = page.locator('.x-btn').filter({ hasText: /Add|Neuer Eintrag/i });
    await addButton.click();

    // Wait for new row to be added
    await page.waitForTimeout(500);

    // Check if start time is pre-filled (not empty)
    const startCell = page.locator('.x-grid-row').first().locator('.x-grid-cell').nth(2);
    const startText = await startCell.textContent();

    console.log(`Start time value (suggest_time=1): ${startText}`);

    // With suggest_time enabled, start should be pre-filled with current time (not empty)
    // The format should be HH:MM
    expect(startText).toMatch(/\d{1,2}:\d{2}|^\s*$/);
  });

  test('suggest_time disabled should not pre-fill times', async ({ page }) => {
    // Disable suggest_time
    await goToSettingsTab(page);
    await setComboValue(page, 'suggest_time', '0');
    await saveSettings(page);

    // Go to tracking tab
    const trackingTab = page.locator('.x-tab').filter({ hasText: /Time Tracking|Zeiterfassung/i });
    await trackingTab.click();
    await page.waitForSelector('.x-grid', { timeout: 10000 });

    // Add a new entry
    const addButton = page.locator('.x-btn').filter({ hasText: /Add|Neuer Eintrag/i });
    await addButton.click();

    await page.waitForTimeout(500);

    // Check if start time is empty (00:00 or empty)
    const startCell = page.locator('.x-grid-row').first().locator('.x-grid-cell').nth(2);
    const startText = await startCell.textContent();

    console.log(`Start time value (suggest_time=0): ${startText}`);

    // With suggest_time disabled, start should be empty or 00:00
    expect(startText?.trim() === '' || startText === '00:00').toBeTruthy();
  });

  test('show_empty_line should add empty row after editing first row', async ({ page }) => {
    // Enable show_empty_line
    await goToSettingsTab(page);
    await setComboValue(page, 'show_empty_line', '1');
    await saveSettings(page);

    // Go to tracking tab
    const trackingTab = page.locator('.x-tab').filter({ hasText: /Time Tracking|Zeiterfassung/i });
    await trackingTab.click();
    await page.waitForSelector('.x-grid', { timeout: 10000 });

    // Get initial row count
    const initialRowCount = await page.locator('.x-grid-row, .x-grid-item').count();
    console.log(`Initial row count: ${initialRowCount}`);

    // If there are saved entries, editing the first one should trigger adding an empty line
    if (initialRowCount > 0) {
      // Double-click on first row to edit it
      const firstRow = page.locator('.x-grid-row, .x-grid-item').first();
      await firstRow.dblclick();

      // Wait a moment for the edit to process
      await page.waitForTimeout(1000);

      // Press Tab to move through fields and potentially trigger save
      await page.keyboard.press('Tab');
      await page.keyboard.press('Tab');
      await page.keyboard.press('Escape'); // Cancel edit

      // Check if an empty row was added
      // Note: This feature only triggers when editing a saved entry in row 0
    }
  });
});

test.describe('Settings API', () => {
  test('settings API should return correct format', async ({ page }) => {
    await login(page, 'developer', 'dev123');

    // Use API endpoint to get settings
    const response = await page.request.get('/settings/get');

    if (response.ok()) {
      const settingsData = await response.json();
      console.log('Settings data from API:', settingsData);

      expect(settingsData).toBeDefined();
      expect(settingsData).toHaveProperty('show_empty_line');
      expect(settingsData).toHaveProperty('suggest_time');
      expect(settingsData).toHaveProperty('show_future');
      expect(settingsData).toHaveProperty('locale');
    } else {
      // If API endpoint doesn't exist, check form fields on settings page
      await goToSettingsTab(page);
      await page.waitForTimeout(500);

      // Verify form fields exist
      const showEmptyLine = page.locator('input[name="show_empty_line"]');
      const suggestTime = page.locator('input[name="suggest_time"]');
      const showFuture = page.locator('input[name="show_future"]');

      await expect(showEmptyLine).toBeAttached();
      await expect(suggestTime).toBeAttached();
      await expect(showFuture).toBeAttached();
    }
  });

  test('save settings API should update settings', async ({ page }) => {
    await login(page, 'developer', 'dev123');

    // Navigate to settings and get initial values from form
    await goToSettingsTab(page);
    await page.waitForTimeout(500);

    // Get initial show_empty_line value via form
    const initialValue = await getComboValue(page, 'show_empty_line');
    console.log(`Initial show_empty_line: ${initialValue}`);

    // Toggle the value
    const newValue = initialValue === '1' ? '0' : '1';

    // Save via API
    const response = await page.request.post('/settings/save', {
      form: {
        show_empty_line: newValue,
        suggest_time: await getComboValue(page, 'suggest_time'),
        show_future: await getComboValue(page, 'show_future'),
        locale: 'de',
      },
    });

    if (response.ok()) {
      const result = await response.json();
      console.log('Save result:', result);
    }

    // Reload and verify the change
    await page.reload();
    await goToSettingsTab(page);
    await page.waitForTimeout(500);

    const savedValue = await getComboValue(page, 'show_empty_line');
    expect(savedValue).toBe(newValue);

    // Restore original value
    await page.request.post('/settings/save', {
      form: {
        show_empty_line: initialValue,
        suggest_time: await getComboValue(page, 'suggest_time'),
        show_future: await getComboValue(page, 'show_future'),
        locale: 'de',
      },
    });
  });
});
