import { Page, test } from '@playwright/test';

/**
 * Test credentials, defaulting to the values seeded in docker/ldap/users-only.ldif
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
  // Under concurrent CI-shard load the auth round-trip (10 shards hitting one LDAP +
  // one MariaDB) occasionally fails and re-renders the login form, leaving us on
  // /login — the long-standing parallel-login flake. Retry the submit a couple of
  // times so a transient failure doesn't sink the whole spec.
  for (let attempt = 1; attempt <= 3; attempt += 1) {
    await page.goto('/login');
    await page.waitForSelector('input[name="_username"]', { timeout: 10000 });
    await page.locator('input[name="_username"]').fill(username);
    await page.locator('input[name="_password"]').fill(password);
    await page.locator('#form-submit').click();
    try {
      // / redirects into the SolidJS SPA: a successful login lands on
      // /ui/tracking (the worklog).
      await page.waitForURL(/\/ui\//, { timeout: 10000 });

      return;
    } catch (error) {
      if (attempt === 3) {
        throw error;
      }
    }
  }
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
 * Per-worker users for specs that create/mutate worklog data. Both exist in the
 * e2e DB *and* in LDAP *and* can book the global "Freizeit" customer. (unittest is
 * in LDAP but absent from the e2e DB, so it can't be used here.)
 */
const ISOLATION_USERS = ['developer', 'myself'] as const;

/**
 * Log in as a user chosen by the running worker's slot, so specs that create
 * worklog entries never share a backend account with a *concurrently* running
 * spec — and therefore never see each other's rows. `parallelIndex` is the worker
 * slot [0..workers-1]; two tests with the same slot never run at the same time, so
 * concurrent specs always get distinct users for up to ISOLATION_USERS.length
 * workers. CI runs exactly 2 workers, matching the two available users.
 */
export async function loginIsolated(page: Page): Promise<void> {
  const userKey = ISOLATION_USERS[test.info().parallelIndex % ISOLATION_USERS.length];
  await loginAs(page, userKey);
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
