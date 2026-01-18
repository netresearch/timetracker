/**
 * Date format conversion helpers for E2E tests.
 *
 * The API returns dates in display format (d/m/Y) for the frontend to show.
 * When sending data back to the API, dates must be in ISO 8601 format (Y-m-d).
 * This mirrors what the ExtJS frontend does internally.
 */

/**
 * Converts a date from display format (d/m/Y) to ISO 8601 format (Y-m-d).
 *
 * @param displayDate - Date in d/m/Y format (e.g., "15/01/2024")
 * @returns Date in Y-m-d format (e.g., "2024-01-15")
 */
export function displayDateToIso(displayDate: string): string {
  const [day, month, year] = displayDate.split('/');
  return `${year}-${month}-${day}`;
}

/**
 * Converts a date from ISO 8601 format (Y-m-d) to display format (d/m/Y).
 *
 * @param isoDate - Date in Y-m-d format (e.g., "2024-01-15")
 * @returns Date in d/m/Y format (e.g., "15/01/2024")
 */
export function isoDateToDisplay(isoDate: string): string {
  const [year, month, day] = isoDate.split('-');
  return `${day}/${month}/${year}`;
}
