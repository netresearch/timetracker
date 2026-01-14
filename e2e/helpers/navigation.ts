import { Page, Locator } from '@playwright/test';

/**
 * Tab names in the application
 */
export const TABS = {
  tracking: /1:.*Time Tracking|Zeiterfassung/i,
  interpretation: /2:.*Interpretation|Auswertung/i,
  extras: /3:.*Extras/i,
  settings: /4:.*Settings|Einstellungen/i,
  administration: /5:.*Administration/i,
  controlling: /6:.*Controlling/i,
  help: /7:.*Help|Hilfe/i,
  charts: /Charts|Diagramme/i, // Legacy, may not exist in numbered tabs
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
 * Navigate to Time Tracking tab
 */
export async function goToTrackingTab(page: Page): Promise<void> {
  await goToTab(page, TABS.tracking);
  await page.waitForSelector('.x-grid', { timeout: 10000 });
}

/**
 * Navigate to Interpretation tab
 */
export async function goToInterpretationTab(page: Page): Promise<void> {
  await goToTab(page, TABS.interpretation);
}

/**
 * Navigate to Charts tab
 */
export async function goToChartsTab(page: Page): Promise<void> {
  await goToTab(page, TABS.charts);
}

/**
 * Navigate to Controlling tab
 */
export async function goToControllingTab(page: Page): Promise<void> {
  await goToTab(page, TABS.controlling);
}

/**
 * Navigate to Settings tab
 */
export async function goToSettingsTab(page: Page): Promise<void> {
  await goToTab(page, TABS.settings);
  // Wait for settings form
  await page.waitForSelector('input[name="locale"]', { timeout: 5000 }).catch(() => {});
}

/**
 * Get the currently active tab
 */
export async function getActiveTab(page: Page): Promise<string | null> {
  const activeTab = page.locator('.x-tab.x-tab-active, .x-tab-active');
  return await activeTab.textContent();
}

/**
 * Check if a specific tab is visible (user has access)
 */
export async function isTabVisible(page: Page, tabName: RegExp | string): Promise<boolean> {
  const tab = page.locator('.x-tab, button').filter({ hasText: tabName });
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
