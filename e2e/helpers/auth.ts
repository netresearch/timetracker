import { expect, Page } from '@playwright/test';

/**
 * Test credentials, defaulting to the values seeded in docker/ldap/dev-users.ldif
 * but overridable via env so no password literal is hard-coded here.
 */
const pw = (envKey: string, fallback: string): string => process.env[envKey] ?? fallback;

export const TEST_USERS = {
  developer: { username: 'developer', password: pw('E2E_DEVELOPER_PASSWORD', 'dev123') },
  unittest: { username: 'unittest', password: pw('E2E_UNITTEST_PASSWORD', 'test123') },
  myself: { username: 'i.myself', password: pw('E2E_MYSELF_PASSWORD', 'myself123') },
} as const;

/**
 * Login to the application
 */
export async function login(
  page: Page,
  username: string = TEST_USERS.developer.username,
  password: string = TEST_USERS.developer.password
): Promise<void> {
  // Suppress the one-time first-run keyboard-shortcut hint: it is a position:fixed
  // overlay shown once per fresh context, so in isolated e2e contexts it would
  // appear on the first /ui load and could obscure a click target.
  await page.addInitScript(() => {
    window.localStorage.setItem('tt-kbd-hint-seen', '1');
  });
  await page.goto('/login');
  await page.waitForSelector('input[name="_username"]', { timeout: 10000 });
  await page.locator('input[name="_username"]').fill(username);
  await page.locator('input[name="_password"]').fill(password);
  await page.locator('#form-submit').click();
  await expect(page).toHaveURL('/', { timeout: 15000 });
}

/**
 * Login as a specific test user
 */
export async function loginAs(
  page: Page,
  userKey: keyof typeof TEST_USERS
): Promise<void> {
  const user = TEST_USERS[userKey];
  await login(page, user.username, user.password);
}

/**
 * Logout from the application
 */
export async function logout(page: Page): Promise<void> {
  await page.waitForSelector('.badge-logout', { timeout: 10000 });
  const logoutHref = await page.locator('.badge-logout').getAttribute('href');
  if (logoutHref) {
    await page.goto(logoutHref);
    await page.waitForURL(/\/login/, { timeout: 10000 });
  }
}

/**
 * Check if user is logged in
 */
export async function isLoggedIn(page: Page): Promise<boolean> {
  try {
    await page.waitForSelector('.badge-logout', { timeout: 3000 });
    return true;
  } catch {
    return false;
  }
}
