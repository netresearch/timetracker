import { test, expect } from '@playwright/test';
import { login, TEST_USERS } from './helpers/auth';
import { goToWorklogPage } from './helpers/navigation';

/**
 * E2E for the SolidJS work-log (Worklog) grid — the time-tracking UI at
 * /ui/tracking.
 */
test.describe('Worklog grid', () => {
  test('the Worklog header link opens the SolidJS grid', async ({ page }) => {
    await login(page);
    await goToWorklogPage(page);
    // The grid exposes the WAI-ARIA grid role (use:gridNav).
    await expect(page.locator('table.tracking-table[role="grid"]')).toBeVisible();
  });

  test('the row-actions kebab popup stays inside the viewport', async ({ page }) => {
    // The default `developer` user has no entries in the frozen 3-day window; only
    // `i.myself` seeds rows on 2024-01-15, so sign in as that user to get a row (and
    // therefore a kebab) to click.
    await login(page, TEST_USERS.myself.username, TEST_USERS.myself.password);
    // Navigate at the default width (the header nav collapses on narrow viewports),
    // then shrink so the per-row action icons collapse into the kebab menu
    // (.is-thin-2+). This is exactly the case where the popup used to open off the
    // right edge — it was measured before layout (width 0) so the horizontal clamp
    // couldn't work. position:fixed + a next-frame measure keeps it inside.
    await goToWorklogPage(page);
    await expect(page.locator('table.tracking-table[role="grid"]')).toBeVisible();
    // Guard against an empty grid (no rows → no kebab): i.myself has seed rows here.
    await expect(page.locator('table.tracking-table tbody tr').first()).toBeVisible();
    await page.setViewportSize({ width: 420, height: 760 });

    const kebab = page.locator('.action-menu button[aria-haspopup="menu"]').first();
    await kebab.waitFor();
    // The kebab is the last column of a horizontally-scrollable table at this width;
    // bring it in first so the hover lands on it.
    await kebab.scrollIntoViewIfNeeded();
    // Hover, not click: with a mouse pointer the menu opens on pointerenter (the
    // button's click merely toggles, so a click after the hover-open would close it).
    await kebab.hover();
    await expect(kebab).toHaveAttribute('aria-expanded', 'true');

    const pop = page.locator('.action-menu-pop');
    await expect(pop).toBeVisible();

    const box = await pop.boundingBox();
    const viewport = page.viewportSize()!;
    expect(box).not.toBeNull();
    // Fully within the viewport on every edge (±1px for sub-pixel rounding).
    expect(box!.x).toBeGreaterThanOrEqual(-1);
    expect(box!.y).toBeGreaterThanOrEqual(-1);
    expect(box!.x + box!.width).toBeLessThanOrEqual(viewport.width + 1);
    expect(box!.y + box!.height).toBeLessThanOrEqual(viewport.height + 1);
  });
});
