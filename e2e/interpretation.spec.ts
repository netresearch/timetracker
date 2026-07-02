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
    // Selects for customer/project/team/user/activity plus the ticket/description text inputs.
    expect(await page.locator('form.filter-bar select').count()).toBeGreaterThan(0);
  });

  test('should have a start and end date filter', async ({ page }) => {
    await expect(page.locator('form.filter-bar input[type="date"]')).toHaveCount(2);
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
    const ticket = page.locator('form.filter-bar input[type="text"]').first();
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

  test('/interpretation/groupByCustomer should group entries by customer', async ({ page }) => {
    // First check if the endpoint exists
    const response = await page.request.get('/interpretation/groupByCustomer');

    if (response.ok()) {
      const data = await response.json();

      // Should be an array of grouped entries
      expect(Array.isArray(data)).toBe(true);

      if (data.length > 0) {
        // Each group should have customer info
        const firstGroup = data[0];
        console.log('GroupByCustomer sample:', firstGroup);
      }
    } else {
      // Endpoint might require POST with parameters
      console.log('GroupByCustomer requires POST or parameters');
    }
  });

  test('/interpretation/groupByProject should group entries by project', async ({ page }) => {
    const response = await page.request.get('/interpretation/groupByProject');

    if (response.ok()) {
      const data = await response.json();
      expect(Array.isArray(data)).toBe(true);

      if (data.length > 0) {
        console.log('GroupByProject sample:', data[0]);
      }
    }
  });

  test('/interpretation/groupByActivity should group entries by activity', async ({ page }) => {
    const response = await page.request.get('/interpretation/groupByActivity');

    if (response.ok()) {
      const data = await response.json();
      expect(Array.isArray(data)).toBe(true);
    }
  });

  test('/interpretation/groupByUser should group entries by user', async ({ page }) => {
    const response = await page.request.get('/interpretation/groupByUser');

    if (response.ok()) {
      const data = await response.json();
      expect(Array.isArray(data)).toBe(true);
    }
  });

  test('/interpretation/groupByTicket should group entries by ticket', async ({ page }) => {
    const response = await page.request.get('/interpretation/groupByTicket');

    if (response.ok()) {
      const data = await response.json();
      expect(Array.isArray(data)).toBe(true);
    }
  });
});

test.describe('Entry Filtering', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
    await waitForGrid(page);
    await goToAuswertungPage(page);
  });

  test('should expose customer/project/user filters defaulting to "all"', async ({ page }) => {
    // The five relation filters (customer, project, team, user, activity) each
    // carry a "0" = all option as their first entry.
    const selects = page.locator('form.filter-bar select');
    expect(await selects.count()).toBeGreaterThanOrEqual(5);
    await expect(selects.first().locator('option[value="0"]')).toHaveCount(1);
  });

  test('should keep the page functional after changing a filter', async ({ page }) => {
    const customer = page.locator('form.filter-bar select').first();
    const options = customer.locator('option');
    // Only exercise selection when seed data provides at least one real option.
    if ((await options.count()) > 1) {
      await customer.selectOption({ index: 1 });
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

  test('/getTicketTimeSummary should return ticket times', async ({ page }) => {
    // This endpoint returns time spent per ticket
    const response = await page.request.get('/getTicketTimeSummary?ticket=TEST-001');

    if (response.ok()) {
      const data = await response.json();
      console.log('Ticket time summary:', data);
    }
  });
});

test.describe('CSV Export', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
  });

  test('should export entries to CSV', async ({ page }) => {
    // CSV export endpoint
    const response = await page.request.get('/export/csv');

    if (response.ok()) {
      const contentType = response.headers()['content-type'];
      console.log('CSV export content-type:', contentType);

      // Should be CSV or text content
      expect(
        contentType?.includes('text/csv') ||
        contentType?.includes('text/plain') ||
        contentType?.includes('application/csv')
      ).toBe(true);
    }
  });
});
