import { test, expect } from '@playwright/test';

import { login } from './helpers/auth';
import { goToWorklogPage } from './helpers/navigation';
import { createWorklogEntry } from './helpers/worklog';

/**
 * Issue #408: a lost backend session must NOT bounce the user to a separate login
 * page and discard their work. Instead the page dims in place and an in-place
 * re-login overlay appears, with the page (and its data) kept.
 *
 * The successful re-auth → resume leg needs the real LdapAuthenticator's XHR-JSON
 * branch, which the APP_ENV=test firewall (plain form_login) lacks, so that leg is
 * covered by unit tests. Here we assert detection + no-navigation + the overlay,
 * which are firewall-independent.
 */
test.describe('Session expiry — in-place re-login overlay', () => {
  test('a lost session dims the page in place instead of redirecting to /login', async ({ page, context }) => {
    await login(page);
    await goToWorklogPage(page);
    const stamp = await createWorklogEntry(page);
    await expect(page.getByRole('gridcell', { name: stamp })).toBeVisible();

    // Drop the session (and remember-me) — a data request now has no session.
    await context.clearCookies();

    // The Refresh button forces a refetch → the firewall 302s it to /login → the
    // app raises the overlay in place (it does NOT navigate the whole page away).
    await page.getByRole('button', { name: /^(Refresh|Aktualisieren)$/ }).click();

    await expect(page.getByText(/Session expired|Sitzung abgelaufen/)).toBeVisible();
    // Still on the SPA — not bounced to the separate /login page...
    expect(new URL(page.url()).pathname).not.toBe('/login');
    expect(new URL(page.url()).pathname).toContain('/ui');
    // ...and the work is still mounted on the (dimmed, now-inert) page — the row is
    // in the DOM, proving the SPA wasn't torn down. (It is aria-hidden by the modal,
    // so a role/a11y query would correctly NOT see it — assert DOM presence instead.)
    await expect(page.locator('tr.tracking-row', { hasText: stamp })).toBeAttached();
    // The overlay offers a password re-entry and a full-page fallback.
    await expect(page.locator('.session-card input[name="_password"]')).toBeVisible();
    await expect(page.locator('.session-fallback')).toBeVisible();
  });
});
