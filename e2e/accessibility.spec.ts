import AxeBuilder from '@axe-core/playwright';
import { test, expect } from '@playwright/test';
import type { Page } from '@playwright/test';
import { loginAs, loginIsolated } from './helpers/auth';
import { goToWorklogPage, goToAuswertungPage, goToAdminPage, hideDebugToolbar } from './helpers/navigation';

/**
 * First real-browser accessibility gate for the SolidJS UI: axe-core (WCAG 2.0/2.1
 * A + AA) against the key pages, asserting zero serious/critical violations.
 * Until now a11y stopped at jsdom vitest-axe component tests, which can't catch
 * computed-contrast, focus-order or live-region issues that only exist once the
 * page is actually rendered and styled in a browser.
 *
 * Each test navigates with the existing goTo* helpers (which already
 * waitForSelector on the page's settled marker) BEFORE analyzing — no fixed
 * sleeps. The scan is read-only: no entry creation, no teardown.
 */

/**
 * Run axe against `page`, optionally scoped to `scopeSelector`, and assert no
 * serious/critical WCAG-A/AA violations. We exclude two regions that are not part
 * of the shipped SolidJS code:
 *   - .sf-toolbar: the Symfony web debug toolbar APP_ENV=test injects; not shipped.
 * Moderate/minor violations are intentionally not asserted on (this is a serious/
 * critical gate); the JSON summary is attached to the failure message so a real
 * regression names the rule, impact and node count.
 */
async function expectNoSeriousA11y(page: Page, scopeSelector?: string): Promise<void> {
  await hideDebugToolbar(page).catch(() => undefined); // APP_ENV=test injects .sf-toolbar
  // Stabilise the render before scanning. Two intermittent sources of a phantom
  // colour-contrast failure on the worklog table's sticky header:
  //   1. Web fonts still swapping — wait for document.fonts.ready.
  //   2. The grid auto-focuses a row, which can scroll a body row UNDER the
  //      position:sticky <th>; axe mis-samples an overlapping sticky element's
  //      background (reporting a composite colour that isn't in the CSS), so
  //      reset every scroll container (and the window) to the top, where the
  //      header overlaps nothing.
  // Then settle two animation frames so the scan sees a fully painted, stable DOM.
  await page.evaluate(async () => {
    await document.fonts?.ready; // no-op if the Font Loading API is absent
    // The worklog grid auto-focuses a row on load, whose scroll-into-view can push
    // a body row UNDER the position:sticky <th> even after an initial scroll reset —
    // axe then composites the header colour with the row peeking beneath and reports
    // a phantom colour-contrast failure. Blur that focus, let any pending
    // scroll-into-view run, THEN reset every scroll container to the top as the LAST
    // action before the scan, so no row sits under the sticky header when axe samples.
    (document.activeElement as HTMLElement | null)?.blur();
    await new Promise<void>((resolve) => requestAnimationFrame(() => requestAnimationFrame(() => resolve())));
    window.scrollTo(0, 0);
    document.querySelectorAll('.table-scroll, .modal-page-body').forEach((el) => { el.scrollTop = 0; });
    await new Promise<void>((resolve) => requestAnimationFrame(() => requestAnimationFrame(() => resolve())));
  });
  let builder = new AxeBuilder({ page })
    .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
    .exclude('.sf-toolbar');  // Symfony web debug toolbar — not shipped code
  if (scopeSelector) builder = builder.include(scopeSelector);
  const { violations } = await builder.analyze();
  const serious = violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
  const summary = serious.map((v) => ({ id: v.id, impact: v.impact, nodes: v.nodes.length }));
  expect(serious, JSON.stringify(summary, null, 2)).toEqual([]);
}

test.describe('Accessibility (axe-core, WCAG 2.1 AA, serious/critical)', () => {
  test('/login has no serious/critical a11y violations', async ({ page }) => {
    await page.goto('/login');
    await page.waitForSelector('#form-submit');
    await expectNoSeriousA11y(page);
  });

  test('/ui/tracking has no serious/critical a11y violations', async ({ page }) => {
    await loginIsolated(page);
    await goToWorklogPage(page);
    await expectNoSeriousA11y(page, 'main');
  });

  test('/ui/auswertung has no serious/critical a11y violations', async ({ page }) => {
    await loginIsolated(page);
    await goToAuswertungPage(page);
    await expectNoSeriousA11y(page, 'main');
  });

  test('/ui/admin has no serious/critical a11y violations', async ({ page }) => {
    // The admin page is ROLE_ADMIN-only; the per-worker 'developer' isolation slot
    // can't reach it, so log in explicitly as the admin user. loginAs pulls the
    // password from TEST_USERS (env-var-backed, no credential literal in the spec).
    await loginAs(page, 'myself');
    await goToAdminPage(page);
    await expectNoSeriousA11y(page, 'section.admin-page');
  });
});
