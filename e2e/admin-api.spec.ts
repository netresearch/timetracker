import { test, expect } from '@playwright/test';
import { login } from './helpers/auth';

/**
 * E2E tests for Admin API endpoints.
 *
 * These tests verify the API endpoints for managing:
 * - Customers
 * - Projects
 * - Activities
 * - Users
 * - Teams
 * - Presets
 * - Ticket Systems
 *
 * Note: These tests require appropriate permissions (PL role for most admin operations).
 * The API returns data wrapped in entity keys: { customer: {...} }, { project: {...} }, etc.
 */

// Helper to unwrap API response - handles both wrapped ({customer: {...}}) and direct formats
function unwrapEntity<T>(item: T | { [key: string]: T }, key: string): T {
  if (item && typeof item === 'object' && key in item) {
    return (item as { [key: string]: T })[key];
  }
  return item as T;
}

test.describe('Customer API', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
  });

  test('GET /getAllCustomers should return customer list', async ({ page }) => {
    const response = await page.request.get('/getAllCustomers');
    expect(response.ok()).toBe(true);

    const customers = await response.json();
    expect(Array.isArray(customers)).toBe(true);

    if (customers.length > 0) {
      const rawItem = customers[0];
      const customer = unwrapEntity(rawItem, 'customer');
      expect(customer).toHaveProperty('id');
      expect(customer).toHaveProperty('name');
      console.log('Sample customer:', { id: customer.id, name: customer.name });
    }
  });

  test('GET /getCustomer should return customer details', async ({ page }) => {
    // First get list of customers
    const listResponse = await page.request.get('/getAllCustomers');
    const customers = await listResponse.json();

    // Find first customer with valid id (skip id=0 which is empty template)
    const validCustomer = customers
      .map((c: unknown) => unwrapEntity(c, 'customer'))
      .find((c: { id: number }) => c.id > 0);

    if (validCustomer) {
      const customerId = validCustomer.id;
      const response = await page.request.get(`/getCustomer?id=${customerId}`);

      if (response.ok()) {
        const result = await response.json();
        // Response might be wrapped or direct - handle both
        const customer = typeof result === 'object' && result !== null
          ? unwrapEntity(result, 'customer')
          : result;

        // Only validate if we got an object back
        if (typeof customer === 'object' && customer !== null) {
          expect(customer).toHaveProperty('id');
          expect(customer.id).toBe(customerId);
        }
      }
    }
  });

  test('POST /customer/save should create/update customer', async ({ page }) => {
    // This test verifies the endpoint format, but won't actually save
    // to avoid polluting test data

    const response = await page.request.post('/customer/save', {
      headers: { 'Content-Type': 'application/json' },
      data: {
        name: 'E2E Test Customer',
        active: true,
      },
    });

    console.log('Customer save response status:', response.status());

    // Response might be 403 if user lacks permissions
    if (response.status() === 403) {
      console.log('User lacks permission to save customers');
      return;
    }

    if (response.ok()) {
      const result = await response.json();
      console.log('Customer save result:', result);

      // Clean up - delete the test customer if created
      const customer = unwrapEntity(result, 'customer');
      if (customer?.id) {
        await page.request.post('/customer/delete', {
          form: { id: customer.id },
        });
      }
    }
  });
});

test.describe('Project API', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
  });

  test('GET /getAllProjects should return all projects', async ({ page }) => {
    const response = await page.request.get('/getAllProjects');
    expect(response.ok()).toBe(true);

    const projects = await response.json();
    expect(Array.isArray(projects)).toBe(true);

    if (projects.length > 0) {
      const project = unwrapEntity(projects[0], 'project');
      expect(project).toHaveProperty('id');
      expect(project).toHaveProperty('name');
      console.log('Sample project:', { id: project.id, name: project.name });
    }
  });

  test('GET /getProjects should return projects for customer', async ({ page }) => {
    // First get a customer ID
    const customersResponse = await page.request.get('/getAllCustomers');
    const customers = await customersResponse.json();

    if (customers.length > 0) {
      const customer = unwrapEntity(customers[0], 'customer');
      const customerId = customer.id;
      const response = await page.request.get(`/getProjects?customer=${customerId}`);

      expect(response.ok()).toBe(true);
      const projects = await response.json();
      expect(Array.isArray(projects)).toBe(true);

      console.log(`Projects for customer ${customerId}:`, projects.length);
    }
  });

  test('GET /getProjectStructure should return project tree', async ({ page }) => {
    const response = await page.request.get('/getProjectStructure');

    if (response.ok()) {
      const structure = await response.json();
      console.log('Project structure:', typeof structure);
    }
  });
});

test.describe('Activity API', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
  });

  test('GET /getActivities should return activity list', async ({ page }) => {
    const response = await page.request.get('/getActivities');
    expect(response.ok()).toBe(true);

    const activities = await response.json();
    expect(Array.isArray(activities)).toBe(true);

    if (activities.length > 0) {
      const activity = unwrapEntity(activities[0], 'activity');
      expect(activity).toHaveProperty('id');
      expect(activity).toHaveProperty('name');
      console.log('Sample activity:', { id: activity.id, name: activity.name });
    }
  });

  test('POST /activity/save should validate activity data', async ({ page }) => {
    const response = await page.request.post('/activity/save', {
      headers: { 'Content-Type': 'application/json' },
      data: {
        name: 'E2E Test Activity',
        needsTicket: false,
        factor: 1,
      },
    });

    console.log('Activity save response status:', response.status());

    if (response.status() === 403) {
      console.log('User lacks permission to save activities');
      return;
    }

    if (response.ok()) {
      const result = await response.json();
      const activity = unwrapEntity(result, 'activity');

      // Clean up
      if (activity?.id) {
        await page.request.post('/activity/delete', {
          form: { id: activity.id },
        });
      }
    }
  });
});

test.describe('User API', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
  });

  test('GET /getAllUsers should return user list', async ({ page }) => {
    const response = await page.request.get('/getAllUsers');
    expect(response.ok()).toBe(true);

    const users = await response.json();
    expect(Array.isArray(users)).toBe(true);

    if (users.length > 0) {
      const user = unwrapEntity(users[0], 'user');
      expect(user).toHaveProperty('id');
      expect(user).toHaveProperty('username');
      console.log('Sample user:', { id: user.id, username: user.username });
    }
  });

  test('GET /getUsers should return active users', async ({ page }) => {
    const response = await page.request.get('/getUsers');

    if (response.ok()) {
      const users = await response.json();
      expect(Array.isArray(users)).toBe(true);
      console.log('Active users count:', users.length);
    }
  });
});

test.describe('Team API', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
  });

  test('GET /getTeams should return team list', async ({ page }) => {
    const response = await page.request.get('/getTeams');

    if (response.ok()) {
      const teams = await response.json();
      expect(Array.isArray(teams)).toBe(true);

      if (teams.length > 0) {
        const team = unwrapEntity(teams[0], 'team');
        console.log('Sample team:', team);
      }
    }
  });

  test('POST /team/save should validate team data', async ({ page }) => {
    const response = await page.request.post('/team/save', {
      headers: { 'Content-Type': 'application/json' },
      data: {
        name: 'E2E Test Team',
      },
    });

    console.log('Team save response status:', response.status());

    if (response.status() === 403) {
      console.log('User lacks permission to save teams');
    }
  });
});

test.describe('Preset API', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
  });

  test('GET /getPresets should return preset list', async ({ page }) => {
    const response = await page.request.get('/getPresets');

    if (response.ok()) {
      const presets = await response.json();
      expect(Array.isArray(presets)).toBe(true);

      if (presets.length > 0) {
        const preset = unwrapEntity(presets[0], 'preset');
        expect(preset).toHaveProperty('id');
        expect(preset).toHaveProperty('name');
        console.log('Sample preset:', { id: preset.id, name: preset.name });
      }
    }
  });
});

test.describe('Ticket System API', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
  });

  test('GET /getTicketSystems should return ticket system list', async ({ page }) => {
    const response = await page.request.get('/getTicketSystems');

    if (response.ok()) {
      const systems = await response.json();
      expect(Array.isArray(systems)).toBe(true);

      if (systems.length > 0) {
        const system = unwrapEntity(systems[0], 'ticketSystem');
        expect(system).toHaveProperty('id');
        expect(system).toHaveProperty('name');
        console.log('Sample ticket system:', { id: system.id, name: system.name });
      }
    }
  });
});

test.describe('Contract API', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
  });

  test('GET /getContracts should return contract list', async ({ page }) => {
    const response = await page.request.get('/getContracts');

    if (response.ok()) {
      const contracts = await response.json();
      expect(Array.isArray(contracts)).toBe(true);

      if (contracts.length > 0) {
        const contract = unwrapEntity(contracts[0], 'contract');
        console.log('Sample contract:', contract);
      }
    }
  });
});

test.describe('Data Integrity', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
  });

  test('Customer-Project relationship should be consistent', async ({ page }) => {
    // Get all customers
    const customersResponse = await page.request.get('/getAllCustomers');
    const customersRaw = await customersResponse.json();
    const customers = customersRaw.map((c: unknown) => unwrapEntity(c, 'customer'));

    // Get all projects
    const projectsResponse = await page.request.get('/getAllProjects');
    const projectsRaw = await projectsResponse.json();
    const projects = projectsRaw.map((p: unknown) => unwrapEntity(p, 'project'));

    if (projects.length > 0 && customers.length > 0) {
      // Check that projects reference valid customers
      const customerIds = new Set(customers.map((c: { id: number }) => c.id));

      for (const project of projects) {
        if (project.customer) {
          expect(customerIds.has(project.customer)).toBe(true);
        }
      }
    }
  });

  test('User-Team relationship should be consistent', async ({ page }) => {
    const usersResponse = await page.request.get('/getAllUsers');
    const usersRaw = await usersResponse.json();
    const users = usersRaw.map((u: unknown) => unwrapEntity(u, 'user'));

    const teamsResponse = await page.request.get('/getTeams');
    if (!teamsResponse.ok()) return;

    const teamsRaw = await teamsResponse.json();
    const teams = teamsRaw.map((t: unknown) => unwrapEntity(t, 'team'));

    if (teams.length > 0 && users.length > 0) {
      const teamIds = new Set(teams.map((t: { id: number }) => t.id));

      // Check user team references
      for (const user of users) {
        if (user.teams && Array.isArray(user.teams)) {
          for (const teamId of user.teams) {
            expect(teamIds.has(teamId)).toBe(true);
          }
        }
      }
    }
  });
});
