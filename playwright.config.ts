import { defineConfig, devices } from '@playwright/test';

/**
 * Playwright E2E Test Configuration
 *
 * E2E tests run against a dedicated test stack:
 * - `make e2e` starts the E2E stack on port 8766 and runs tests
 * - `make e2e-up` starts only the stack for manual testing
 * - `make e2e-run` runs tests against an already-running stack
 *
 * The E2E stack uses:
 * - APP_ENV=test with .env.test.local overrides
 * - Local LDAP server (ldap-dev container)
 * - Main database with test users
 *
 * @see https://playwright.dev/docs/test-configuration
 */
export default defineConfig({
  testDir: './e2e',
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  // In CI with sharding, use 2 workers per shard for parallelism
  workers: process.env.CI ? 2 : undefined,
  reporter: [
    ['html', { open: 'never' }],
    ['list'],
  ],
  use: {
    // E2E stack runs on port 8766 by default
    baseURL: process.env.E2E_BASE_URL || 'http://localhost:8766',
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
  },
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
  // Web server is managed by Makefile (make e2e-up)
  // Use `make e2e` to run tests with automatic stack management
  webServer: process.env.CI
    ? undefined
    : {
        command: 'make e2e-up',
        url: 'http://localhost:8766/login',
        reuseExistingServer: true,
        timeout: 120000, // 2 minutes for container startup
      },
});
