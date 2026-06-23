import { test, expect } from '@playwright/test';
import { login } from './helpers/auth';
import { waitForGrid } from './helpers/grid';
import { clickHeaderNav } from './helpers/navigation';

/**
 * E2E tests for the backend error/contract behaviour of the JSON API and the
 * session firewall.
 *
 * Each test asserts a real, observable contract:
 * - invalid payloads are rejected with a 4xx
 * - unknown routes 404
 * - a successful settings save shows the inline success status (SolidJS UI)
 * - a request with no session is rejected / redirected to login
 * - an authenticated same-origin POST needs no CSRF token (SameSite=Lax)
 */

test.describe('API Error Handling', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
    await waitForGrid(page);
  });

  test('should handle 400 Bad Request errors', async ({ page }) => {
    // Try to save an entry with invalid data
    const response = await page.request.post('/tracking/save', {
      headers: { 'Content-Type': 'application/json' },
      data: {
        // Missing required fields
        date: '',
        start: '',
        end: '',
      },
    });

    // Should return validation error
    expect(response.status()).toBeGreaterThanOrEqual(400);
  });

  test('should handle 404 Not Found errors', async ({ page }) => {
    // Try to access a non-existent entry
    const response = await page.request.get('/nonexistent-endpoint');

    expect(response.status()).toBe(404);
  });

  test('rejects an entry whose end precedes its start', async ({ page }) => {
    // Re-auth as i.myself (type=ADMIN) so the booking clears the customer/project
    // access + active-project checks and the request actually reaches the time
    // validation. Otherwise a 400 from an inactive project (project 2 is inactive)
    // or a missing-access error would make this pass for the WRONG reason.
    // customer 1 / project 1 is the seed's one active, bookable triple; the date
    // sits inside the frozen-clock window.
    // The block's beforeEach already authenticated as `developer`; drop that
    // session so the login form is shown again, then sign in as i.myself (ADMIN).
    await page.context().clearCookies();
    await login(page, 'i.myself', 'myself123');
    const response = await page.request.post('/tracking/save', {
      headers: { 'Content-Type': 'application/json' },
      data: {
        date: '2024-01-15',
        start: '18:00',
        end: '08:00', // End before start
        customer: 1,
        project: 1,
        activity: 1,
        description: 'Invalid entry test',
      },
    });
    // The only remaining rejection path for this valid, active, bookable triple is
    // the start<end guard (422 "Start time must be before end time"), so assert
    // that specific message — otherwise an unrelated 4xx (inactive project, missing
    // access) would let the test pass for the wrong reason.
    const body = await response.text();
    expect(response.status(), body).toBe(422);
    expect(body).toMatch(/before end time/i);
  });
});

test.describe('Success Notifications', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
    await waitForGrid(page);
  });

  test('should show success notification after settings save', async ({ page }) => {
    // Settings moved to the SolidJS UI; reach it via the header nav (inline or
    // folded into "More" depending on width).
    await clickHeaderNav(page, 'a.header-icon-link[data-nav="settings"]');
    await page.waitForURL(/\/ui\/settings/, { timeout: 10000 });
    await page.waitForSelector('form.stack-form', { timeout: 10000 });

    // Click the Save button (leave locale unchanged — a locale change would
    // reload the page instead of showing the inline success status).
    const saveButton = page.locator('form.stack-form button.primary-button');
    await saveButton.click();

    // The SolidJS page shows an inline success status on a successful save.
    await expect(page.locator('.form-status.is-ok')).toBeVisible({ timeout: 10000 });
  });
});

test.describe('Session Handling', () => {
  test('should reject API requests once the session is cleared', async ({ page }) => {
    await login(page);
    await waitForGrid(page);

    // Clear cookies to simulate session expiry
    await page.context().clearCookies();

    // A protected API with no session must not return data — the firewall
    // 302-redirects it to /login (verified). maxRedirects:0 captures that 3xx
    // rather than following it to the 200 login-page HTML.
    const response = await page.request.get('/getData', { maxRedirects: 0 });
    expect(response.status()).toBeGreaterThanOrEqual(300);
    expect(response.status()).toBeLessThan(400);

    // Navigating to the main page redirects to the login form.
    await page.goto('/');
    await page.waitForURL(/\/login/, { timeout: 10000 });
  });

  test('the authenticated JSON API accepts a same-origin POST without a CSRF token (SameSite=Lax is the CSRF protection)', async ({
    page,
  }) => {
    await login(page, 'i.myself', 'myself123');

    // config/packages/framework.yaml sets cookie_samesite: lax, so the session
    // cookie is NOT sent on cross-site POSTs — that is the CSRF defence. The
    // authenticated same-origin JSON API therefore (by design) carries no
    // per-request CSRF token: a same-origin POST with a valid session succeeds.
    const response = await page.request.post('/tracking/save', {
      headers: {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      },
      data: {
        date: '2024-01-15', // ISO format, matches the frozen-clock seed window
        start: '09:00',
        end: '10:00',
        customer: 1, // i.myself is type=ADMIN, so it may book any customer
        project: 1, // "Das Kuchenbacken" — the seed's one active project (under customer 1)
        activity: 1,
        description: 'same-origin POST without CSRF token',
      },
    });

    expect(response.status()).toBe(200);

    // Clean up the entry this test created so the shared db-e2e doesn't accrue it.
    const created = await response.json();
    if (created?.result?.id) {
      await page.request.post('/tracking/delete', { form: { id: created.result.id } });
    }
  });
});
