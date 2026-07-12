import { expect, test, type Page } from '@playwright/test';
import { adminFixture, login, loginAsSuperAdmin, uniqueSuffix } from './helpers/admin-auth';

const DEPARTMENT_PREFIX = 'E2E-DEPT';
const USER_PREFIX = 'E2E-USER';

function rowFor(page: Page, text: string) {
  return page.locator('tbody tr').filter({ hasText: text });
}

/**
 * UI-based cleanup helpers — use the live SPA cookie + CSRF flow. If a
 * leftover row exists after the test body fails mid-flight, the cleanup
 * navigates to the listing, locates the row, accepts the confirm dialog,
 * and waits for the real DELETE through the canonical API.
 */
async function deleteDepartment(page: Page, name: string): Promise<void> {
  // Exact list route only — '/departments/new' must NOT satisfy this guard.
  if (!/\/departments(?:\?|$)/.test(page.url())) {
    await page.goto('/departments');
  }
  await page.locator('select[aria-label]').first().selectOption({ label: 'Admin E2E Primary' });
  const row = rowFor(page, name);
  if ((await row.count()) === 0) return;
  const confirm = page.once('dialog', (dialog) => dialog.accept());
  const removed = page.waitForResponse(
    (response) =>
      response.url().includes('/api/admin/departments/') &&
      response.request().method() === 'DELETE',
  );
  await row.getByRole('button', { name: /delete|حذف/i }).click();
  await confirm;
  await removed;
}

async function deleteUser(page: Page, email: string): Promise<void> {
  // Exact list route only — '/users/new' must NOT satisfy this guard.
  if (!/\/users(?:\?|$)/.test(page.url())) {
    await page.goto('/users');
  }
  const search = page.locator('input[placeholder]');
  if ((await search.count()) > 0) {
    await search.fill(email);
    await page.getByRole('button', { name: /search|بحث/i }).click();
  }
  const row = rowFor(page, email);
  if ((await row.count()) === 0) return;
  const confirm = page.once('dialog', (dialog) => dialog.accept());
  const removed = page.waitForResponse(
    (response) =>
      response.url().includes('/api/admin/users/') &&
      response.request().method() === 'DELETE',
  );
  await row.getByRole('button', { name: /delete|حذف/i }).click();
  await confirm;
  await removed;
}

test.describe('admin users, departments, and cross-organization flow', () => {
  test('renders users listing with the seeded regular user', async ({ page }) => {
    await loginAsSuperAdmin(page, '/users');
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();

    // Seeded regular user email must appear — proves /api/admin/users worked.
    await expect(
      page.locator('tbody tr').filter({ hasText: adminFixture.regularEmail }).first(),
    ).toBeVisible();
  });

  test('renders departments scoped to the actor organization', async ({ page }) => {
    await loginAsSuperAdmin(page, '/departments');
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();

    // Seeded Admin E2E Governance must appear in the table.
    await expect(
      page.locator('tbody tr').filter({ hasText: 'Admin E2E Governance' }).first(),
    ).toBeVisible();
  });

  test('creates, persists, and deterministically deletes a uniquely named department', async ({ page }) => {
    const code = `${DEPARTMENT_PREFIX}-${uniqueSuffix('CODE')}`;
    const name = `${DEPARTMENT_PREFIX}-${uniqueSuffix('NAME')}`;
    let created = false;

    try {
      await loginAsSuperAdmin(page, '/departments/new');

      await page.getByLabel(/department name|اسم القسم/i).first().fill(name);
      await page.getByLabel(/department code|كود القسم/i).first().fill(code);

      // The form requires an organization selector (super_admin may pick any).
      const orgSelect = page.locator('form select').first();
      await orgSelect.selectOption({ label: 'Admin E2E Primary' });

      const createdResponse = page.waitForResponse(
        (response) =>
          response.url().endsWith('/api/admin/departments') &&
          response.request().method() === 'POST',
      );
      await page.getByRole('button', { name: /create|إنشاء/i }).click();
      expect((await createdResponse).status()).toBe(201);
      created = true;

      // Persistence: navigate back to the list and confirm the row is there.
      await expect(page).toHaveURL(/\/departments(\?|$)/);
      await page.reload();
      await page.locator('select[aria-label]').first().selectOption({ label: 'Admin E2E Primary' });
      await expect(rowFor(page, name)).toHaveCount(1);

      // DELETE through the real UI confirmation + canonical DELETE.
      page.once('dialog', (dialog) => dialog.accept());
      const removed = page.waitForResponse(
        (response) =>
          response.url().includes('/api/admin/departments/') &&
          response.request().method() === 'DELETE',
      );
      await rowFor(page, name).getByRole('button', { name: /delete|حذف/i }).click();
      expect((await removed).status()).toBe(200);

      await page.reload();
      await page.locator('select[aria-label]').first().selectOption({ label: 'Admin E2E Primary' });
      await expect(rowFor(page, name)).toHaveCount(0);
      created = false;
    } finally {
      if (created) {
        await deleteDepartment(page, name);
      }
    }
  });

  test('creates, persists, and deterministically deletes a unique user', async ({ page }) => {
    const email = `${USER_PREFIX}-${uniqueSuffix('EMAIL').toLowerCase()}@example.test`;
    const displayName = `E2E User ${uniqueSuffix('NAME')}`;
    let created = false;

    try {
      await loginAsSuperAdmin(page, '/users/new');

      await page.getByLabel(/name|اسم/i).first().fill(displayName);
      await page.getByLabel(/email|البريد/i).first().fill(email);
      await page.getByLabel(/password|كلمة المرور/i).first().fill('E2EUser!Password123');

      const createdResponse = page.waitForResponse(
        (response) =>
          response.url().endsWith('/api/admin/users') &&
          response.request().method() === 'POST',
      );
      await page.getByRole('button', { name: /create|إنشاء/i }).click();
      expect((await createdResponse).status()).toBe(201);
      created = true;

      await expect(page).toHaveURL(/\/users$/);

      // Persistence through reload + filter.
      await page.reload();
      const search = page.locator('input[placeholder]');
      await expect(search).toBeVisible();
      await search.fill(email);
      await page.getByRole('button', { name: /search|بحث/i }).click();
      await expect(rowFor(page, email)).toHaveCount(1);

      // DELETE through the real UI confirmation + canonical DELETE.
      page.once('dialog', (dialog) => dialog.accept());
      const removed = page.waitForResponse(
        (response) =>
          response.url().includes('/api/admin/users/') &&
          response.request().method() === 'DELETE',
      );
      await rowFor(page, email).getByRole('button', { name: /delete|حذف/i }).click();
      expect((await removed).status()).toBe(200);

      await page.reload();
      await search.fill(email);
      await page.getByRole('button', { name: /search|بحث/i }).click();
      await expect(rowFor(page, email)).toHaveCount(0);
      created = false;
    } finally {
      if (created) {
        await deleteUser(page, email);
      }
    }
  });

  test('cross-organization tenant isolation: non-super users are denied the foreign admin surface', async ({ page, browser }) => {
    // First discover a real foreign organization id through the privileged SPA.
    // The later request deliberately tries to select it as a non-super user.
    await loginAsSuperAdmin(page, '/overview');
    const req = page.context().request;
    const orgs = await req.get('/api/admin/organizations?per_page=100', {
      headers: { Accept: 'application/json' },
    });
    expect(orgs.ok()).toBeTruthy();
    const orgsJson = (await orgs.json()) as { data: Array<{ id: number; code: string }> };
    const primaryOrg = orgsJson.data.find((row) => row.code === 'ADMIN-E2E-PRIMARY');
    expect(primaryOrg).toBeDefined();
    if (!primaryOrg) return;

    // A distinct browser context avoids sharing the super-admin session or its
    // login-rate-limit bucket with the non-super actor under test.
    const isolatedContext = await browser.newContext({ baseURL: 'http://127.0.0.1:4174' });
    try {
      const isolatedPage = await isolatedContext.newPage();
      await login(isolatedPage, adminFixture.isolatedRegularEmail);
      await expect(isolatedPage).toHaveURL(/\/overview$/);

      // The independent control plane is super-admin only. A tenant-scoped
      // admin cannot use a forged foreign organization filter to enter it.
      const departments = await isolatedContext.request.get('/api/admin/departments?per_page=100', {
        headers: {
          Accept: 'application/json',
          'X-Organization-Id': String(primaryOrg.id),
        },
      });
      expect(departments.status(), await departments.text()).toBe(403);
    } finally {
      await isolatedContext.close();
    }
  });
});
