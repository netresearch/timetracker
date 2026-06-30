import { test, expect } from '@playwright/test';
import { login } from './helpers/auth';
import {
  goToAuswertungPage,
  goToSettingsPage,
  goToAdminPage,
  NAV_LINKS,
} from './helpers/navigation';
import { waitForGrid } from './helpers/grid';

/**
 * E2E for header navigation and UI structure. The ExtJS shell was removed; the
 * app is a SolidJS SPA under /ui, and a successful login lands on /ui/tracking.
 */

test.describe('Header Navigation', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
    await waitForGrid(page);
  });

  test('shows the SPA nav links after login (no ExtJS tabs)', async ({ page }) => {
    // Worklog, Overview, Evaluation are header nav links; there is no ExtJS tab bar.
    await expect(page.locator(NAV_LINKS.worklog).first()).toBeVisible();
    await expect(page.locator(NAV_LINKS.auswertung)).toBeVisible();
    // Settings is a header icon action (inline or folded into "More").
    await expect(page.locator(NAV_LINKS.settings)).toBeAttached();
    // The legacy ExtJS tab bar is gone.
    await expect(page.locator('.x-tab-bar, .x-tab')).toHaveCount(0);
  });

  test('navigates to Evaluation via the header nav link', async ({ page }) => {
    await goToAuswertungPage(page);
    await expect(page).toHaveURL(/\/ui\/auswertung/);
    await expect(page.locator('section.auswertung')).toBeVisible();
    await expect(page.locator('form.filter-bar')).toBeVisible();
  });

  test('navigates to Settings via the header nav link', async ({ page }) => {
    await goToSettingsPage(page);
    await expect(page).toHaveURL(/\/ui\/settings/);
    await expect(page.locator('select[name="locale"]')).toBeAttached();
    await expect(page.locator('input[type="checkbox"][name="show_empty_line"]')).toBeAttached();
  });

  test('Settings opens as a modal dialog over the page and closes on Escape', async ({ page }) => {
    await goToSettingsPage(page);
    await expect(page).toHaveURL(/\/ui\/settings/);

    const dialog = page.getByRole('dialog');
    await expect(dialog).toBeVisible();
    await expect(dialog.locator('form.stack-form')).toBeVisible();

    await page.keyboard.press('Escape');
    // Settings was opened over the worklog (the beforeEach landing), so Escape
    // returns there.
    await expect(page).toHaveURL(/\/ui\/tracking/, { timeout: 10000 });
    await expect(page.getByRole('dialog')).toHaveCount(0);
  });

  test('closing a modal returns to the page it was opened from, not Overview', async ({ page }) => {
    await goToAuswertungPage(page);
    await expect(page).toHaveURL(/\/ui\/auswertung/);

    await goToSettingsPage(page); // opens the Settings modal over Evaluation
    await expect(page).toHaveURL(/\/ui\/settings/);
    await expect(page.getByRole('dialog')).toBeVisible();

    await page.keyboard.press('Escape');
    await expect(page).toHaveURL(/\/ui\/auswertung/, { timeout: 10000 });
    await expect(page.getByRole('dialog')).toHaveCount(0);
    await expect(page.locator('section.auswertung')).toBeVisible();
  });

  test('keeps the worklog grid on reload', async ({ page }) => {
    // Land on the worklog and reload — the SPA re-renders the grid.
    await page.goto('/ui/tracking');
    await waitForGrid(page);
    await page.reload();
    await waitForGrid(page);
    await expect(page).toHaveURL(/\/ui\/tracking/);
  });
});

test.describe('Role-Based Navigation', () => {
  test('PL user sees Administration + Billing nav links', async ({ page }) => {
    // i.myself has type PL (Project Lead) → ROLE_ADMIN.
    await login(page, 'i.myself', 'myself123');
    await waitForGrid(page);

    // Administration and Billing are header nav links (inline or folded into "More").
    await expect(page.locator(NAV_LINKS.admin)).toBeAttached();
    await expect(page.locator(NAV_LINKS.billing)).toBeAttached();
  });

  test('PL user can navigate to the Administration page', async ({ page }) => {
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

  test('shows the logo in the header', async ({ page }) => {
    const logo = page.locator('#logo, img[alt*="TimeTracker"]');
    await expect(logo.first()).toBeVisible();
  });

  test('shows the user badge with a logout link', async ({ page }) => {
    await expect(page.locator('#user-badge, .user-badge').first()).toBeVisible();
    await expect(page.locator('.badge-logout')).toBeVisible();
  });

  test('shows work-time statistics in the header', async ({ page }) => {
    const headerContent = (await page.locator('.app-header').first().textContent()) ?? '';
    expect(/\d+:\d+/.test(headerContent)).toBe(true);

    const anyWorktimeVisible = await Promise.all([
      page.locator('#worktime-day').isVisible().catch(() => false),
      page.locator('#worktime-week').isVisible().catch(() => false),
      page.locator('#worktime-month').isVisible().catch(() => false),
    ]);
    expect(anyWorktimeVisible.some(Boolean)).toBe(true);
  });
});

test.describe('Responsive Behavior', () => {
  test('renders the worklog grid on a desktop viewport', async ({ page }) => {
    await page.setViewportSize({ width: 1920, height: 1080 });
    await login(page);
    await waitForGrid(page);
    await expect(page.locator('table.tracking-table[role="grid"]')).toBeVisible();
  });

  test('remains functional on a smaller viewport', async ({ page }) => {
    await page.setViewportSize({ width: 1024, height: 768 });
    await login(page);
    await waitForGrid(page);
    await expect(page.locator('table.tracking-table[role="grid"]')).toBeVisible();

    // Header navigation to Settings still works at this width.
    await goToSettingsPage(page);
    await expect(page.locator('select[name="locale"]')).toBeAttached();
  });
});
