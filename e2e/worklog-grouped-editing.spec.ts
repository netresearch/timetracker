import { test, expect, type Page } from '@playwright/test';

import { loginIsolated } from './helpers/auth';
import { goToWorklogPage } from './helpers/navigation';
import { cleanupWorklogEntries, createWorklogEntry } from './helpers/worklog';

/**
 * The grouped worklog packs several fields into one table cell and puts two
 * sticky bands over the grid. Both are layout the browser decides, so these cases
 * measure the rendered result: an editor that is narrower than its own value, or
 * a popup a sticky band paints over, is invisible to a jsdom test and was
 * reported by hand three times.
 */
test.describe('Worklog grouped view — editing in composite cells', () => {
  const useGroupedView = async (page: Page, sort: 'time' | 'context' = 'time'): Promise<void> => {
    await page.evaluate((chosen) => {
      window.localStorage.setItem('tt-worklog-view', 'grouped');
      window.localStorage.setItem('tt-worklog-sort', chosen);
    }, sort);
    await page.reload();
    await page.waitForSelector('table.tracking-table.is-grouped', { timeout: 10000 });
  };

  test.beforeEach(async ({ page }) => {
    await loginIsolated(page);
    await goToWorklogPage(page);
  });

  test.afterEach(async ({ page }) => {
    await page.evaluate(() => {
      window.localStorage.setItem('tt-worklog-view', 'flat');
      window.localStorage.setItem('tt-worklog-sort', 'time');
    });
    // The cleanup reads the grid, so put the flat view back on screen first.
    await page.goto('/ui/tracking');
    await page.locator('table.tracking-table').first().waitFor({ timeout: 10000 }).catch(() => undefined);
    await cleanupWorklogEntries(page);
  });

  test('the time editor is wide enough for the value it holds', async ({ page }) => {
    await createWorklogEntry(page);
    await useGroupedView(page);

    await page.locator('td[data-col-key="time"] .worklog-part').first().dblclick();
    const editor = page.locator('td[data-col-key="time"] input.inline-editor').first();
    await expect(editor).toBeVisible();

    // The editor used to inherit the cell's x-padding inside a box exactly as wide
    // as the value, so "13:00" rendered as ".3:00" — scrollWidth is what says so.
    const fits = await editor.evaluate((el: HTMLInputElement) => el.scrollWidth <= el.clientWidth + 1);
    expect(fits).toBe(true);
  });

  test('Tab moves to the second field of the same cell', async ({ page }) => {
    await createWorklogEntry(page);
    await useGroupedView(page);

    await page.locator('td[data-col-key="time"] .worklog-part').first().dblclick();
    const start = page.locator('td[data-col-key="time"] input.inline-editor').first();
    await expect(start).toBeFocused();

    await page.keyboard.press('Tab');

    // Start and end share one cell: walking cells stepped over the end entirely.
    // Asserted on the VALUE rather than the label, because the e2e stack renders
    // German (see e2e/AGENTS.md) — createWorklogEntry books 00:00–00:15.
    const second = page.locator('td[data-col-key="time"] input.inline-editor').first();
    await expect(second).toBeVisible();
    await expect(second).toHaveValue('00:15');
  });

  test('a select popup opens above the sticky headers', async ({ page }) => {
    await createWorklogEntry(page);
    await useGroupedView(page);

    // The activity is the last part of the block cell.
    await page.locator('td[data-col-key="context"] .worklog-part').last().dblclick();
    const popup = page.locator('[data-chipselect-popup]');
    await expect(popup).toBeVisible();

    // The day heading is sticky with a stacking order of its own; the popup carried
    // none at all and was painted over.
    const ownTopmost = await popup.evaluate((el: HTMLElement) => {
      const box = (el.querySelector('.combobox-content') ?? el).getBoundingClientRect();
      const points = [0.15, 0.5, 0.85].map((fraction) => [box.left + box.width / 2, box.top + box.height * fraction]);

      return points.every(([x, y]) => {
        const hit = document.elementFromPoint(x, y);

        return hit !== null && el.contains(hit);
      });
    });
    expect(ownTopmost).toBe(true);
  });

  test('the date editor in a block first row stays visible under the day heading', async ({ page }) => {
    await createWorklogEntry(page);
    await useGroupedView(page, 'context');

    // Ordered by customer, the block column shows the day — and that first row sits
    // directly beneath the sticky heading, which used to cut the editor in half.
    await page.locator('td[data-col-key="context"] .worklog-part').first().dblclick();
    const editor = page.locator('td[data-col-key="context"] input.inline-editor').first();
    await expect(editor).toBeVisible();

    const visible = await editor.evaluate((el: HTMLElement) => {
      const box = el.getBoundingClientRect();
      const hit = document.elementFromPoint(box.left + 4, box.top + 3);

      return hit === el;
    });
    expect(visible).toBe(true);
  });

  test('the ticket editor of a new row is actually visible', async ({ page }) => {
    await useGroupedView(page);

    await page.getByRole('button', { name: /Add entry|Eintrag hinzufügen/i }).click();
    const editor = page.locator('tr.tracking-row.is-new input.inline-editor').first();
    await expect(editor).toBeVisible();

    // It fills its ghost, and an empty ghost is zero pixels high: the field was in
    // the row, focused, and invisible.
    const box = await editor.boundingBox();
    expect(box).not.toBeNull();
    expect(box!.height).toBeGreaterThan(10);
    expect(box!.width).toBeGreaterThan(10);
  });

  test('leaving the worklog and coming back keeps the page interactive', async ({ page }) => {
    const crashes: string[] = [];
    page.on('pageerror', (error) => crashes.push(String(error)));

    await useGroupedView(page);
    await page.locator('a.main-nav-link[data-nav="month"]').click();
    await page.waitForURL(/\/ui\/month/, { timeout: 10000 });
    await page.locator('a.main-nav-link[data-nav="tracking"]').click();
    await page.waitForSelector('table.tracking-table.is-grouped', { timeout: 10000 });

    // The grid's height is measured in an effect. Placed above the signal it reads,
    // it threw "Cannot access … before initialization" on the SECOND mount, which
    // aborted the route's render and left the whole app dead to clicks until a
    // reload — no error was visible on the page itself.
    expect(crashes).toEqual([]);
    await page.getByRole('button', { name: /Add entry|Eintrag hinzufügen/i }).click();
    await expect(page.locator('tr.tracking-row.is-new')).toBeVisible();
  });

  test('Tab cycles through every field of a new row', async ({ page }) => {
    await useGroupedView(page);

    await page.getByRole('button', { name: /Add entry|Eintrag hinzufügen/i }).click();
    await expect(page.locator('tr.tracking-row.is-new')).toBeVisible();

    const focusedField = (): Promise<string> =>
      page.evaluate(() => document.activeElement?.getAttribute('aria-label') ?? document.activeElement?.tagName ?? '');

    const walk: string[] = [];
    for (let step = 0; step < 8; step += 1) {
      const current = await focusedField();
      walk.push(current);
      await page.keyboard.press('Tab');
      // The next editor mounts and takes focus asynchronously (a select commits a
      // frame later); wait for focus to actually be somewhere else.
      await expect.poll(focusedField).not.toBe(current);
    }

    // The row opens on its ticket, which this layout places in the last editable
    // cell — Tab used to leave the table on the first press, so customer, project,
    // activity, start and end could not be reached at all. Every field takes its
    // turn now, and the walk closes back on the one it started from.
    expect(new Set(walk).size).toBeGreaterThanOrEqual(6);
    expect(await focusedField()).toBe(walk[0]);
  });

  test('Tab out of a relation stays in the cell it was picked in', async ({ page }) => {
    await useGroupedView(page);

    await page.getByRole('button', { name: /Add entry|Eintrag hinzufügen/i }).click();
    await expect(page.locator('tr.tracking-row.is-new')).toBeVisible();

    // Ticket → the first relation of the block cell.
    await page.keyboard.press('Tab');
    await expect(page.locator('input.combobox-input').first()).toBeFocused();
    await page.keyboard.press('ArrowDown');
    await page.keyboard.press('Tab');

    // A select commits from a body-portalled popup, after which the roving cell no
    // longer says where the user was: Tab skipped the rest of the block cell and
    // landed in the time cell.
    await expect
      .poll(() => page.evaluate(() => document.activeElement?.closest('td')?.getAttribute('data-col-key') ?? null))
      .toBe('context');
  });

  test('a new row is not saved before it can be booked', async ({ page }) => {
    await useGroupedView(page);

    const saves: number[] = [];
    page.on('response', (response) => {
      if (/\/tracking\/save$/.test(response.url())) {
        saves.push(response.status());
      }
    });

    await page.getByRole('button', { name: /Add entry|Eintrag hinzufügen/i }).click();
    await expect(page.locator('tr.tracking-row.is-new')).toBeVisible();

    // Tab from the ticket the row opens in reaches the project — inside the block
    // cell, which the cell-by-cell walk skipped — and picking it moves focus into a
    // body-portalled popup, i.e. out of the table. That flush posted the row while
    // customer and activity were still unset, and the server answered 422.
    await page.keyboard.press('Tab');
    await expect(page.locator('input.combobox-input').first()).toBeVisible();
    await page.locator('.combobox-content .combobox-item').first().click();
    // The commit closes the popup and moves the edit on; once the next editor is
    // up, any save this flow would have triggered has been issued.
    await expect(page.locator('[data-chipselect-popup]')).toBeHidden();
    await expect(page.locator('tr.tracking-row.is-new input.inline-editor')).toHaveCount(1);

    expect(saves).toEqual([]);
  });
});
