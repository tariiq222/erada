import { expect, test } from '@playwright/test';
import { loginAsSuperAdmin } from './helpers/admin-auth';

test('creates, persists, updates, and deletes a uniquely coded organization', async ({ page }) => {
  const suffix = `${Date.now()}-${test.info().workerIndex}`;
  const code = `E2E-ORG-${suffix}`;
  const initialName = `E2E Organization ${suffix}`;
  const updatedName = `${initialName} Updated`;

  await loginAsSuperAdmin(page, '/organizations/new');

  try {
    await page.getByLabel(/اسم الجهة|organization name|name/i).first().fill(initialName);
    await page.getByLabel(/رمز الجهة|organization code|code/i).first().fill(code);
    const created = page.waitForResponse((response) =>
      response.url().endsWith('/api/admin/organizations') && response.request().method() === 'POST',
    );
    await page.getByRole('button', { name: /create|إنشاء/i }).click();
    expect((await created).status()).toBe(201);
    await expect(page).toHaveURL(/\/organizations$/);

    await page.locator('input[placeholder]').fill(code);
    await page.getByRole('button', { name: /search|بحث/i }).click();
    const row = page.locator('tbody tr').filter({ hasText: code });
    await expect(row).toContainText(initialName);

    await row.locator('a[href$="/edit"]').click();
    await page.getByLabel(/اسم الجهة|organization name|name/i).first().fill(updatedName);
    const updated = page.waitForResponse((response) =>
      response.url().includes('/api/admin/organizations/') && response.request().method() === 'PUT',
    );
    await page.getByRole('button', { name: /save|حفظ/i }).click();
    expect((await updated).status()).toBe(200);

    await page.reload();
    await page.locator('input[placeholder]').fill(code);
    await page.getByRole('button', { name: /search|بحث/i }).click();
    const updatedRow = page.locator('tbody tr').filter({ hasText: code });
    await expect(updatedRow).toContainText(updatedName);

    page.once('dialog', (dialog) => dialog.accept());
    const deleted = page.waitForResponse((response) =>
      response.url().includes('/api/admin/organizations/') && response.request().method() === 'DELETE',
    );
    await updatedRow.getByRole('button', { name: /delete|حذف/i }).click();
    expect((await deleted).status()).toBe(200);
    await expect(page.locator('tbody tr').filter({ hasText: code })).toHaveCount(0);
  } finally {
    // Normal-path deletion is the cleanup assertion. If an earlier assertion
    // fails, a clean migrate:fresh before the next run removes the unique row.
  }
});
