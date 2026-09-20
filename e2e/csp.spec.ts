/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

import { test, expect } from '@playwright/test';
import type { Page } from '@playwright/test';
import { loginAs, loginIsolated } from './helpers/auth';
import { goToWorklogPage, goToAuswertungPage, goToAdminPage } from './helpers/navigation';

/**
 * Reads what the Content-Security-Policy would have blocked (issue #739).
 *
 * The policy is enforced and there is no `report-uri` collector, so the
 * browser event is the only signal. It fires either way: `disposition:
 * "enforce"` now, `"report"` during the report-only round this spec was
 * written for. A violation used to mean a report; it now means a resource the
 * browser refused.
 *
 * A violation here means one of two things, and both need a human: a template
 * renders an inline <script> without `csp_nonce()`, or the policy is missing a
 * source the application legitimately uses. Either way something on that page
 * did not load.
 *
 * Readiness comes from the goTo* helpers, which already wait on each page's
 * settled marker; there is no `networkidle`, which the suite's conventions rule
 * out and which would only add flake here.
 */

interface CspViolation {
  directive: string;
  blockedURI: string;
  sourceFile: string;
  lineNumber: number;
  disposition: string;
}

declare global {
  interface Window {
    __reportCspViolation?: (violation: CspViolation) => void;
  }
}

/**
 * Collect violations on the Node side, not in `window`.
 *
 * A navigation destroys the page context, and `addInitScript` runs again on
 * the new document — anything accumulated in a page-scoped array is lost with
 * it. A test that visits three settings sections would then assert over the
 * third one alone and report the first two as clean. `exposeFunction` survives
 * navigation because Playwright re-installs the binding per document, so every
 * violation from every document in the test lands in one array.
 *
 * The listener is installed before any document script runs: a violation
 * raised by the page's own bootstrap fires before anything a test could attach
 * afterwards.
 */
async function collectViolations(page: Page): Promise<CspViolation[]> {
  const collected: CspViolation[] = [];

  await page.exposeFunction('__reportCspViolation', (violation: CspViolation) => {
    collected.push(violation);
  });

  await page.addInitScript(() => {
    document.addEventListener('securitypolicyviolation', (event) => {
      window.__reportCspViolation?.({
        directive: event.effectiveDirective || event.violatedDirective,
        blockedURI: event.blockedURI,
        sourceFile: event.sourceFile,
        lineNumber: event.lineNumber,
        disposition: event.disposition,
      });
    });
  });

  return collected;
}

/**
 * The Symfony web debug toolbar is injected only under APP_ENV=test and is not
 * shipped; the a11y spec excludes it for the same reason. Its own inline
 * scripts are not ours to nonce.
 */
function isOurs(violation: CspViolation): boolean {
  return !violation.sourceFile.includes('/_wdt/') && !violation.sourceFile.includes('/_profiler/');
}

function describe(list: CspViolation[]): string {
  return list
    .map((v) => `${v.directive} blocked ${v.blockedURI || '(inline)'} at ${v.sourceFile}:${v.lineNumber}`)
    .join('\n');
}

test.describe('Content Security Policy', () => {
  test('the shell declares an enforced policy with a nonce', async ({ page }) => {
    // Authenticated first: SpaAction redirects an anonymous visitor to /login,
    // so an unauthenticated goto would assert the login page's header while
    // claiming to test the shell's — and pass, because both carry one.
    await loginIsolated(page);
    const response = await page.goto('/ui/');
    expect(page.url()).toContain('/ui/');

    const policy = response?.headers()['content-security-policy'] ?? '';

    expect(policy, 'no policy on the shell').toContain("default-src 'self'");
    expect(policy, 'script-src must be nonce-based, never unsafe-inline').toMatch(
      /script-src 'self' 'nonce-[A-Za-z0-9+/=]+'/,
    );
    expect(policy).not.toContain("script-src 'self' 'unsafe-inline'");
  });

  test('login raises no violation', async ({ page }) => {
    const collected = await collectViolations(page);
    await page.goto('/login');
    await page.waitForSelector('#form-submit');

    const found = collected.filter(isOurs);
    expect(found, `CSP would have blocked:\n${describe(found)}`).toEqual([]);
  });

  test('the worklog view raises no violation', async ({ page }) => {
    const collected = await collectViolations(page);
    await loginIsolated(page);
    await goToWorklogPage(page);

    const found = collected.filter(isOurs);
    expect(found, `CSP would have blocked:\n${describe(found)}`).toEqual([]);
  });

  test('the evaluation view raises no violation', async ({ page }) => {
    const collected = await collectViolations(page);
    await loginIsolated(page);
    await goToAuswertungPage(page);

    const found = collected.filter(isOurs);
    expect(found, `CSP would have blocked:\n${describe(found)}`).toEqual([]);
  });

  // The admin page is ROLE_ADMIN-only and the per-worker isolation slot cannot
  // reach it, so log in as the admin user — same reasoning as accessibility.spec.ts.
  test('the admin view raises no violation', async ({ page }) => {
    const collected = await collectViolations(page);
    await loginAs(page, 'myself');
    await goToAdminPage(page);

    const found = collected.filter(isOurs);
    expect(found, `CSP would have blocked:\n${describe(found)}`).toEqual([]);
  });

  // The settings sections render their own inline bootstrap through the same
  // shell, and the account section is where a passkey/TOTP widget mounts.
  test('the settings sections raise no violation', async ({ page }) => {
    const collected = await collectViolations(page);
    await loginIsolated(page);

    // Three documents in one test: the Node-side collector is what makes the
    // first two count. A page-scoped array would report only the last.
    for (const section of ['account', 'appearance', 'security']) {
      await page.goto(`/ui/settings/${section}`);
      await page.waitForURL(new RegExp(`/ui/settings/${section}`));
    }

    const found = collected.filter(isOurs);
    expect(found, `CSP would have blocked:\n${describe(found)}`).toEqual([]);
  });
});
