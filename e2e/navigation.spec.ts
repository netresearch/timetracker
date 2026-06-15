import { test, expect } from '@playwright/test';
import { login } from './helpers/auth';
import {
  goToTrackingTab,
  goToAuswertungPage,
  goToSettingsPage,
  goToAdminPage,
  getVisibleTabs,
  NAV_LINKS,
} from './helpers/navigation';
import { waitForGrid } from './helpers/grid';

/**
 * E2E tests for tab navigation and UI structure.
 */

test.describe('Tab Navigation', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
    await waitForGrid(page);
  });

  test('should display main tabs after login', async ({ page }) => {
    // The ExtJS tab bar now holds only Time Tracking (1) for a non-admin user;
    // Interpretation/Auswertung, Settings, Extras, Billing and Help moved to the
    // SolidJS UI and live in the shared header nav.
    const tabs = await getVisibleTabs(page);
    console.log('Visible tabs:', tabs);

    expect(tabs.length).toBeGreaterThan(0);

    // Time Tracking remains in the ExtJS shell (partial text match).
    const hasTrackingTab = tabs.some((t) => /Zeiterfassung|Time Tracking|1:/i.test(t));
    expect(hasTrackingTab).toBe(true);

    // Interpretation/Auswertung is no longer an ExtJS tab — it is reached via
    // the header nav link.
    const hasInterpretationTab = tabs.some((t) => /Auswertung|Interpretation/i.test(t));
    expect(hasInterpretationTab).toBe(false);
    await expect(page.locator(NAV_LINKS.auswertung)).toBeVisible();

    // Settings is no longer an ExtJS tab — it is reached via the header nav link.
    const hasSettingsTab = tabs.some((t) => /Einstellungen|Settings/i.test(t));
    expect(hasSettingsTab).toBe(false);
    // Settings is a header nav link (inline, or folded into "More" at narrow
    // widths) — assert it's present in the header rather than its placement.
    await expect(page.locator(NAV_LINKS.settings)).toBeAttached();
  });

  test('should navigate to Time Tracking tab', async ({ page }) => {
    await goToTrackingTab(page);

    // Grid should be visible
    await expect(page.locator('.x-grid')).toBeVisible();
  });

  test('should navigate to Evaluation via header nav link', async ({ page }) => {
    // Interpretation/Auswertung moved to the SolidJS UI; the header nav link is a
    // full navigation to /ui/auswertung.
    await goToAuswertungPage(page);

    await expect(page).toHaveURL(/\/ui\/auswertung/);

    // SolidJS evaluation page should be visible with its filter bar.
    await expect(page.locator('section.auswertung')).toBeVisible();
    await expect(page.locator('form.filter-bar')).toBeVisible();
  });

  test('should navigate to Settings via header nav link', async ({ page }) => {
    // Settings moved to the SolidJS UI; the header nav link is a full navigation
    // to /ui/settings.
    await goToSettingsPage(page);

    await expect(page).toHaveURL(/\/ui\/settings/);

    // SolidJS settings form should be visible
    await expect(page.locator('select[name="locale"]')).toBeAttached();
    await expect(page.locator('input[type="checkbox"][name="show_empty_line"]')).toBeAttached();
  });

  test('Settings opens as a modal dialog over the page and closes on Escape', async ({ page }) => {
    // Modal-as-route: /ui/settings opens a dialog over the background page (the
    // URL is preserved); Escape closes it and returns to that page.
    await goToSettingsPage(page);
    await expect(page).toHaveURL(/\/ui\/settings/);

    const dialog = page.getByRole('dialog');
    await expect(dialog).toBeVisible();
    await expect(dialog.locator('form.stack-form')).toBeVisible();

    await page.keyboard.press('Escape');
    await expect(page).toHaveURL(/\/ui\/month/, { timeout: 10000 });
    await expect(page.getByRole('dialog')).toHaveCount(0);
  });

  test('should restore the tracking grid on page reload', async ({ page }) => {
    await goToTrackingTab(page);

    // Reload the page
    await page.reload();
    await page.waitForSelector('.x-tab-bar', { timeout: 10000 });

    // Default behavior is to show the Time Tracking tab after reload.
    await waitForGrid(page);
  });
});

test.describe('Role-Based Tab Visibility', () => {
  test('PL user should see Administration + Billing nav links, not the ExtJS tabs', async ({ page }) => {
    // Login as i.myself who has type PL (Project Lead) which grants ROLE_ADMIN
    await login(page, 'i.myself', 'myself123');
    await waitForGrid(page);

    const tabs = await getVisibleTabs(page);
    console.log('Visible tabs for PL user:', tabs);

    // Administration and Controlling/Abrechnung both moved to the SolidJS UI —
    // for ROLE_ADMIN they are reached via the header nav links, not ExtJS tabs.
    const hasAdminTab = tabs.some((t) => /Administration/i.test(t));
    expect(hasAdminTab).toBe(false);
    const hasControllingTab = tabs.some((t) => /Controlling|Abrechnung/i.test(t));
    expect(hasControllingTab).toBe(false);

    // Administration and Billing are header nav links (inline, or folded into
    // "More" at narrow widths) — assert presence, not placement.
    await expect(page.locator(NAV_LINKS.admin)).toBeAttached();
    await expect(page.locator(NAV_LINKS.billing)).toBeAttached();
  });

  test('PL user should be able to navigate to the Administration page', async ({ page }) => {
    await login(page, 'i.myself', 'myself123');
    await waitForGrid(page);

    await goToAdminPage(page);

    await expect(page).toHaveURL(/\/ui\/admin/);
    await expect(page.locator('section.admin-page')).toBeVisible();
    await expect(page.locator('nav.admin-subnav')).toBeVisible();
  });
});

test.describe('Header Elements', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
    await waitForGrid(page);
  });

  test('should display logo in header', async ({ page }) => {
    const logo = page.locator('#logo, img[alt*="TimeTracker"]');
    await expect(logo).toBeVisible();
  });

  test('should display user badge with status', async ({ page }) => {
    const userBadge = page.locator('#user-badge, .user-badge');
    await expect(userBadge).toBeVisible();

    // Should have logout link
    const logoutLink = page.locator('.badge-logout');
    await expect(logoutLink).toBeVisible();
  });

  test('should display work time statistics', async ({ page }) => {
    // Header should show Today, Week, Month statistics
    const headerContent = await page.locator('.app-header').first().textContent();

    // Should contain time format patterns
    const hasTimeFormat = /\d+:\d+/.test(headerContent || '');
    expect(hasTimeFormat).toBe(true);

    // Check for worktime elements
    const todayEl = page.locator('#worktime-day');
    const weekEl = page.locator('#worktime-week');
    const monthEl = page.locator('#worktime-month');

    // At least one should be visible
    const hasTodayVisible = await todayEl.isVisible().catch(() => false);
    const hasWeekVisible = await weekEl.isVisible().catch(() => false);
    const hasMonthVisible = await monthEl.isVisible().catch(() => false);

    expect(hasTodayVisible || hasWeekVisible || hasMonthVisible).toBe(true);
  });
});

test.describe('Grid Column Headers', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
    await waitForGrid(page);
  });

  test('should display entry grid with correct columns', async ({ page }) => {
    // Check for column headers
    const headerRow = page.locator('.x-column-header, .x-grid-header');
    const headerCount = await headerRow.count();

    console.log(`Found ${headerCount} column headers`);
    expect(headerCount).toBeGreaterThan(0);

    // Grid should have expected columns (Date, Start, End, Ticket, Customer, Project, Activity, Description, Duration)
    const gridContent = await page.locator('.x-grid').textContent();

    // Check for some expected column content
    // These might be in German or English
    const expectedPatterns = [
      /Datum|Date/i,
      /Start/i,
      /Ende|End/i,
      /Kunde|Customer/i,
      /Projekt|Project/i,
    ];

    // At least some columns should be present
    const matchCount = expectedPatterns.filter((p) => p.test(gridContent || '')).length;
    expect(matchCount).toBeGreaterThan(2);
  });

  test('should allow column sorting', async ({ page }) => {
    // Click on a column header to sort
    const dateHeader = page.locator('.x-column-header').filter({ hasText: /Datum|Date/i }).first();

    if ((await dateHeader.count()) > 0) {
      await dateHeader.click();
      await page.waitForTimeout(500);

      // Check for sort indicator
      const hasSortIndicator =
        (await page.locator('.x-column-header-sort-ASC').count()) > 0 ||
        (await page.locator('.x-column-header-sort-DESC').count()) > 0;

      console.log(`Sort indicator present: ${hasSortIndicator}`);
    }
  });
});

test.describe('Responsive Behavior', () => {
  test('should display correctly on desktop', async ({ page }) => {
    // Set desktop viewport
    await page.setViewportSize({ width: 1920, height: 1080 });

    await login(page);
    await waitForGrid(page);

    // Grid should be visible and have reasonable width
    const grid = page.locator('.x-grid');
    const box = await grid.boundingBox();

    expect(box?.width).toBeGreaterThan(800);
  });

  test('should remain functional on smaller screens', async ({ page }) => {
    // Set smaller viewport (tablet size)
    await page.setViewportSize({ width: 1024, height: 768 });

    await login(page);
    await waitForGrid(page);

    // Grid should still be visible
    await expect(page.locator('.x-grid')).toBeVisible();

    // Header navigation to the SolidJS settings page should still work
    await goToSettingsPage(page);
    await expect(page.locator('select[name="locale"]')).toBeAttached();
  });
});
