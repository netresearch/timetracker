import { Page } from '@playwright/test';

/**
 * Header navigation links. The whole app is the SolidJS SPA under /ui;
 * clicking a link is a full navigation to the corresponding /ui route.
 */
export const NAV_LINKS = {
  worklog: 'a.main-nav-link[data-nav="tracking"]',
  month: 'a.main-nav-link[data-nav="month"]',
  auswertung: 'a.main-nav-link[data-nav="auswertung"]',
  billing: 'a.main-nav-link[data-nav="billing"]',
  // Settings & Help are icon actions beside the theme switch — .header-icon-link
  // (scoped so it doesn't also match the mobile drawer's .drawer-link[data-nav]).
  settings: 'a.header-icon-link[data-nav="settings"]',
  help: 'a.header-icon-link[data-nav="help"]',
  admin: 'a.main-nav-link[data-nav="admin"]',
} as const;

/**
 * Open the header "More" overflow menu (only meaningful when items have folded
 * into it — the control is hidden otherwise).
 */
export async function openMoreMenu(page: Page): Promise<void> {
  // Hover opens the menu (mouse semantics). A real click would move the pointer
  // onto the button first — firing the hover-open — and then toggle it back shut,
  // so hovering is the reliable way to open it.
  await page.locator('#nav-more-btn').hover();
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
 * Navigate to the SolidJS Evaluation (Auswertung) page via the shared header
 * nav link.
 */
export async function goToAuswertungPage(page: Page): Promise<void> {
  await page.locator(NAV_LINKS.auswertung).click();
  await page.waitForURL(/\/ui\/auswertung/, { timeout: 10000 });
  await page.waitForSelector('section.auswertung', { timeout: 10000 });
}

/**
 * Navigate to the SolidJS Worklog grid via the shared header "Worklog" link.
 */
export async function goToWorklogPage(page: Page): Promise<void> {
  await page.locator(NAV_LINKS.worklog).first().click();
  await page.waitForURL(/\/ui\/tracking/, { timeout: 10000 });
  await page.waitForSelector('table.tracking-table', { timeout: 10000 });
}

/**
 * Navigate to the SolidJS Billing page via the shared header nav link.
 */
export async function goToBillingPage(page: Page): Promise<void> {
  await clickHeaderNav(page, NAV_LINKS.billing);
  await page.waitForURL(/\/ui\/billing/, { timeout: 10000 });
  await page.waitForSelector('form.stack-form', { timeout: 10000 });
}

/**
 * Navigate to the SolidJS Settings page via the shared header nav link.
 * Lands on the default (account) section; the section nav is the marker
 * that the full settings page has rendered.
 */
export async function goToSettingsPage(page: Page): Promise<void> {
  await clickHeaderNav(page, NAV_LINKS.settings);
  await page.waitForURL(/\/ui\/settings/, { timeout: 10000 });
  await page.waitForSelector('.settings-nav', { timeout: 10000 });
}

/**
 * Navigate to the SolidJS Administration page via the shared header nav link.
 * (Only visible to ROLE_ADMIN.)
 */
export async function goToAdminPage(page: Page): Promise<void> {
  await clickHeaderNav(page, NAV_LINKS.admin);
  await page.waitForURL(/\/ui\/admin/, { timeout: 10000 });
  await page.waitForSelector('section.admin-page', { timeout: 10000 });
}

/**
 * Hide Symfony debug toolbar (can interfere with clicks).
 */
export async function hideDebugToolbar(page: Page): Promise<void> {
  await page.evaluate(() => {
    const toolbar = document.querySelector('.sf-toolbar');
    if (toolbar) (toolbar as HTMLElement).style.display = 'none';
  });
}
