import { expect, test, type Page } from '@playwright/test';
import { adminFixture, loginAsSuperAdmin, uniqueSuffix } from './helpers/admin-auth';

const INCIDENT_PREFIX = 'E2E-INC';

function rowFor(page: Page, name: string) {
  return page.locator('tbody tr').filter({ hasText: name });
}

/**
 * UI-based cleanup helper — uses the live SPA cookie + CSRF flow. If a
 * leftover incident type is still present after the test (because an earlier
 * assertion failed before the delete step), this navigates to the listing,
 * finds the row, accepts the confirm dialog, and waits for the real DELETE.
 */
async function deleteIncidentType(page: Page, name: string): Promise<void> {
  if (!/\/incident-types(?:\?|$)/.test(page.url())) {
    await page.goto('/incident-types');
  }
  const row = rowFor(page, name);
  if ((await row.count()) === 0) return;
  const confirm = page.once('dialog', (dialog) => dialog.accept());
  const removed = page.waitForResponse(
    (response) =>
      response.url().includes('/api/admin/incident-types/') &&
      response.request().method() === 'DELETE',
  );
  await row.getByRole('button', { name: /delete|حذف/i }).click();
  await confirm;
  await removed;
}

test.describe('admin access, activity, incident surface flows', () => {
  test('renders the access page with the seeded super-admin user and opens access summary', async ({ page }) => {
    await loginAsSuperAdmin(page, '/access');
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();

    // Real super_admin from the seeder must appear — proves /api/admin/users?per_page=100
    // hydrated with real data, not a stub.
    await expect(
      page.getByRole('row', { name: new RegExp(adminFixture.adminEmail) }),
    ).toBeVisible();

    const summary = page.waitForResponse(
      (response) =>
        response.url().includes('/api/admin/scoped-roles/user/') &&
        response.url().includes('/access-summary') &&
        response.request().method() === 'GET',
    );
    await page
      .getByRole('row', { name: new RegExp(adminFixture.adminEmail) })
      .getByRole('button', { name: /^view access$|^عرض الصلاحيات$/i })
      .click();
    expect((await summary).status()).toBe(200);
    // The summary card appears only after the real network round-trip.
    await expect(page.getByRole('heading', { level: 2 })).toBeVisible();
  });

  test('renders the seeded activity logs and applies a real action filter', async ({ page }) => {
    await loginAsSuperAdmin(page, '/activity-logs');
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();

    // Filter by an action the page exposes; the canonical API narrows the
    // result set in real-time. login() runs the SPA against the same backend,
    // so a row with action="login" is written by the time we open this page.
    const filter = page.waitForResponse(
      (response) =>
        response.url().includes('/api/admin/activity-logs') &&
        response.request().method() === 'GET' &&
        response.url().includes('action='),
    );
    // admin.activityLogs.fields.action aria-label = "Action" / "الإجراء"
    await page.locator('select[aria-label]').first().selectOption('login');
    await page.getByRole('button', { name: /search|بحث/i }).click();
    expect((await filter).status()).toBe(200);
    // The login row from the helper must appear — proves a real row exists
    // for the action we filtered by and the backend narrowed correctly.
    const dataRows = page.locator('tbody tr');
    await expect(dataRows.first()).toBeVisible();
    await expect(page.getByText(/no activity logs found|لا توجد سجلات/i)).toHaveCount(0);
  });

  test('renders the governance rules CRUD surface for the actor organization', async ({ page }) => {
    await loginAsSuperAdmin(page, '/access/governance');
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    // Admin E2E Governance department must be selectable in the dropdown
    // (real API call, real department list).
    const select = page.locator('select[aria-label]').first();
    await expect(select).toBeVisible();
    const options = await select.locator('option').allTextContents();
    expect(options.some((label) => label.includes('Admin E2E Governance'))).toBe(true);
  });

  test('creates and deterministically deletes a uniquely named incident type', async ({ page }) => {
    const name = `${INCIDENT_PREFIX}-${uniqueSuffix('TYPE')}`;
    const nameAr = `${INCIDENT_PREFIX} نوع`;
    let created = false;

    try {
      await loginAsSuperAdmin(page, '/incident-types');

      const createdResponse = page.waitForResponse(
        (response) =>
          response.url().endsWith('/api/admin/incident-types') &&
          response.request().method() === 'POST',
      );
      // ovr.add_category = "Add Type" (en) / "إضافة نوع" (ar)
      await page.getByRole('button', { name: /^add type$|^إضافة نوع$/i }).click();
      // ovr.category_name = "Name (English)" / "الاسم (إنجليزي)"
      await page.getByLabel(/name \(english\)|الاسم \(إنجليزي\)/i).fill(name);
      // ovr.category_name_ar = "Name (Arabic)" / "الاسم (عربي)"
      await page.getByLabel(/name \(arabic\)|الاسم \(عربي\)/i).fill(nameAr);
      // common.save = "Save" / "حفظ" — scoped to the open form, not the page header
      await page.getByRole('button', { name: /^save$|^حفظ$/i }).click();
      expect((await createdResponse).status()).toBe(201);
      created = true;

      // Reload, confirm row is on the page (DB persistence).
      await page.reload();
      await expect(rowFor(page, name)).toHaveCount(1);

      // DELETE through the real UI + canonical DELETE.
      page.once('dialog', (dialog) => dialog.accept());
      const removed = page.waitForResponse(
        (response) =>
          response.url().includes('/api/admin/incident-types/') &&
          response.request().method() === 'DELETE',
      );
      await rowFor(page, name).getByRole('button', { name: /delete|حذف/i }).click();
      expect((await removed).status()).toBe(200);

      await page.reload();
      await expect(rowFor(page, name)).toHaveCount(0);
      created = false;
    } finally {
      if (created) {
        await deleteIncidentType(page, name);
      }
    }
  });
});
