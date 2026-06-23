import { expect, type Locator, type Page } from '@playwright/test';

/**
 * Shared Worklog-grid helpers. The seeded entries predate the default day range,
 * so tests create their own entry for *today* via the Add flow. Relation cells
 * (customer/project/activity) are Ark Combobox chip editors — picked by opening
 * the cell, optionally filtering, and clicking the first option.
 */

/**
 * A SUCCESSFUL (HTTP 200) POST to the worklog save endpoint. Picking the three
 * required relations one by one fires a partial auto-save after each, so the
 * endpoint answers 422 (incomplete) twice before the final 200 — matching on the
 * method alone would resolve on the first 422 and let the helper return while the
 * real save (and its reconciling refetch) is still in flight.
 */
const isSaveResponse = (r: { url(): string; status(): number; request(): { method(): string } }): boolean =>
  /\/tracking\/save$/.test(r.url()) && r.request().method() === 'POST' && r.status() === 200;

/** The reconciling GET the grid issues after a save lands (invalidate → refetch). */
const isEntriesRefetch = (r: { url(): string }): boolean => /\/getData\/days\//.test(r.url());

export async function openTextEditor(page: Page, row: Locator, colKey: string): Promise<Locator> {
  await row.locator(`td[data-col-key="${colKey}"]`).focus();
  await page.keyboard.press('Enter');
  const editor = page.locator('td[data-inline-editing] input.inline-editor').first();
  await expect(editor).toBeVisible();
  return editor;
}

// Open a relation cell's combobox (Add already opens the first one) and pick its
// first available option, committing it.
export async function pickFirstOption(page: Page, row: Locator, colKey: string, alreadyOpen = false): Promise<void> {
  if (!alreadyOpen) {
    await row.locator(`td[data-col-key="${colKey}"]`).focus();
    await page.keyboard.press('Enter');
  }
  await expect(page.locator('.combobox-input').first()).toBeVisible();
  const option = page.locator('.combobox-content .combobox-item').first();
  await expect(option).toBeVisible({ timeout: 8000 });
  await option.click();
  await expect(page.locator('.combobox-content')).toBeHidden({ timeout: 4000 });
}

export function rowByStamp(page: Page, stamp: string): Locator {
  return page.locator('tr.tracking-row').filter({ hasText: stamp }).first();
}

/**
 * Delete every e2e-stamped entry the current user can see, via the same plain
 * form-POST the app uses (session cookie, same-origin → CSRF origin check passes).
 * Call it in an afterEach so the shared db-e2e doesn't accumulate the fixed-time
 * entries these tests create — left to pile up they overlap at the same fixed time
 * and confuse cell-focus + clipboard targeting (the no-teardown pollution the testing
 * review flagged). Best-effort: a failed delete is swallowed, never failing the test.
 */
export async function cleanupWorklogEntries(page: Page): Promise<void> {
  // A test may have ended on another page (e.g. Settings); the cleanup reads the
  // grid DOM, so return to the worklog first or it would silently delete nothing
  // and leave the shared DB polluted.
  if (!page.url().includes('/ui/tracking')) {
    await page.goto('/ui/tracking').catch(() => undefined);
    await page.locator('table.tracking-table').first().waitFor({ timeout: 5000 }).catch(() => undefined);
  }
  await page
    .evaluate(async () => {
      const ids = new Set<string>();
      document.querySelectorAll('tr.tracking-row').forEach((tr) => {
        if (tr.textContent?.includes('e2e-') === true) {
          const id = tr.querySelector('[data-row-id]')?.getAttribute('data-row-id');
          if (id != null && Number.isInteger(Number(id)) && Number(id) > 0) ids.add(id);
        }
      });
      // Independent best-effort deletes — fire them concurrently.
      await Promise.all(
        Array.from(ids).map((id) =>
          fetch('/tracking/delete', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            credentials: 'same-origin',
            body: `id=${encodeURIComponent(id)}`,
          }).catch(() => undefined),
        ),
      );
    })
    .catch(() => undefined);
}

/** Create one entry dated today and return its unique description stamp. */
export async function createWorklogEntry(page: Page): Promise<string> {
  const stamp = `e2e-${Date.now()}`;
  await page.getByRole('button', { name: /Add entry|Eintrag hinzufügen/i }).click();
  const row = page.locator('tr.tracking-row.is-new').first();
  await expect(row).toBeVisible();

  // Add opens the customer combobox; close it so we can set the plain fields first.
  await page.keyboard.press('Escape');

  // Set explicit start/end/description while the row still lacks its required
  // relations and therefore can't auto-save — so nothing saves mid-helper and the
  // .is-new row never detaches under us (the historic tracking-grid flake). Fixed
  // early times, *overriding* the suggest-time default that starts entries at "now"
  // and marches +minDuration each one, give a stable, non-drifting span. The window
  // sits at the very start of the day so the wall-clock "now" is ALWAYS at or past
  // the start (Prolong rewrites the end to "now" and aborts when now < start) — a
  // later fixed start (e.g. 08:00) would silently no-op Prolong for any run before
  // that hour, the time-of-day flake the earlier 08:00–09:00 span hid.
  const start = await openTextEditor(page, row, 'start');
  await start.fill('00:00');
  await page.keyboard.press('Enter');
  const end = await openTextEditor(page, row, 'end');
  await end.fill('00:15');
  await page.keyboard.press('Enter');
  const description = await openTextEditor(page, row, 'description');
  await description.fill(stamp);
  await page.keyboard.press('Enter');

  // Complete the three required relations; the last one validates the row and fires
  // the save (HTTP 200) carrying every field set above. Wait for that 200 AND the
  // reconciling GET refetch the grid issues afterwards, so the row's <tr> has been
  // rebuilt from the authoritative server list before we hand back — a caller that
  // immediately opens an editor then can't have the cell detached under it by a
  // late-landing refetch (the historic boundingBox-null flake).
  const saved = page.waitForResponse(isSaveResponse);
  const refetched = page.waitForResponse(isEntriesRefetch);
  await pickFirstOption(page, row, 'customer');
  await pickFirstOption(page, row, 'project');
  await pickFirstOption(page, row, 'activity');
  await saved;
  await refetched;

  // The grid rebuilds this <tr> from the post-save refetch, then SolidJS may
  // re-reconcile it once more as the authoritative list settles. Under CI
  // 2-worker contention that save→refetch→render path can outlast Playwright's
  // default 5s expect timeout — the historic shard-9 worklog-crud flake — so
  // give the row the same 15s the page's other readiness waits use.
  await expect(rowByStamp(page, stamp)).toBeVisible({ timeout: 15000 });
  return stamp;
}
