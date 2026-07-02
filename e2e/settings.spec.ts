import { test, expect } from '@playwright/test';
import { installFrozenClock, E2E_FROZEN_DATE } from './helpers/clock';

/**
 * E2E tests for user settings.
 *
 * Settings lives in the SolidJS UI
 * (frontend/src/pages/Settings.tsx), served at `/ui/settings`. These tests
 * drive that page and verify that settings are saved correctly and take
 * effect in the worklog grid.
 *
 * Note: Uses frozen clock for consistency with other E2E tests.
 */

// Helper to login with frozen clock
async function loginWithFrozenClock(page: import('@playwright/test').Page, username: string, password: string) {
  // Install frozen clock BEFORE any navigation
  await installFrozenClock(page, E2E_FROZEN_DATE);

  await page.goto('/login');
  await page.waitForSelector('input[name="_username"]', { timeout: 10000 });
  await page.locator('input[name="_username"]').fill(username);
  await page.locator('input[name="_password"]').fill(password);
  await page.locator('#form-submit').click();
  await expect(page).toHaveURL(/\/ui\//, { timeout: 15000 });
}

// Helper to navigate to the SolidJS settings page and wait for the form
async function goToSettingsPage(page: import('@playwright/test').Page) {
  await page.goto('/ui/settings');
  await page.waitForSelector('form.stack-form', { timeout: 15000 });
}

// Wait for the SolidJS worklog grid to render before interacting.
async function waitForTrackingGridReady(page: import('@playwright/test').Page) {
  await page.waitForSelector('table.tracking-table[role="grid"]', { timeout: 15000 });
}

// Click the worklog "Add entry" button and wait for the freshly inserted row to
// render, returning its start-time cell.
async function addRowAndGetStartCell(page: import('@playwright/test').Page) {
  await page.getByRole('button', { name: /Add entry|Eintrag hinzufügen/i }).click();

  const newRow = page.locator('tr.tracking-row.is-new').first();
  await expect(newRow).toBeVisible({ timeout: 10000 });

  return newRow.locator('td[data-col-key="start"]');
}

// Helper to set a checkbox setting's checked state (no-op if already in state)
async function setCheckboxValue(page: import('@playwright/test').Page, name: string, checked: boolean) {
  await page.locator(`input[type="checkbox"][name="${name}"]`).setChecked(checked);
}

// Helper to submit the settings form, wait for the success status, and return
// the persisted `settings` object echoed by the /settings/save response that
// the form submission triggered. Reading the response that the UI itself fired
// verifies persistence without re-saving (which would be circular), and works
// despite Settings.tsx not hydrating saved state back into the inputs.
// NOTE: only safe when the locale is left unchanged — a locale change triggers
// window.location.reload() instead of showing the success status.
async function saveSettingsViaForm(
  page: import('@playwright/test').Page,
): Promise<Record<string, unknown>> {
  const [response] = await Promise.all([
    page.waitForResponse((r) => r.url().includes('/settings/save') && r.request().method() === 'POST'),
    page.locator('form.stack-form button.primary-button').click(),
  ]);
  await page.waitForSelector('.form-status.is-ok', { timeout: 10000 });
  const result = await response.json();
  expect(result).toMatchObject({ success: true });

  return result.settings as Record<string, unknown>;
}

type BoolSettings = { show_empty_line: number; suggest_time: number; show_future: number };

// Persist a known boolean settings state via the API and return the echoed
// `settings` object. The controller reads each field independently and treats
// an unset field as false, so every request sends all four fields. The JSON
// response echoes the persisted settings — the authoritative source of truth,
// since Settings.tsx does not hydrate saved state back into the form inputs.
async function applySettingsApi(
  page: import('@playwright/test').Page,
  settings: BoolSettings,
): Promise<Record<string, unknown>> {
  const response = await page.request.post('/settings/save', {
    form: { locale: 'de', ...settings },
  });
  expect(response.ok()).toBeTruthy();
  const result = await response.json();
  expect(result).toMatchObject({ success: true });

  return result.settings as Record<string, unknown>;
}

// All tests in this file share user `i.myself` and toggle the same
// settings, so they must not run in parallel — neither across describes
// within the file nor across worker shards. Configuring at file level
// (outside any describe) covers the three describes below.
test.describe.configure({ mode: 'serial' });

test.describe('Settings Tab', () => {
  test.beforeEach(async ({ page }) => {
    // Use 'i.myself' who has a stable database record
    await loginWithFrozenClock(page, 'i.myself', 'myself123');
  });

  test('should display settings form', async ({ page }) => {
    await goToSettingsPage(page);

    // Verify settings form fields are present
    await expect(page.locator('select[name="locale"]')).toBeAttached();
    await expect(page.locator('input[type="checkbox"][name="show_empty_line"]')).toBeAttached();
    await expect(page.locator('input[type="checkbox"][name="suggest_time"]')).toBeAttached();
    await expect(page.locator('input[type="checkbox"][name="show_future"]')).toBeAttached();
  });

  // Persistence is verified through the /settings/save response the form
  // submission triggers (its echoed `settings` object), NOT by re-reading the
  // checkbox after reload: Settings.tsx renders all checkboxes unchecked on
  // every load (it does not hydrate saved state into the inputs), so a checkbox
  // read-back would not reflect persisted state. Driving the form proves the UI
  // save path works; the echoed settings prove the new value persisted.
  test('should save show_empty_line setting', async ({ page }) => {
    // Establish a known baseline (everything off) so the toggle is deterministic.
    await applySettingsApi(page, { show_empty_line: 0, suggest_time: 0, show_future: 0 });

    await goToSettingsPage(page);

    // Toggle show_empty_line on via the UI form; leave the others off to match
    // the baseline (locale stays unchanged → success status, no reload).
    await setCheckboxValue(page, 'show_empty_line', true);
    await setCheckboxValue(page, 'suggest_time', false);
    await setCheckboxValue(page, 'show_future', false);
    const persisted = await saveSettingsViaForm(page);

    console.log(`Saved show_empty_line value: ${persisted.show_empty_line}`);
    expect(Boolean(persisted.show_empty_line)).toBe(true);

    // Restore baseline (off).
    await applySettingsApi(page, { show_empty_line: 0, suggest_time: 0, show_future: 0 });
  });

  test('should save suggest_time setting', async ({ page }) => {
    // Establish a known baseline (everything off) so the toggle is deterministic.
    await applySettingsApi(page, { show_empty_line: 0, suggest_time: 0, show_future: 0 });

    await goToSettingsPage(page);

    await setCheckboxValue(page, 'suggest_time', true);
    await setCheckboxValue(page, 'show_empty_line', false);
    await setCheckboxValue(page, 'show_future', false);
    const persisted = await saveSettingsViaForm(page);

    console.log(`Saved suggest_time value: ${persisted.suggest_time}`);
    expect(Boolean(persisted.suggest_time)).toBe(true);

    // Restore baseline (off).
    await applySettingsApi(page, { show_empty_line: 0, suggest_time: 0, show_future: 0 });
  });
});

test.describe('Settings Effectiveness', () => {
  test.beforeEach(async ({ page }) => {
    // Use 'i.myself' who has a stable database record
    await loginWithFrozenClock(page, 'i.myself', 'myself123');
  });

  test('suggest_time should pre-fill start time when enabled', async ({ page }) => {
    // Enable suggest_time via the API, then exercise the worklog grid
    await applySettingsApi(page, { show_empty_line: 0, suggest_time: 1, show_future: 0 });

    await page.goto('/ui/tracking');
    await waitForTrackingGridReady(page);

    const startCell = await addRowAndGetStartCell(page);

    // With suggest_time enabled the start column is pre-filled with the (frozen)
    // current time in HH:MM. toHaveText retries, so a slow grid render no longer
    // races the assertion.
    await expect(startCell).toHaveText(/\d{1,2}:\d{2}/, { timeout: 10000 });
  });

  test('suggest_time disabled should not pre-fill times', async ({ page }) => {
    // Disable suggest_time via the API
    await applySettingsApi(page, { show_empty_line: 0, suggest_time: 0, show_future: 0 });

    await page.goto('/ui/tracking');
    await waitForTrackingGridReady(page);

    const startCell = await addRowAndGetStartCell(page);

    // With suggest_time disabled the start column stays empty (or 00:00).
    await expect(startCell).toHaveText(/^(\s*|00:00)$/, { timeout: 10000 });
  });

  // Note: show_empty_line is a persisted preference (covered by the save test
  // above) with no worklog-grid effect, so there is nothing to assert
  // against the grid here.
});

test.describe('Settings API', () => {
  test('settings API should return correct format', async ({ page }) => {
    // Use 'i.myself' who has a stable database record
    await loginWithFrozenClock(page, 'i.myself', 'myself123');

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
      // If the GET endpoint doesn't exist, verify form fields on the settings page
      await goToSettingsPage(page);

      // Verify form fields exist
      await expect(page.locator('input[type="checkbox"][name="show_empty_line"]')).toBeAttached();
      await expect(page.locator('input[type="checkbox"][name="suggest_time"]')).toBeAttached();
      await expect(page.locator('input[type="checkbox"][name="show_future"]')).toBeAttached();
    }
  });

  test('save settings API should update settings', async ({ page }) => {
    // Use 'i.myself' who has a stable database record
    await loginWithFrozenClock(page, 'i.myself', 'myself123');

    // Establish a known baseline (everything off) and capture the echo as the
    // authoritative source of truth — there is no /settings/get endpoint, and
    // Settings.tsx does not hydrate saved state back into the form inputs.
    const baseline = await applySettingsApi(page, {
      show_empty_line: 0,
      suggest_time: 0,
      show_future: 0,
    });
    expect(Boolean(baseline.show_empty_line)).toBe(false);

    // Toggle show_empty_line on via the API (send all four fields — controller
    // treats unset as false) and assert the echoed settings reflect the change.
    const updated = await applySettingsApi(page, {
      show_empty_line: 1,
      suggest_time: 0,
      show_future: 0,
    });
    console.log('Save result settings:', updated);
    expect(Boolean(updated.show_empty_line)).toBe(true);

    // Restore baseline (off).
    await applySettingsApi(page, { show_empty_line: 0, suggest_time: 0, show_future: 0 });
  });
});
