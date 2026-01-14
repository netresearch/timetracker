import { test, expect } from '@playwright/test';

/**
 * E2E tests for the login flow.
 *
 * Test credentials are from docker/ldap/dev-users.ldif:
 * - developer / dev123
 * - unittest / test123
 * - i.myself / myself123
 */

// Helper to click the ExtJS login button
async function clickLoginButton(page: import('@playwright/test').Page) {
  // ExtJS button with id='form-submit' and text 'Login'
  // The button structure is: <a id="form-submit-..."><span class="x-btn-inner">Login</span></a>
  await page.locator('#form-submit').click();
}

test.describe('Login Page', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/login');
    // Wait for ExtJS to initialize
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
    await expect(page).toHaveURL('/', { timeout: 15000 });
  });

  test('should login as unittest user', async ({ page }) => {
    await page.locator('input[name="_username"]').fill('unittest');
    await page.locator('input[name="_password"]').fill('test123');

    await clickLoginButton(page);

    await expect(page).toHaveURL('/', { timeout: 15000 });
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
    await expect(page).toHaveURL('/', { timeout: 15000 });

    // Wait for the app to fully load and show logout link
    await page.waitForSelector('.logout-link', { timeout: 10000 });

    // Get the logout URL and navigate directly
    const logoutHref = await page.locator('.logout-link').getAttribute('href');
    expect(logoutHref).toBeTruthy();

    // Navigate to logout URL and wait for redirect
    await page.goto(logoutHref!);
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
    await expect(page).toHaveURL('/', { timeout: 15000 });

    // Now try to access the main page - should work
    await page.goto('/');
    await expect(page).toHaveURL('/');
  });
});
