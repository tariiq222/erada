import { expect, type Page } from '@playwright/test';

export const adminFixture = {
  adminEmail: 'admin-e2e@example.test',
  twoFactorEmail: 'admin-2fa-e2e@example.test',
  regularEmail: 'regular-e2e@example.test',
  password: 'AdminE2E!Password123',
} as const;

export async function login(page: Page, email = adminFixture.adminEmail, returnTo = '/overview') {
  await page.goto(`/login?returnTo=${encodeURIComponent(returnTo)}`);
  await page.locator('#admin-email').fill(email);
  await page.locator('#admin-password').fill(adminFixture.password);
  await page.getByRole('button', { name: /login|دخول/i }).click();
}

export async function loginAsSuperAdmin(page: Page, returnTo = '/overview') {
  await login(page, adminFixture.adminEmail, returnTo);
  await expect(page).toHaveURL(new RegExp(`${returnTo.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}$`));
  await expect(page.getByTestId('admin-control-plane-shell')).toBeVisible();
}
