import { expect, test, type Page } from '@playwright/test';
import { loginAsSuperAdmin, uniqueSuffix } from './helpers/admin-auth';

const PREFIX = 'E2E-ORG';

function rowFor(page: Page, code: string) {
  return page.locator('tbody tr').filter({ hasText: code });
}

/**
 * Deterministic cleanup contract: every code in this file is suffixed with a
 * timestamp + worker + random token. Cleanup runs from the test body using
 * the live admin SPA UI (which carries the SPA's CSRF + session cookies)
 * so the test stays inside the real Sanctum/CSRF flow — no X-Skip-Csrf,
 * no mocks, and no shared-test-database wipes.
 */
async function deleteOrganization(page: Page, code: string): Promise<void> {
  // Ensure we are on the organizations listing page so the row is rendered.
  if (!/\/organizations(?:\?|$)/.test(page.url())) {
    await page.goto('/organizations');
  }
  await page.locator('input[placeholder]').fill(code);
  await page.getByRole('button', { name: /search|بحث/i }).click();
  const row = rowFor(page, code);
  if ((await row.count()) === 0) return;
  const confirm = page.once('dialog', (dialog) => dialog.accept());
  const removed = page.waitForResponse(
    (response) =>
      response.url().includes('/api/admin/organizations/') &&
      response.request().method() === 'DELETE',
  );
  await row.getByRole('button', { name: /delete|حذف/i }).click();
  await confirm;
  await removed;
}

test('creates, persists, updates, reloads and deletes a uniquely coded organization end-to-end', async ({ page }) => {
  const code = `${PREFIX}-${uniqueSuffix('CRUD')}`;
  const initialName = `${code} Initial`;
  const updatedName = `${code} Updated`;

  let created = false;

  try {
    await loginAsSuperAdmin(page, '/organizations/new');

    // CREATE — exercises the real form + Laravel POST /api/admin/organizations.
    await page.getByLabel(/^الاسم$/).fill(initialName);
    await page.getByLabel(/^الكود$/).fill(code);
    const createdResponse = page.waitForResponse(
      (response) =>
        response.url().endsWith('/api/admin/organizations') &&
        response.request().method() === 'POST',
    );
    await page.getByRole('button', { name: /create|إنشاء/i }).click();
    expect((await createdResponse).status()).toBe(201);
    created = true;
    await expect(page).toHaveURL(/\/organizations$/);

    // PERSISTENCE — search the listing, the row is real.
    await page.locator('input[placeholder]').fill(code);
    await page.getByRole('button', { name: /search|بحث/i }).click();
    let row = rowFor(page, code);
    await expect(row).toHaveCount(1);
    await expect(row).toContainText(initialName);

    // UPDATE — via the edit form, real PUT, real backend validation.
    await row.locator('a[href$="/edit"]').click();
    await page.getByLabel(/^الاسم$/).fill(updatedName);
    const updatedResponse = page.waitForResponse(
      (response) =>
        response.url().includes('/api/admin/organizations/') &&
        response.request().method() === 'PUT',
    );
    await page.getByRole('button', { name: /save|حفظ/i }).click();
    expect((await updatedResponse).status()).toBe(200);

    // RELOAD — proves Laravel persisted the change.
    await page.reload();
    await page.locator('input[placeholder]').fill(code);
    await page.getByRole('button', { name: /search|بحث/i }).click();
    row = rowFor(page, code);
    await expect(row).toHaveCount(1);
    await expect(row).toContainText(updatedName);

    // DELETE — UI confirmation + real DELETE.
    page.once('dialog', (dialog) => dialog.accept());
    const deleted = page.waitForResponse(
      (response) =>
        response.url().includes('/api/admin/organizations/') &&
        response.request().method() === 'DELETE',
    );
    await row.getByRole('button', { name: /delete|حذف/i }).click();
    expect((await deleted).status()).toBe(200);
    await expect(rowFor(page, code)).toHaveCount(0);
    created = false; // deleted on normal path — no further cleanup needed
  } finally {
    if (created) {
      await deleteOrganization(page, code);
    }
  }
});
