import { test, expect } from '@playwright/test';
import { installFrozenClock, E2E_FROZEN_DATE } from './helpers/clock';

/**
 * E2E tests for user settings.
 *
 * Settings lives in the SolidJS UI
 * (frontend/src/pages/Settings.tsx), served as a full page at
 * `/ui/settings/:section` (account | appearance | security | tokens | sync;
 * unknown/absent falls back to account). These tests drive that page and
 * verify that settings are saved correctly and take effect in the worklog
 * grid. Persistence goes through PATCH /api/v2/settings (partial update:
 * absent fields stay unchanged).
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

// Navigate to a settings section and wait for the section nav.
async function goToSettingsPage(page: import('@playwright/test').Page, section = 'account') {
  await page.goto(`/ui/settings/${section}`);
  await page.waitForSelector('.settings-nav', { timeout: 15000 });
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

// Submit the account form, wait for the success status, and return the settings
// echoed by the PATCH /api/v2/settings request the form submission fired.
// Reading the response that the UI itself fired verifies persistence without
// re-saving (which would be circular).
// NOTE: only safe when the locale is left unchanged — a locale change triggers
// window.location.reload() instead of showing the success status.
async function saveSettingsViaForm(
  page: import('@playwright/test').Page,
): Promise<Record<string, unknown>> {
  const [response] = await Promise.all([
    page.waitForResponse((r) => r.url().includes('/api/v2/settings') && r.request().method() === 'PATCH'),
    page.locator('form.stack-form button.primary-button').click(),
  ]);
  await page.waitForSelector('.form-status.is-ok', { timeout: 10000 });

  return (await response.json()) as Record<string, unknown>;
}

type BoolSettings = { show_empty_line: boolean; suggest_time: boolean; show_future: boolean };

// Persist a known boolean settings state via the API and return the echoed
// settings object (PATCH partial semantics — booleans stay booleans, absent
// fields stay unchanged). The JSON response echoes the persisted settings —
// the authoritative source of truth.
async function applySettingsApi(
  page: import('@playwright/test').Page,
  settings: BoolSettings,
): Promise<Record<string, unknown>> {
  const response = await page.request.patch('/api/v2/settings', { data: settings });
  expect(response.ok()).toBeTruthy();

  return (await response.json()) as Record<string, unknown>;
}

// All tests in this file share user `i.myself` and toggle the same
// settings, so they must not run in parallel — neither across describes
// within the file nor across worker shards. Configuring at file level
// (outside any describe) covers the describes below.
test.describe.configure({ mode: 'serial' });

test.describe('Settings Tab', () => {
  test.beforeEach(async ({ page }) => {
    // Use 'i.myself' who has a stable database record
    await loginWithFrozenClock(page, 'i.myself', 'myself123');
  });

  test('should display settings form', async ({ page }) => {
    await goToSettingsPage(page);

    // Verify settings form fields are present (account section)
    await expect(page.locator('select[name="locale"]')).toBeAttached();
    await expect(page.locator('input[type="checkbox"][name="show_empty_line"]')).toBeAttached();
    await expect(page.locator('input[type="checkbox"][name="suggest_time"]')).toBeAttached();
    await expect(page.locator('input[type="checkbox"][name="show_future"]')).toBeAttached();
  });

  // Persistence is verified through the PATCH /api/v2/settings response the
  // form submission triggers (its echoed settings object). Driving the form
  // proves the UI save path works; the echoed settings prove the new value
  // persisted.
  test('should save show_empty_line setting', async ({ page }) => {
    // Establish a known baseline (everything off) so the toggle is deterministic.
    await applySettingsApi(page, { show_empty_line: false, suggest_time: false, show_future: false });

    await goToSettingsPage(page);

    // Toggle show_empty_line on via the UI form; leave the others off to match
    // the baseline (locale stays unchanged → success status, no reload).
    await setCheckboxValue(page, 'show_empty_line', true);
    await setCheckboxValue(page, 'suggest_time', false);
    await setCheckboxValue(page, 'show_future', false);
    const persisted = await saveSettingsViaForm(page);

    console.log(`Saved show_empty_line value: ${persisted.show_empty_line}`);
    expect(persisted.show_empty_line).toBe(true);

    // Restore baseline (off).
    await applySettingsApi(page, { show_empty_line: false, suggest_time: false, show_future: false });
  });

  test('should save suggest_time setting', async ({ page }) => {
    // Establish a known baseline (everything off) so the toggle is deterministic.
    await applySettingsApi(page, { show_empty_line: false, suggest_time: false, show_future: false });

    await goToSettingsPage(page);

    await setCheckboxValue(page, 'suggest_time', true);
    await setCheckboxValue(page, 'show_empty_line', false);
    await setCheckboxValue(page, 'show_future', false);
    const persisted = await saveSettingsViaForm(page);

    console.log(`Saved suggest_time value: ${persisted.suggest_time}`);
    expect(persisted.suggest_time).toBe(true);

    // Restore baseline (off).
    await applySettingsApi(page, { show_empty_line: false, suggest_time: false, show_future: false });
  });
});

test.describe('Settings page conversion', () => {
  test.beforeEach(async ({ page }) => {
    await loginWithFrozenClock(page, 'i.myself', 'myself123');
  });

  test('settings is a full page with a working section nav', async ({ page }) => {
    await goToSettingsPage(page);

    // No dialog anymore — the page itself hosts the content.
    await expect(page.getByRole('dialog')).toHaveCount(0);

    // Nav switches section and URL (German UI, English fallback).
    await page.locator('.settings-nav-link', { hasText: /Sicherheit|Security/ }).click();
    await expect(page).toHaveURL(/\/ui\/settings\/security/);

    // The document title names the active section (a11y: a distinct page title
    // per section plus a section-specific route-change announcement). German UI
    // → "Einstellungen – Sicherheit – …".
    await expect(page).toHaveTitle(/Sicherheit|Security/);
  });

  test('deep link opens the security section directly', async ({ page }) => {
    await goToSettingsPage(page, 'security');
    // Structure assertion (German UI): the 2FA/password card is on screen.
    await expect(page.locator('.security-block').first()).toBeVisible();
  });

  test('unknown section falls back to account', async ({ page }) => {
    await goToSettingsPage(page, 'does-not-exist');
    await expect(page.locator('form.stack-form')).toBeVisible();
  });
});

test.describe('Settings locale reload', () => {
  test.beforeEach(async ({ page }) => {
    await loginWithFrozenClock(page, 'i.myself', 'myself123');
  });

  // Saving a changed locale reloads the SPA (UI strings are locale-bound at load,
  // so AccountSection calls window.location.reload()). The reload must land back
  // on the same settings section, not /ui or the default section (spec §9.1/§12).
  // The e2e UI is German by default; switch to English and back to German so the
  // serially-run file is left as it found it.
  test('changing the locale reloads onto the same settings section', async ({ page }) => {
    await goToSettingsPage(page, 'account');

    // The section hydrates its locale <select> from GET /api/v2/settings on
    // mount; wait for the hydrated German default so the test changes a
    // settled form from a known baseline, not the pre-hydration value.
    const locale = page.locator('select[name="locale"]');
    await expect(locale).toHaveValue('de');

    await locale.selectOption('en');
    await Promise.all([
      page.waitForResponse((r) => r.url().includes('/api/v2/settings') && r.request().method() === 'PATCH'),
      page.waitForEvent('load'),
      page.locator('form.stack-form button.primary-button').click(),
    ]);
    // The reload preserved the section URL.
    await expect(page).toHaveURL(/\/ui\/settings\/account/);

    // Restore German (en → de reloads too), leaving the shared user on de.
    const localeAfterReload = page.locator('select[name="locale"]');
    await expect(localeAfterReload).toHaveValue('en');
    await localeAfterReload.selectOption('de');
    await Promise.all([
      page.waitForResponse((r) => r.url().includes('/api/v2/settings') && r.request().method() === 'PATCH'),
      page.waitForEvent('load'),
      page.locator('form.stack-form button.primary-button').click(),
    ]);
    await expect(page).toHaveURL(/\/ui\/settings\/account/);
  });
});

test.describe('Settings Effectiveness', () => {
  test.beforeEach(async ({ page }) => {
    // Use 'i.myself' who has a stable database record
    await loginWithFrozenClock(page, 'i.myself', 'myself123');
  });

  test('suggest_time should pre-fill start time when enabled', async ({ page }) => {
    // Enable suggest_time via the API, then exercise the worklog grid
    await applySettingsApi(page, { show_empty_line: false, suggest_time: true, show_future: false });

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
    await applySettingsApi(page, { show_empty_line: false, suggest_time: false, show_future: false });

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

    // GET /api/v2/settings returns the full settings object (booleans).
    const response = await page.request.get('/api/v2/settings');
    expect(response.ok()).toBeTruthy();

    const settingsData = await response.json();
    console.log('Settings data from API:', settingsData);

    expect(settingsData).toBeDefined();
    expect(settingsData).toHaveProperty('show_empty_line');
    expect(settingsData).toHaveProperty('suggest_time');
    expect(settingsData).toHaveProperty('show_future');
    expect(settingsData).toHaveProperty('locale');
  });

  test('save settings API should update settings', async ({ page }) => {
    // Use 'i.myself' who has a stable database record
    await loginWithFrozenClock(page, 'i.myself', 'myself123');

    // Establish a known baseline (everything off) and capture the echo as the
    // authoritative source of truth.
    const baseline = await applySettingsApi(page, {
      show_empty_line: false,
      suggest_time: false,
      show_future: false,
    });
    expect(baseline.show_empty_line).toBe(false);

    // Toggle show_empty_line on via the API (PATCH partial update — the other
    // fields stay unchanged) and assert the echoed settings reflect the change.
    const updated = await applySettingsApi(page, {
      show_empty_line: true,
      suggest_time: false,
      show_future: false,
    });
    console.log('Save result settings:', updated);
    expect(updated.show_empty_line).toBe(true);

    // Restore baseline (off).
    await applySettingsApi(page, { show_empty_line: false, suggest_time: false, show_future: false });
  });
});
