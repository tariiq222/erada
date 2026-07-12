import { expect, test } from '@playwright/test';
import { adminFixture, login, loginAsSuperAdmin } from './helpers/admin-auth';

test.describe('independent admin authentication', () => {
  test('logs in on the admin origin and preserves a safe deep link across reload', async ({ page }) => {
    await loginAsSuperAdmin(page, '/audit/recent?page=2');
    await expect(page).toHaveURL(/127\.0\.0\.1:4174\/audit\/recent\?page=2$/);
    await page.reload();
    await expect(page.getByTestId('admin-control-plane-shell')).toBeVisible();
  });

  test('routes a confirmed 2FA user into the real challenge state', async ({ page }) => {
    await login(page, adminFixture.twoFactorEmail, '/security/alerts');
    await expect(page).toHaveURL(/\/verify-2fa$/);
    await expect(page.locator('input[autocomplete="one-time-code"]')).toHaveCount(6);
  });

  test('shows the forbidden surface to an authenticated non-super-admin', async ({ page }) => {
    await login(page, adminFixture.regularEmail);
    await expect(page).toHaveURL(/\/overview$/);
    await expect(page.getByTestId('admin-control-plane-shell')).toHaveCount(0);
    await expect(page.locator('main')).toContainText(/403|صلاحية|permission|access/i);
  });

  test('keeps unsafe return targets on the admin overview', async ({ page }) => {
    await login(page, adminFixture.adminEmail, '//evil.example.test/steal');
    await expect(page).toHaveURL(/\/overview$/);
  });

  test('opens compact navigation and navigates without leaving the admin origin', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await loginAsSuperAdmin(page);
    await page.getByRole('button', { name: /navigation|تنقل|القائمة|الشريط/i }).click();
    const navigation = page.getByTestId('admin-mobile-navigation');
    await expect(navigation).toBeVisible();
    await navigation.locator('a[href="/organizations"]').click();
    await expect(page).toHaveURL(/127\.0\.0\.1:4174\/organizations$/);
    await expect(navigation).toHaveCount(0);
  });
});
