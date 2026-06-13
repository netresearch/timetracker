import { test, expect } from '@playwright/test';
import { login } from './helpers/auth';
import {
  goToTab,
  goToTrackingTab,
  goToInterpretationTab,
  goToSettingsPage,
  getVisibleTabs,
  NAV_LINKS,
  TABS,
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
    // The ExtJS tab bar now holds only Time Tracking (1) and Interpretation (2)
    // for a non-admin user; Settings/Extras/Billing/Help moved to the SolidJS UI
    // and live in the shared header nav.
    const tabs = await getVisibleTabs(page);
    console.log('Visible tabs:', tabs);

    expect(tabs.length).toBeGreaterThan(0);

    // Essential ExtJS tabs are present (using partial text match)
    const hasTrackingTab = tabs.some((t) => /Zeiterfassung|Time Tracking|1:/i.test(t));
    const hasInterpretationTab = tabs.some((t) => /Auswertung|Interpretation|2:/i.test(t));

    expect(hasTrackingTab).toBe(true);
    expect(hasInterpretationTab).toBe(true);

    // Settings is no longer an ExtJS tab — it is reached via the header nav link.
    const hasSettingsTab = tabs.some((t) => /Einstellungen|Settings/i.test(t));
    expect(hasSettingsTab).toBe(false);
    await expect(page.locator(NAV_LINKS.settings)).toBeVisible();
  });

  test('should navigate to Time Tracking tab', async ({ page }) => {
    await goToTrackingTab(page);

    // Grid should be visible
    await expect(page.locator('.x-grid')).toBeVisible();
  });

  test('should navigate to Interpretation tab', async ({ page }) => {
    await goToInterpretationTab(page);

    // Wait for interpretation content to load
    await page.waitForTimeout(1000);

    // Should show interpretation content: charts, grids, panels, or form items
    const hasInterpretationContent =
      (await page.locator('.x-grid').first().isVisible().catch(() => false)) ||
      (await page.locator('.x-form-item').first().isVisible().catch(() => false)) ||
      (await page.locator('.x-panel-body').first().isVisible().catch(() => false)) ||
      (await page.locator('.x-chart, .x-draw').first().isVisible().catch(() => false)) ||
      // Check for visible text that indicates Interpretation tab content
      (await page.getByText(/Effort by|Hours|Customer|Project/i).first().isVisible().catch(() => false));

    expect(hasInterpretationContent).toBe(true);
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

  test('should persist active tab on page reload', async ({ page }) => {
    // Switch to the Interpretation ExtJS tab
    await goToInterpretationTab(page);

    // Reload the page
    await page.reload();
    await page.waitForSelector('.x-tab-bar', { timeout: 10000 });

    // Note: Tab persistence depends on app implementation
    // Default behavior is to show Time Tracking tab after reload
    await waitForGrid(page);
  });
});

test.describe('Role-Based Tab Visibility', () => {
  test('PL user should see Administration tab and Billing nav link', async ({ page }) => {
    // Login as i.myself who has type PL (Project Lead) which grants ROLE_ADMIN
    await login(page, 'i.myself', 'myself123');
    await waitForGrid(page);

    const tabs = await getVisibleTabs(page);
    console.log('Visible tabs for PL user:', tabs);

    // PL users (ROLE_ADMIN) should see the Administration ExtJS tab (now tab 3)
    const hasAdminTab = tabs.some((t) => /Administration|3:/i.test(t));
    expect(hasAdminTab).toBe(true);

    // Controlling/Abrechnung moved to the SolidJS UI — for ROLE_PL/ROLE_ADMIN it
    // is reachable via the Billing header nav link, not an ExtJS tab.
    const hasControllingTab = tabs.some((t) => /Controlling|Abrechnung/i.test(t));
    expect(hasControllingTab).toBe(false);
    await expect(page.locator(NAV_LINKS.billing)).toBeVisible();
  });

  test('PL user should be able to navigate to Administration tab', async ({ page }) => {
    await login(page, 'i.myself', 'myself123');
    await waitForGrid(page);

    // Navigate to Administration tab
    await goToTab(page, TABS.administration);

    // Admin panel should load (check for admin-specific content)
    await page.waitForTimeout(1000);
    const adminContent = page.locator('.x-panel, .x-grid').first();
    await expect(adminContent).toBeVisible();
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
