import { test, type Page } from '@playwright/test';

/**
 * Throwaway-customer lifecycle for the Administration specs.
 *
 * Those specs create a global, project-less customer to mutate so they never touch
 * the shared seed rows. Such a row is dangerous residue: the customer list is
 * name-sorted and shared run-wide, so an `E2E*` row that outlives its test sorts
 * ahead of the seeded bookable customer and derails every later spec that reads the
 * list (see SEEDED_BOOKABLE_CUSTOMER in helpers/worklog.ts). #675 hardened those
 * consumers to pick by name; this module closes the leak at the source.
 *
 * Two independent guards, so no single failure can leak a row past the spec:
 *  - deleteThrowawayCustomers() deletes over the HTTP API, not the UI — it works
 *    from a `finally` even when the test died mid-interaction with a modal open,
 *    and it reports a delete it could not perform instead of swallowing it.
 *  - sweepStaleThrowawayCustomers() drops rows left behind by an earlier run that
 *    crashed hard enough to skip its own cleanup.
 */

/** Name prefix per spec, so a sweep can tell throwaway rows from seed rows. */
export const INLINE_EDIT_PREFIX = 'E2EInline';
export const ADMIN_UI_PREFIX = 'E2ECustomer';

const THROWAWAY_PREFIXES = [INLINE_EDIT_PREFIX, ADMIN_UI_PREFIX];

/**
 * `<prefix>_<epoch ms>_<rand>`, plus whatever a spec appends while mutating it
 * (`-edited`, `-draft`, `_Renamed`). The captured epoch ms is what makes the sweep
 * safe under `fullyParallel` — see sweepStaleThrowawayCustomers. The digit run is
 * bounded (no unbounded quantifier) and the pattern is anchored.
 */
const THROWAWAY_NAME = new RegExp(`^(?:${THROWAWAY_PREFIXES.join('|')})_(\\d{10,16})_`);

/**
 * A row younger than this may belong to a test running right now in the other
 * worker (`fullyParallel: true`, 2 workers per CI shard), so the sweep leaves it
 * alone. Playwright's per-test timeout is 30s, so a row this old is provably
 * residue from an earlier run and can never be in use.
 */
const STALE_AFTER_MS = 10 * 60 * 1000;

interface CustomerRow {
  customer: { id: number; name: string };
}

/**
 * Report a cleanup that did not happen. Deliberately non-fatal: this runs from a
 * `finally`, where throwing would replace the test's own error with a teardown one.
 * The annotation surfaces the leak in the HTML report, the console line in the CI
 * log — a silent leak is what let a row survive the spec in the first place.
 */
function reportLeak(message: string): void {
  console.error(`[e2e cleanup] ${message}`);
  try {
    test.info().annotations.push({ type: 'cleanup-failed', description: message });
  } catch {
    // Called outside a test (no TestInfo) — the console line above still stands.
  }
}

/** Every customer the admin list shows, or null when the read itself failed. */
async function readCustomers(page: Page): Promise<CustomerRow[] | null> {
  try {
    const response = await page.request.get('/getAllCustomers');
    if (!response.ok()) {
      reportLeak(`GET /getAllCustomers answered ${response.status()} — cannot verify throwaway customers were removed`);
      return null;
    }
    return (await response.json()) as CustomerRow[];
  } catch (error) {
    reportLeak(`GET /getAllCustomers failed (${String(error)}) — cannot verify throwaway customers were removed`);
    return null;
  }
}

/**
 * Delete every given customer, then report the ones that are still there.
 *
 * The verdict comes from re-reading the list, not from the delete's status code:
 * `/customer/delete` answers 422 both when the row could not go (a real leak) and
 * when it was already gone — which is the NORMAL outcome when the sibling worker
 * swept the same stale row a moment earlier. Reporting on the status alone made
 * every parallel sweep cry leak; the list says what actually happened, and says it
 * in any locale.
 */
async function deleteRows(page: Page, rows: CustomerRow['customer'][]): Promise<void> {
  if (rows.length === 0) {
    return;
  }
  for (const row of rows) {
    await page.request.post('/customer/delete', { data: { id: row.id } }).catch(() => undefined);
  }
  const remaining = await readCustomers(page);
  if (remaining === null) {
    return;
  }
  for (const row of rows) {
    if (remaining.some((candidate) => candidate.customer.id === row.id)) {
      reportLeak(`LEAKED customer "${row.name}" (id ${row.id}) — /customer/delete did not remove it; it will pollute the name-sorted customer list for the rest of this shard`);
    }
  }
}

/** A throwaway name for `prefix`, collision-safe across parallel workers.
 *  Date.now() alone can collide when two workers create a row in the same
 *  millisecond (a unique-name DB violation); the random suffix rules that out. */
export function throwawayCustomerName(prefix: string): string {
  return `${prefix}_${Date.now()}_${Math.floor(Math.random() * 1_000_000)}`;
}

/**
 * Delete the throwaway customer named `base` and every variant a spec renamed it
 * to (`base-edited`, `base-draft`, `base_Renamed`, …) — matching on the prefix
 * means the caller never has to enumerate which name actually landed.
 *
 * Goes over the HTTP API rather than the row's Delete button on purpose: a test
 * that failed mid-UI can leave a modal open or the row detached, which is exactly
 * when the UI delete silently gave up and the row leaked. Nothing here throws, so
 * a `finally` calling it cannot mask the test's own failure; a delete that does not
 * happen is reported instead.
 */
export async function deleteThrowawayCustomers(page: Page, base: string): Promise<void> {
  const customers = await readCustomers(page);
  if (customers === null) {
    return;
  }
  await deleteRows(
    page,
    customers.map((row) => row.customer).filter((customer) => customer.name.startsWith(base)),
  );
}

/**
 * Drop throwaway customers left behind by an EARLIER run — a run killed hard
 * enough to skip its own `finally` still poisons the shared, persistent db-e2e for
 * every run after it. Call it from a beforeEach of the specs that create them, so
 * the shard heals itself instead of needing a manual DB cleanup.
 *
 * Only rows older than STALE_AFTER_MS are touched: under `fullyParallel` a sibling
 * test in the other worker may have a fresh throwaway row live at this very moment,
 * and deleting it would break that test.
 */
export async function sweepStaleThrowawayCustomers(page: Page): Promise<void> {
  const customers = await readCustomers(page);
  if (customers === null) {
    return;
  }
  const now = Date.now();
  const stale = customers.map((row) => row.customer).filter((customer) => {
    const stamp = THROWAWAY_NAME.exec(customer.name);
    return stamp !== null && now - Number(stamp[1]) > STALE_AFTER_MS;
  });
  if (stale.length > 0) {
    console.error(`[e2e cleanup] sweeping ${stale.length} stale throwaway customer(s) from an earlier run: ${stale.map((customer) => customer.name).join(', ')}`);
    await deleteRows(page, stale);
  }
}
