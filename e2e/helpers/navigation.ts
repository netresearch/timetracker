import { Page } from '@playwright/test';

/**
 * Tab names in the ExtJS shell.
 *
 * The Extras, Settings, Controlling/Abrechnung, Help, Interpretation/Auswertung
 * and Administration tabs were migrated out of the ExtJS shell into the SolidJS
 * UI (served under /ui) and are reached via the shared header nav links
 * (a.main-nav-link[data-nav=...]). The ExtJS tab bar now holds only Time
 * Tracking (1); the matcher uses the label only (also avoiding the
 * super-linear `\d+:.*` form).
 */
export const TABS = {
  tracking: /Time Tracking|Zeiterfassung/i,
  charts: /Charts|Diagramme/i, // Legacy, may not exist in numbered tabs
} as const;

/**
 * Shared-header navigation links for features migrated to the SolidJS UI.
 * Present on both the ExtJS page (/) and the SPA (/ui/...). Clicking one is a
 * full navigation to the corresponding /ui route.
 */
export const NAV_LINKS = {
  month: 'a.main-nav-link[data-nav="month"]',
  auswertung: 'a.main-nav-link[data-nav="auswertung"]',
  extras: 'a.main-nav-link[data-nav="extras"]',
  billing: 'a.main-nav-link[data-nav="billing"]',
  settings: 'a.main-nav-link[data-nav="settings"]',
  help: 'a.main-nav-link[data-nav="help"]',
  admin: 'a.main-nav-link[data-nav="admin"]',
} as const;

/**
 * Navigate to a specific tab
 */
export async function goToTab(page: Page, tabName: RegExp | string): Promise<void> {
  // Wait for tab bar to be available
  await page.waitForSelector('.x-tab-bar, .x-tab', { timeout: 10000 });

  // Find and click the tab - use .x-tab specifically to avoid matching inner button
  const tab = page.locator('.x-tab').filter({ hasText: tabName }).first();
  await tab.click();
  await page.waitForTimeout(500);
}

/**
 * Open the header "More" overflow menu (only meaningful when items have folded
 * into it — the control is hidden otherwise).
 */
export async function openMoreMenu(page: Page): Promise<void> {
  const button = page.locator('#nav-more-btn');
  if ((await button.getAttribute('aria-expanded')) !== 'true') {
    await button.click();
  }
  await page.locator('#nav-more-menu').waitFor({ state: 'visible' });
}

/**
 * Click a header nav link, regardless of whether the priority-overflow has kept
 * it inline or folded it into the "More" menu (which depends on viewport width).
 */
export async function clickHeaderNav(page: Page, selector: string): Promise<void> {
  const link = page.locator(selector);
  if (!(await link.isVisible())) {
    // Folded into "More" — reveal it first (the control is then visible).
    await openMoreMenu(page);
  }
  await link.click();
}

/**
 * Navigate to Time Tracking tab
 */
export async function goToTrackingTab(page: Page): Promise<void> {
  await goToTab(page, TABS.tracking);
  await page.waitForSelector('.x-grid', { timeout: 10000 });
}

/**
 * Navigate to the SolidJS Evaluation (Auswertung) page via the shared header
 * nav link. (Formerly the ExtJS Interpretation/Auswertung tab.)
 */
export async function goToAuswertungPage(page: Page): Promise<void> {
  await page.locator(NAV_LINKS.auswertung).click();
  await page.waitForURL(/\/ui\/auswertung/, { timeout: 10000 });
  await page.waitForSelector('section.auswertung', { timeout: 10000 });
}

/**
 * Navigate to Charts tab
 */
export async function goToChartsTab(page: Page): Promise<void> {
  await goToTab(page, TABS.charts);
}

/**
 * Navigate to the SolidJS Billing page via the shared header nav link.
 * (Formerly the ExtJS Controlling/Abrechnung tab.)
 */
export async function goToBillingPage(page: Page): Promise<void> {
  await clickHeaderNav(page, NAV_LINKS.billing);
  await page.waitForURL(/\/ui\/billing/, { timeout: 10000 });
  await page.waitForSelector('form.stack-form', { timeout: 10000 });
}

/**
 * Navigate to the SolidJS Settings page via the shared header nav link.
 * (Formerly the ExtJS Settings/Einstellungen tab.)
 */
export async function goToSettingsPage(page: Page): Promise<void> {
  await clickHeaderNav(page, NAV_LINKS.settings);
  await page.waitForURL(/\/ui\/settings/, { timeout: 10000 });
  await page.waitForSelector('form.stack-form', { timeout: 10000 });
}

/**
 * Navigate to the SolidJS Administration page via the shared header nav link.
 * (Formerly the ExtJS Administration tab; only visible to ROLE_ADMIN.)
 */
export async function goToAdminPage(page: Page): Promise<void> {
  await clickHeaderNav(page, NAV_LINKS.admin);
  await page.waitForURL(/\/ui\/admin/, { timeout: 10000 });
  await page.waitForSelector('section.admin-page', { timeout: 10000 });
}

/**
 * Get the currently active tab
 */
export async function getActiveTab(page: Page): Promise<string | null> {
  const activeTab = page.locator('.x-tab.x-tab-active, .x-tab-active').first();
  return await activeTab.textContent();
}

/**
 * Check if a specific tab is visible (user has access)
 */
export async function isTabVisible(page: Page, tabName: RegExp | string): Promise<boolean> {
  const tab = page.locator('.x-tab, button').filter({ hasText: tabName }).first();
  return await tab.isVisible();
}

/**
 * Get all visible tab names
 */
export async function getVisibleTabs(page: Page): Promise<string[]> {
  const tabs = page.locator('.x-tab');
  const count = await tabs.count();
  const tabNames: string[] = [];

  for (let i = 0; i < count; i++) {
    const text = await tabs.nth(i).textContent();
    if (text) tabNames.push(text.trim());
  }

  return tabNames;
}

/**
 * Hide Symfony debug toolbar (can interfere with clicks)
 */
export async function hideDebugToolbar(page: Page): Promise<void> {
  await page.evaluate(() => {
    const toolbar = document.querySelector('.sf-toolbar');
    if (toolbar) (toolbar as HTMLElement).style.display = 'none';
  });
}
