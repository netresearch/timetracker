import { test, expect } from '@playwright/test';
import { login } from './helpers/auth';
import { waitForGrid } from './helpers/grid';
import { goToAuswertungPage } from './helpers/navigation';

/**
 * E2E tests for the Evaluation (Auswertung) feature.
 *
 * Interpretation/Auswertung lives in the SolidJS UI
 * (frontend/src/pages/Auswertung.tsx), served at
 * `/ui/auswertung`. These tests drive that page; the grouped-effort charts
 * render for the logged-in user's own entries by default. The API-endpoint
 * tests further below exercise the backend the page consumes.
 */

test.describe('Evaluation (Auswertung) page', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
    await waitForGrid(page);
    await goToAuswertungPage(page);
  });

  test('should display the evaluation page with a filter bar', async ({ page }) => {
    await expect(page.locator('form.filter-bar')).toBeVisible();
    // customer/project/team/user/activity are searchable comboboxes now
    // (SearchableSelect), plus the ticket/description text inputs.
    expect(await page.locator('form.filter-bar .searchable-select').count()).toBeGreaterThan(0);
  });

  test('should have a start and end date filter', async ({ page }) => {
    // Start/end are DateField controls now — text inputs that honour the user's
    // date format with a calendar popover, not native date inputs.
    await expect(page.locator('form.filter-bar .date-field')).toHaveCount(2);
  });

  test('should render effort results when filters are applied', async ({ page }) => {
    // The default filter targets the logged-in user (user > 0), so the effort
    // charts and last-entries table render without further input. Applying the
    // (unchanged) filter must keep them visible.
    await page.locator('form.filter-bar button[type="submit"]').click();
    await expect(page.locator('.effort-charts')).toBeVisible();
    await expect(page.locator('.effort-chart').first()).toBeVisible();
  });

  test('should reset filters back to defaults', async ({ page }) => {
    // The ticket field is the first free-text input in the filter grid; the
    // date-range inputs are DateField controls in a separate row above.
    const ticket = page.locator('form.filter-bar .filter-grid input[type="text"]').first();
    await ticket.fill('ABC-123');
    await expect(ticket).toHaveValue('ABC-123');

    // Reset lives in .form-actions; scope past the date-range preset buttons
    // (also button[type="button"]) so this targets the Reset control only.
    await page.locator('form.filter-bar .form-actions button[type="button"]').click();
    await expect(ticket).toHaveValue('');
  });
});

test.describe('Interpretation API Endpoints', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
  });

  test('/interpretation/entries should return entries', async ({ page }) => {
    // Endpoint requires at least one filter: customer, project, user, ticket, or year+month
    const response = await page.request.get('/interpretation/entries?user=1');

    // Should return OK status
    expect(response.ok()).toBe(true);

    const data = await response.json();
    expect(Array.isArray(data)).toBe(true);

    if (data.length > 0) {
      const firstItem = data[0];
      expect(firstItem).toHaveProperty('entry');
    }
  });

  // The five grouped-interpretation endpoints. Until 2026-09 these were called
  // as /interpretation/groupByCustomer and friends, which do not exist: every
  // request 404'd and the `if (response.ok())` wrapper made the test pass
  // anyway. `debug:router` names them /interpretation/{customer,project,
  // activity,user,ticket}. Like /interpretation/entries they need at least one
  // filter, so each passes user=1.
  for (const [route, label] of [
    ['customer', 'customer'],
    ['project', 'project'],
    ['activity', 'activity'],
    ['user', 'user'],
    ['ticket', 'ticket'],
  ] as const) {
    test(`/interpretation/${route} should group entries by ${label}`, async ({ page }) => {
      const response = await page.request.get(`/interpretation/${route}?user=1`);

      expect(response.ok()).toBe(true);

      const data = await response.json();
      expect(Array.isArray(data)).toBe(true);
    });
  }
});

test.describe('Entry Filtering', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
    await waitForGrid(page);
    await goToAuswertungPage(page);
  });

  test('should expose customer/project/user filters defaulting to "all"', async ({ page }) => {
    // The five relation filters (customer, project, team, user, activity) are
    // searchable comboboxes. Each defaults to its "Alle" (value 0) entry, which in
    // the unified control shows as no value chip — an empty search field.
    const filters = page.locator('form.filter-bar .searchable-select');
    expect(await filters.count()).toBeGreaterThanOrEqual(5);
    await expect(filters.first().locator('.tag-list .tag')).toHaveCount(0);
  });

  test('should keep the page functional after changing a filter', async ({ page }) => {
    // Type into the first relation combobox (customer) to open + filter the list,
    // then pick a real option — only when the seed data provides a match. Typing
    // filters out the leading "Alle" entry, so the first result is a real option.
    const firstCombo = page.locator('form.filter-bar .searchable-select').first();
    await firstCombo.locator('.combobox-input').pressSequentially('e', { delay: 30 });
    const option = firstCombo.locator('.combobox-item').first();
    if (await option.isVisible().catch(() => false)) {
      await option.click();
    } else {
      await page.keyboard.press('Escape');
    }
    await page.locator('form.filter-bar button[type="submit"]').click();
    await expect(page.locator('section.auswertung')).toBeVisible();
  });
});

test.describe('Time Summary', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
  });

  test('/getTimeSummary should return correct format', async ({ page }) => {
    const response = await page.request.get('/getTimeSummary');
    expect(response.ok()).toBe(true);
    // Deprecated v1 endpoint (ADR-022) — signalled per response until removal in v7.
    expect(response.headers()['deprecation']).toBe('true');

    const data = await response.json();

    // Should have today, week, month properties
    expect(data).toHaveProperty('today');
    expect(data).toHaveProperty('week');
    expect(data).toHaveProperty('month');

    // Each should have duration
    expect(data.today).toHaveProperty('duration');
    expect(data.week).toHaveProperty('duration');
    expect(data.month).toHaveProperty('duration');

    console.log('Time summary:', {
      today: data.today.duration,
      week: data.week.duration,
      month: data.month.duration,
    });
  });

  test('/api/v2/time-balance should return the v2 balance shape', async ({ page }) => {
    const response = await page.request.get('/api/v2/time-balance');
    expect(response.ok()).toBe(true);

    const data = await response.json();

    expect(data).toHaveProperty('warnings');
    for (const period of ['today', 'week', 'month'] as const) {
      expect(data).toHaveProperty(period);
      for (const key of ['ist', 'soll_total', 'soll_so_far', 'diff', 'status'] as const) {
        expect(data[period]).toHaveProperty(key);
      }
    }
  });

  // The ticket is a PATH segment (/getTicketTimeSummary/{ticket}), not a query
  // parameter. The old call passed ?ticket=… , which left the route's default
  // (null) in place, and the `if (response.ok())` hid the result either way.
  test('/getTicketTimeSummary/{ticket} should return the per-ticket breakdown', async ({ page }) => {
    // LK-12 carries two human entries in sql/testdata.sql.
    const response = await page.request.get('/getTicketTimeSummary/LK-12');

    expect(response.ok()).toBe(true);

    const data = await response.json();
    expect(data).toHaveProperty('total_time');
    expect(data.total_time).toHaveProperty('time');
    expect(data.total_time).toHaveProperty('seconds');
    expect(data).toHaveProperty('users');
  });

  test('/getTicketTimeSummary/{ticket} should 404 for a ticket with no entries', async ({ page }) => {
    const response = await page.request.get('/getTicketTimeSummary/NOSUCHTICKET-9999');

    expect(response.status()).toBe(404);
  });
});

test.describe('CSV Export', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
  });

  // The route is /export/{days} with days defaulting to 10000. The old call used
  // /export/csv, where "csv" lands in the {days} segment; the controller's
  // is_numeric() guard falls back to the default, so it answered 200 by
  // accident rather than by contract.
  test('should export entries to CSV', async ({ page }) => {
    const response = await page.request.get('/export');

    expect(response.ok()).toBe(true);
    expect(response.headers()['content-type']).toContain('text/csv');
    expect(response.headers()['content-disposition']).toContain('.csv');
  });
});
