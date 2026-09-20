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
 * The policy ships report-only, and there is no `report-uri` collector — so
 * without this spec the reports go to a browser console nobody watches, and
 * "report-only first" would be a gesture rather than a measurement. The browser
 * fires `securitypolicyviolation` for a report-only policy too, with
 * `disposition: "report"`, so listening for the event is the collector.
 *
 * A violation here means one of two things, and both need a human: a template
 * renders an inline <script> without `csp_nonce()`, or the policy is missing a
 * source the application legitimately uses. Either way it must be settled
 * before the header is switched from Report-Only to enforcing — at which point
 * every violation listed here becomes a blocked resource.
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
    __cspViolations?: CspViolation[];
  }
}

/**
 * Install the listener before any document script runs — a violation raised by
 * the page's own bootstrap fires before anything a test could attach later.
 */
async function collectViolations(page: Page): Promise<void> {
  await page.addInitScript(() => {
    window.__cspViolations = [];
    document.addEventListener('securitypolicyviolation', (event) => {
      window.__cspViolations?.push({
        directive: event.effectiveDirective || event.violatedDirective,
        blockedURI: event.blockedURI,
        sourceFile: event.sourceFile,
        lineNumber: event.lineNumber,
        disposition: event.disposition,
      });
    });
  });
}

async function violations(page: Page): Promise<CspViolation[]> {
  return (await page.evaluate(() => window.__cspViolations ?? [])) as CspViolation[];
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
  test('the shell declares a report-only policy with a nonce', async ({ page }) => {
    // Authenticated first: SpaAction redirects an anonymous visitor to /login,
    // so an unauthenticated goto would assert the login page's header while
    // claiming to test the shell's — and pass, because both carry one.
    await loginIsolated(page);
    const response = await page.goto('/ui/');
    expect(page.url()).toContain('/ui/');

    const policy = response?.headers()['content-security-policy-report-only'] ?? '';

    expect(policy, 'no report-only policy on the shell').toContain("default-src 'self'");
    expect(policy, 'script-src must be nonce-based, never unsafe-inline').toMatch(
      /script-src 'self' 'nonce-[A-Za-z0-9+/=]+'/,
    );
    expect(policy).not.toContain("script-src 'self' 'unsafe-inline'");
  });

  test('login raises no violation', async ({ page }) => {
    await collectViolations(page);
    await page.goto('/login');
    await page.waitForSelector('#form-submit');

    const found = (await violations(page)).filter(isOurs);
    expect(found, `CSP would have blocked:\n${describe(found)}`).toEqual([]);
  });

  test('the worklog view raises no violation', async ({ page }) => {
    await collectViolations(page);
    await loginIsolated(page);
    await goToWorklogPage(page);

    const found = (await violations(page)).filter(isOurs);
    expect(found, `CSP would have blocked:\n${describe(found)}`).toEqual([]);
  });

  test('the evaluation view raises no violation', async ({ page }) => {
    await collectViolations(page);
    await loginIsolated(page);
    await goToAuswertungPage(page);

    const found = (await violations(page)).filter(isOurs);
    expect(found, `CSP would have blocked:\n${describe(found)}`).toEqual([]);
  });

  // The admin page is ROLE_ADMIN-only and the per-worker isolation slot cannot
  // reach it, so log in as the admin user — same reasoning as accessibility.spec.ts.
  test('the admin view raises no violation', async ({ page }) => {
    await collectViolations(page);
    await loginAs(page, 'myself');
    await goToAdminPage(page);

    const found = (await violations(page)).filter(isOurs);
    expect(found, `CSP would have blocked:\n${describe(found)}`).toEqual([]);
  });
});
