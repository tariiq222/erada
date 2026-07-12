import { expect, test } from '@playwright/test';
import { loginAsSuperAdmin } from './helpers/admin-auth';

test.describe('admin governance surfaces', () => {
  test('renders the real overview and refreshes canonical data', async ({ page }) => {
    await loginAsSuperAdmin(page, '/overview');
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    await expect(page.getByTestId('admin-protected-page')).toContainText(/2FA/);

    const refreshed = page.waitForResponse((response) =>
      response.url().includes('/api/admin/overview') && response.request().method() === 'GET',
    );
    await page.getByRole('button', { name: /refresh|تحديث/i }).click();
    await expect((await refreshed).status()).toBe(200);
  });

  test('renders security alerts from Laravel without intercepting the API', async ({ page }) => {
    await loginAsSuperAdmin(page, '/security/alerts');
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    await expect(page.getByTestId('admin-protected-page')).toContainText(/الأمن|security/i);
  });

  test('paginates the seeded audit fixture through the canonical endpoint', async ({ page }) => {
    await loginAsSuperAdmin(page, '/audit/recent');
    await expect(page.getByTestId('audit-recent-row')).toHaveCount(50);
    await expect(page.getByTestId('audit-recent-row').filter({ hasText: 'Admin E2E audit row 55' })).toHaveCount(1);

    const secondPage = page.waitForResponse((response) =>
      response.url().includes('/api/admin/audit/recent?page=2') && response.status() === 200,
    );
    await page.getByRole('button', { name: /page 2|صفحة 2/i }).click();
    await secondPage;
    await expect(page.getByTestId('audit-recent-row').filter({ hasText: /Admin E2E audit row 0[1-9]/ }).first()).toBeVisible();
    await expect(page.getByRole('button', { name: /page 2|صفحة 2/i })).toHaveAttribute('aria-current', 'page');
  });
});
