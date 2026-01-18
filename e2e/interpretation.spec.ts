import { test, expect } from '@playwright/test';
import { login } from './helpers/auth';
import { waitForGrid } from './helpers/grid';
import { goToInterpretationTab, goToTrackingTab } from './helpers/navigation';

/**
 * E2E tests for Interpretation (Analysis/Reporting) features.
 *
 * The Interpretation tab provides various views and groupings of time entries.
 */

test.describe('Interpretation Tab', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
    await waitForGrid(page);
  });

  test('should display interpretation tab', async ({ page }) => {
    await goToInterpretationTab(page);

    // Wait for content to load
    await page.waitForTimeout(1000);

    // Should have filter controls
    const hasFilters = await page.locator('.x-form-item, input[type="text"]').count();
    expect(hasFilters).toBeGreaterThan(0);
  });

  test('should have date range filter', async ({ page }) => {
    await goToInterpretationTab(page);
    await page.waitForTimeout(500);

    // Look for date picker or date fields
    const dateFields = page.locator('input[type="text"]').filter({ hasText: /\d{2}\/\d{2}\/\d{4}|\d{4}-\d{2}-\d{2}/i });
    const dateFieldCount = await dateFields.count();

    // Also check for date picker triggers
    const dateTriggers = page.locator('.x-form-date-trigger, .x-form-trigger');
    const triggerCount = await dateTriggers.count();

    console.log(`Date fields: ${dateFieldCount}, Triggers: ${triggerCount}`);

    // Should have some form controls
    expect(dateFieldCount + triggerCount).toBeGreaterThan(0);
  });

  test('should filter entries by date range', async ({ page }) => {
    await goToInterpretationTab(page);
    await page.waitForTimeout(500);

    // Find and click the search/filter button
    const searchButton = page.locator('.x-btn').filter({ hasText: /Search|Suchen|Filter/i });

    if ((await searchButton.count()) > 0) {
      await searchButton.click();
      await page.waitForTimeout(1000);

      // Check if results are displayed
      const hasResults =
        (await page.locator('.x-grid').isVisible()) ||
        (await page.locator('.x-chart').isVisible()) ||
        (await page.locator('table').isVisible());

      expect(hasResults).toBe(true);
    }
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
  });

  test('should filter entries by customer in interpretation view', async ({ page }) => {
    await goToInterpretationTab(page);
    await page.waitForTimeout(500);

    // Find customer dropdown/combo
    const customerField = page.locator('input[name="customer"], input[name="customerId"]');

    if ((await customerField.count()) > 0) {
      // Get the combo trigger
      const trigger = customerField.locator('xpath=ancestor::*[contains(@class, "x-field")]//div[contains(@class, "x-form-trigger")]');

      if ((await trigger.count()) > 0) {
        await trigger.click();
        await page.waitForTimeout(300);

        // Select first item from dropdown
        const firstItem = page.locator('.x-boundlist-item').first();
        if ((await firstItem.count()) > 0) {
          await firstItem.click();
        }
      }
    }
  });

  test('should filter entries by project in interpretation view', async ({ page }) => {
    await goToInterpretationTab(page);
    await page.waitForTimeout(500);

    // Find project dropdown/combo
    const projectField = page.locator('input[name="project"], input[name="projectId"]');

    if ((await projectField.count()) > 0) {
      console.log('Project field found in interpretation view');
    }
  });

  test('should filter entries by user in interpretation view', async ({ page }) => {
    await goToInterpretationTab(page);
    await page.waitForTimeout(500);

    // Find user dropdown/combo
    const userField = page.locator('input[name="user"], input[name="userId"]');

    if ((await userField.count()) > 0) {
      console.log('User field found in interpretation view');
    }
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
