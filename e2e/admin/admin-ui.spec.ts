import { test, expect, Page } from '@playwright/test';
import { login } from '../helpers/auth';
import { waitForGrid } from '../helpers/grid';
import { goToAdminPage } from '../helpers/navigation';

/**
 * E2E tests for the Administration area.
 *
 * Administration lives in the SolidJS UI
 * (frontend/src/pages/Admin.tsx + components/AdminCrudShell.tsx), served at
 * `/ui/admin` and gated on ROLE_ADMIN. One generic CRUD shell drives all eight
 * entities via per-entity descriptors; list responses are row-wrapped and
 * save/delete go out as JSON (#[MapRequestPayload]). The component itself is
 * unit-tested (Admin.test.tsx, incl. axe); these specs cover routing/gating
 * and a real create→edit→delete round-trip against the backend.
 */

const ADD = /^(Hinzufügen|Add)$/i;
const SAVE = /^(Speichern|Save)$/i;
const EDIT = /^(Bearbeiten|Edit)$/i;
const DELETE = /^(Löschen|Delete)$/i;

function row(page: Page, name: string) {
  return page.locator('table.admin-table tbody tr').filter({ hasText: name });
}

test.describe('Administration gating', () => {
  test('redirects a non-admin away from /ui/admin', async ({ page }) => {
    // `developer` is a plain user (no ROLE_ADMIN); the route guard sends them
    // back to the month view instead of letting the admin endpoints 403.
    await login(page);
    await page.goto('/ui/admin');
    await page.waitForURL(/\/ui\/month/, { timeout: 15000 });
    await expect(page.locator('section.admin-page')).toHaveCount(0);
  });

  test('does not show the Administration nav link to a non-admin', async ({ page }) => {
    await login(page);
    await waitForGrid(page);
    await expect(page.locator('a.main-nav-link[data-nav="admin"]')).toHaveCount(0);
  });
});

test.describe('Administration UI', () => {
  test.beforeEach(async ({ page }) => {
    // i.myself is type PL → ROLE_ADMIN.
    await login(page, 'i.myself', 'myself123');
    await waitForGrid(page);
    await goToAdminPage(page);
  });

  test('shows the entity sub-nav and a list grid', async ({ page }) => {
    await expect(page.locator('nav.admin-subnav')).toBeVisible();
    await expect(page.locator('table.admin-table')).toBeVisible();
    // Customers is the default entity; its Name column header is present.
    await expect(page.locator('table.admin-table thead th').first()).toBeVisible();
  });

  test('switches the active entity via the sub-nav', async ({ page }) => {
    const usersButton = page.locator('button.admin-subnav-link').filter({ hasText: /^(Nutzer|Users)$/i }).first();
    await usersButton.click();
    await expect(usersButton).toHaveAttribute('aria-current', 'page');
    await expect(page.locator('table.admin-table')).toBeVisible();
  });

  test('creates, edits and deletes a customer', async ({ page }) => {
    const name = `E2ECustomer_${Date.now()}`;

    // Create. A customer must be global or have teams (server-enforced), so
    // mark it global to keep the fixture self-contained.
    await page.locator('.admin-crud-toolbar button.primary-button').filter({ hasText: ADD }).click();
    const form = page.locator('.modal form.stack-form');
    await expect(form).toBeVisible();
    await form.locator('.field input[type="text"]').first().fill(name);
    await form.locator('.field-check').filter({ hasText: /^Global$/ }).locator('input[type="checkbox"]').check();
    await form.locator('button[type="submit"]').filter({ hasText: SAVE }).click();
    await expect(page.locator('.modal')).toHaveCount(0);
    await expect(row(page, name)).toHaveCount(1);

    // Edit (rename). The action buttons are icon-only, so match the accessible
    // name (aria-label), not visible text.
    const renamed = `${name}_Renamed`;
    await row(page, name).getByRole('button', { name: EDIT }).click();
    const editForm = page.locator('.modal form.stack-form');
    await expect(editForm).toBeVisible();
    const nameInput = editForm.locator('.field input[type="text"]').first();
    await nameInput.fill(renamed);
    await editForm.locator('button[type="submit"]').filter({ hasText: SAVE }).click();
    await expect(page.locator('.modal')).toHaveCount(0);
    await expect(row(page, renamed)).toHaveCount(1);

    // Delete (native confirm)
    page.once('dialog', (dialog) => dialog.accept());
    await row(page, renamed).getByRole('button', { name: DELETE }).click();
    await expect(row(page, renamed)).toHaveCount(0);
  });
});
