import { test, expect } from '@playwright/test';

/**
 * E2E tests for the login flow.
 *
 * Test credentials are from docker/ldap/users-only.ldif:
 * - developer / dev123
 * - unittest / test123
 * - i.myself / myself123
 */

// Helper to submit the login form (the native/SolidJS login button, #form-submit).
async function clickLoginButton(page: import('@playwright/test').Page) {
  await page.locator('#form-submit').click();
}

test.describe('Login Page', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/login');
    await page.waitForSelector('input[name="_username"]', { timeout: 10000 });
  });

  test('should display login form', async ({ page }) => {
    // Check page title
    await expect(page).toHaveTitle(/TimeTracker/i);

    // Check form fields are visible
    await expect(page.locator('input[name="_username"]')).toBeVisible();
    await expect(page.locator('input[name="_password"]')).toBeVisible();

    // Check for CSRF token field (hidden)
    await expect(page.locator('input[name="_csrf_token"]')).toBeAttached();

    // Check login button exists
    await expect(page.locator('#form-submit')).toBeVisible();
  });

  test('should show error for invalid credentials', async ({ page }) => {
    // Fill in invalid credentials
    await page.locator('input[name="_username"]').fill('invaliduser');
    await page.locator('input[name="_password"]').fill('wrongpassword');

    // Submit the form
    await clickLoginButton(page);

    // Wait for response and check we're back on login page with error
    await page.waitForURL(/\/login/, { timeout: 10000 });
    await expect(page).toHaveURL(/\/login/);
  });

  test('should login successfully with valid credentials', async ({ page }) => {
    // Fill in valid credentials (from LDAP dev users)
    await page.locator('input[name="_username"]').fill('developer');
    await page.locator('input[name="_password"]').fill('dev123');

    // Submit the form
    await clickLoginButton(page);

    // Should be redirected to main page
    await expect(page).toHaveURL(/\/ui\//, { timeout: 15000 });
  });

  test('should login as unittest user', async ({ page }) => {
    await page.locator('input[name="_username"]').fill('unittest');
    await page.locator('input[name="_password"]').fill('test123');

    await clickLoginButton(page);

    await expect(page).toHaveURL(/\/ui\//, { timeout: 15000 });
  });
});

test.describe('Logout', () => {
  test('should logout successfully', async ({ page }) => {
    // First login
    await page.goto('/login');
    await page.waitForSelector('input[name="_username"]', { timeout: 10000 });
    await page.locator('input[name="_username"]').fill('developer');
    await page.locator('input[name="_password"]').fill('dev123');
    await clickLoginButton(page);
    await expect(page).toHaveURL(/\/ui\//, { timeout: 15000 });

    // Wait for the app to fully load and show logout link
    await page.waitForSelector('.badge-logout', { timeout: 10000 });

    // The logout link carries the CSRF token; click it (a real same-origin
    // navigation with Sec-Fetch-Site) instead of page.goto().
    const logoutHref = await page.locator('.badge-logout').getAttribute('href');
    expect(logoutHref).toContain('_csrf_token');

    await page.locator('.badge-logout').click();
    await page.waitForURL(/\/login/, { timeout: 10000 });

    // Verify we're on the login page
    await expect(page).toHaveURL(/\/login/);
  });
});

test.describe('Protected Routes', () => {
  test('should redirect unauthenticated user to login', async ({ page }) => {
    // Try to access a protected route without logging in
    await page.goto('/');

    // Should be redirected to login
    await expect(page).toHaveURL(/\/login/);
  });

  test('should allow authenticated user to access protected routes', async ({ page }) => {
    // Login first
    await page.goto('/login');
    await page.waitForSelector('input[name="_username"]', { timeout: 10000 });
    await page.locator('input[name="_username"]').fill('developer');
    await page.locator('input[name="_password"]').fill('dev123');
    await clickLoginButton(page);
    await expect(page).toHaveURL(/\/ui\//, { timeout: 15000 });

    // The root now redirects into the SPA worklog for an authenticated user.
    await page.goto('/');
    await expect(page).toHaveURL(/\/ui\//);
  });
});
