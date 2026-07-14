import { test, expect, type Page } from '@playwright/test';
import { createEntry, deleteEntry, getActivities } from './helpers/api';
import { loginIsolated } from './helpers/auth';

/**
 * The header's TODAY/WEEK/MONTH summary (#worktime-day/-week/-month) is painted
 * imperatively from GET /api/v2/time-balance, outside TanStack Query. #620: it
 * refreshed only on init and after Tracking-page mutations, so entries written
 * out-of-band (another tab, the REST/MCP API) left it stale until F5. This
 * spec covers the new refocus catch-up against a real out-of-band write. The
 * 90s poll piggyback is deliberately NOT asserted in e2e — an interval
 * assertion against the stack's frozen server clock would be meaningless — it
 * is unit-tested in frontend/src/header.test.ts, as are throttle + hidden-tab.
 */

const isBalanceFetch = (r: { url(): string }): boolean => r.url().includes('/api/v2/time-balance');

interface Refs {
  customer: number;
  project: number;
  activity: number;
}

/** First bookable customer/project/activity for the logged-in user, read via
 *  the same open endpoints the tracking grid uses (/getProjects is
 *  role-gated, so projects come from /getAllProjects filtered by customer). */
async function firstBookableRefs(page: Page): Promise<Refs> {
  const customersResponse = await page.request.get('/getCustomers');
  const customers = (await customersResponse.json()) as Array<{ customer: { id: number } }>;
  const customer = customers[0]!.customer.id;
  const projectsResponse = await page.request.get('/getAllProjects');
  const projects = (await projectsResponse.json()) as Array<{ project: { id: number; customer: number } }>;
  const project = projects.map((row) => row.project).find((candidate) => candidate.customer === customer);
  const activities = (await getActivities(page)) as Array<{ activity: { id: number } }>;
  return { customer, project: project!.id, activity: activities[0]!.activity.id };
}

/** True when the current user's /getData/days/{days} range contains the stamp. */
async function inDayRange(page: Page, days: number, stamp: string): Promise<boolean> {
  const response = await page.request.get(`/getData/days/${days}`);
  const rows = (await response.json()) as Array<{ entry: { description: string } }>;
  return rows.some((row) => row.entry.description === stamp);
}

/**
 * The day the SERVER considers "today". The e2e app runs on its own frozen
 * clock, and only entries dated on ITS today count into the header's TODAY
 * total — so the spec discovers it instead of assuming a date. Mechanism:
 * /getData/days/N filters entries by `day >= today − N`, so an anchor entry
 * planted on a fixed past day D first becomes visible at N = today − D.
 */
async function discoverServerToday(page: Page, refs: Refs): Promise<string> {
  const anchorDay = '2020-01-01';
  const stamp = `e2e-620-anchor-${Date.now()}`;
  const anchor = await createEntry(page, {
    date: anchorDay,
    start: '00:00',
    end: '00:01',
    ...refs,
    description: stamp,
  });
  try {
    let lo = 1; // days such that the anchor is NOT included (today − D > lo)
    let hi = 4096; // upper bound: anchor visible (covers today − D ≈ 11 years)
    expect(await inDayRange(page, hi, stamp)).toBe(true);
    while (hi - lo > 1) {
      const mid = Math.floor((lo + hi) / 2);
      if (await inDayRange(page, mid, stamp)) {
        hi = mid;
      } else {
        lo = mid;
      }
    }
    const today = new Date(`${anchorDay}T00:00:00Z`);
    today.setUTCDate(today.getUTCDate() + hi);
    return today.toISOString().slice(0, 10);
  } finally {
    await deleteEntry(page, anchor.id);
  }
}

/** Best-effort removal of every entry this spec stamped, however old. */
async function cleanupStamped(page: Page): Promise<void> {
  const response = await page.request.get('/getData/days/4096').catch(() => null);
  if (response === null) {
    return;
  }
  const rows = (await response.json()) as Array<{ entry: { id: number; description: string } }>;
  for (const row of rows) {
    if (row.entry.description.startsWith('e2e-620-')) {
      await deleteEntry(page, row.entry.id).catch(() => undefined);
    }
  }
}

test.describe('Header summary refresh (#620)', () => {
  test('an out-of-band write is picked up when the window regains focus', async ({ page }) => {
    // Clock control BEFORE navigation: the refocus refresh is throttled, and
    // the test jumps past the throttle window instead of sleeping through it.
    await page.clock.install();
    await loginIsolated(page);

    try {
      const refs = await firstBookableRefs(page);
      const serverToday = await discoverServerToday(page, refs);

      // Hard reload of the worklog with the init balance fetch awaited, so the
      // TODAY total is a settled pre-write baseline.
      const painted = page.waitForResponse(isBalanceFetch);
      await page.goto('/ui/tracking');
      await painted;
      const day = page.locator('#worktime-day');
      await expect(day).toBeVisible();
      const before = (await day.textContent()) ?? '';

      // Out-of-band write while the tab is open: Playwright's request context
      // shares the session cookie but bypasses the SPA entirely — the same
      // class as the REST/MCP write in #620. Dated on the server's "today" so
      // it counts into the TODAY total; the SPA has not observed it.
      await createEntry(page, {
        date: serverToday,
        start: '02:00',
        end: '03:00',
        ...refs,
        description: `e2e-620-${Date.now()}`,
      });

      // Jump past the refocus throttle, then simulate the tab regaining
      // focus: the header must refetch the balance and repaint the total,
      // one hour up (the entries grid refetches on its own via TanStack
      // Query's refetchOnWindowFocus).
      await page.clock.fastForward(16_000);
      const refreshed = page.waitForResponse(isBalanceFetch);
      await page.evaluate(() => window.dispatchEvent(new Event('focus')));
      await refreshed;
      await expect(day).not.toHaveText(before);
    } finally {
      await cleanupStamped(page);
    }
  });
});
