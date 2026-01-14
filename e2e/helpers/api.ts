import { Page, APIResponse } from '@playwright/test';

/**
 * Entry data structure from API
 */
export interface Entry {
  id: number;
  date: string;
  start: string;
  end: string;
  customer: number;
  project: number;
  activity: number;
  description: string;
  ticket: string;
  duration: string;
  durationMinutes: number;
  customerName?: string;
  projectName?: string;
  activityName?: string;
}

/**
 * Entry wrapper from API response
 */
export interface EntryWrapper {
  entry: Entry;
}

/**
 * Get all entries via API
 */
export async function getEntries(page: Page): Promise<EntryWrapper[]> {
  const response = await page.request.get('/getData');
  if (!response.ok()) {
    throw new Error(`Failed to get entries: ${response.status()}`);
  }
  return await response.json();
}

/**
 * Get a single entry by ID
 */
export async function getEntryById(page: Page, id: number): Promise<Entry | null> {
  const entries = await getEntries(page);
  const found = entries.find((e) => e.entry.id === id);
  return found ? found.entry : null;
}

/**
 * Create a new entry via API
 */
export async function createEntry(
  page: Page,
  data: {
    date: string;
    start: string;
    end: string;
    customer: number;
    project: number;
    activity: number;
    description?: string;
    ticket?: string;
  }
): Promise<Entry> {
  const response = await page.request.post('/tracking/save', {
    headers: { 'Content-Type': 'application/json' },
    data: {
      date: data.date,
      start: data.start,
      end: data.end,
      customer: data.customer,
      project: data.project,
      activity: data.activity,
      description: data.description || '',
      ticket: data.ticket || '',
    },
  });

  if (!response.ok()) {
    const error = await response.text();
    throw new Error(`Failed to create entry: ${error}`);
  }

  const result = await response.json();
  return result.result;
}

/**
 * Update an existing entry via API
 */
export async function updateEntry(
  page: Page,
  id: number,
  data: Partial<{
    date: string;
    start: string;
    end: string;
    customer: number;
    project: number;
    activity: number;
    description: string;
    ticket: string;
  }>
): Promise<Entry> {
  const response = await page.request.post('/tracking/save', {
    headers: { 'Content-Type': 'application/json' },
    data: { id, ...data },
  });

  if (!response.ok()) {
    const error = await response.text();
    throw new Error(`Failed to update entry: ${error}`);
  }

  const result = await response.json();
  return result.result;
}

/**
 * Delete an entry via API
 */
export async function deleteEntry(page: Page, id: number): Promise<void> {
  const response = await page.request.post('/tracking/delete', {
    form: { id },
  });

  if (!response.ok()) {
    const error = await response.text();
    throw new Error(`Failed to delete entry: ${error}`);
  }
}

/**
 * Get customers via API
 */
export async function getCustomers(page: Page): Promise<unknown[]> {
  const response = await page.request.get('/getAllCustomers');
  if (!response.ok()) {
    throw new Error(`Failed to get customers: ${response.status()}`);
  }
  return await response.json();
}

/**
 * Get projects via API
 */
export async function getProjects(page: Page, customerId?: number): Promise<unknown[]> {
  const url = customerId ? `/getProjects?customer=${customerId}` : '/getAllProjects';
  const response = await page.request.get(url);
  if (!response.ok()) {
    throw new Error(`Failed to get projects: ${response.status()}`);
  }
  return await response.json();
}

/**
 * Get activities via API
 */
export async function getActivities(page: Page): Promise<unknown[]> {
  const response = await page.request.get('/getActivities');
  if (!response.ok()) {
    throw new Error(`Failed to get activities: ${response.status()}`);
  }
  return await response.json();
}

/**
 * Get time summary via API
 */
export async function getTimeSummary(page: Page): Promise<{
  today: { duration: number };
  week: { duration: number };
  month: { duration: number };
}> {
  const response = await page.request.get('/getTimeSummary');
  if (!response.ok()) {
    throw new Error(`Failed to get time summary: ${response.status()}`);
  }
  return await response.json();
}

/**
 * Get settings data from page context
 */
export async function getSettingsData(page: Page): Promise<Record<string, unknown>> {
  return await page.evaluate(() => {
    return (window as unknown as { settingsData: Record<string, unknown> }).settingsData;
  });
}

/**
 * Save user settings via API
 */
export async function saveSettings(
  page: Page,
  settings: {
    show_empty_line?: number;
    suggest_time?: number;
    show_future?: number;
    locale?: string;
  }
): Promise<{ success: boolean; settings: Record<string, unknown> }> {
  const response = await page.request.post('/settings/save', {
    form: settings,
  });

  if (!response.ok()) {
    const error = await response.text();
    throw new Error(`Failed to save settings: ${error}`);
  }

  return await response.json();
}
