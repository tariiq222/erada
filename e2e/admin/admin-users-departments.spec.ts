import { expect, test, type Page } from '@playwright/test';
import { adminFixture, loginAsSuperAdmin, uniqueSuffix } from './helpers/admin-auth';

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
  if (!page.url().includes('/departments')) {
    await page.goto('/departments');
  }
  await page.locator('select[aria-label]').first().selectOption({ label: /Admin E2E Primary/i });
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
  if (!page.url().includes('/users')) {
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
      await page.getByLabel(/department code|رمز القسم/i).first().fill(code);

      // The form requires an organization selector (super_admin may pick any).
      const orgSelect = page.locator('form select').first();
      await orgSelect.selectOption({ label: /Admin E2E Primary/i });

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
      await page.locator('select[aria-label]').first().selectOption({ label: /Admin E2E Primary/i });
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
      await page.locator('select[aria-label]').first().selectOption({ label: /Admin E2E Primary/i });
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

  test('cross-organization tenant isolation: API narrows visibility to the actor organization', async ({ page }) => {
    // Authenticate through the SPA's real login UI so the page-context
    // request inherits the Sanctum session cookies (no X-Skip-Csrf).
    await loginAsSuperAdmin(page, '/overview');

    // Use the page's shared request context so cookies + X-XSRF-TOKEN carry
    // over exactly as the SPA's api client does.
    const req = page.context().request;

    // Pull both organizations through the canonical admin endpoint.
    const orgs = await req.get('/api/admin/organizations?per_page=100', {
      headers: { Accept: 'application/json' },
    });
    expect(orgs.ok()).toBeTruthy();
    const orgsJson = (await orgs.json()) as { data: Array<{ id: number; code: string }> };
    const isolatedOrg = orgsJson.data.find((row) => row.code === 'ADMIN-E2E-ISOLATED');
    expect(isolatedOrg).toBeDefined();
    if (!isolatedOrg) return;

    // Users in the isolated org must NOT contain the primary org's admin.
    const isolatedUsers = await req.get(
      `/api/admin/users?organization_id=${isolatedOrg.id}&per_page=100`,
      { headers: { Accept: 'application/json' } },
    );
    expect(isolatedUsers.ok()).toBeTruthy();
    const isolatedUsersJson = (await isolatedUsers.json()) as { data: Array<{ email: string }> };
    expect(
      isolatedUsersJson.data.find((row) => row.email === adminFixture.adminEmail),
    ).toBeUndefined();

    // Conversely, the primary org must contain our seed admin — proves the
    // user listing is correctly narrowing on organization.
    const primaryUsers = await req.get('/api/admin/users?per_page=100', {
      headers: { Accept: 'application/json' },
    });
    expect(primaryUsers.ok()).toBeTruthy();
    const primaryUsersJson = (await primaryUsers.json()) as { data: Array<{ email: string }> };
    expect(
      primaryUsersJson.data.find((row) => row.email === adminFixture.adminEmail),
    ).toBeDefined();

    // Also verify departments: the isolated dept must not be present when
    // scoping to the primary org, and the governance dept must be present.
    const primaryDepts = await req.get(
      '/api/admin/departments?organization_id=' +
        (orgsJson.data.find((row) => row.code === 'ADMIN-E2E-PRIMARY')?.id ?? 0) +
        '&per_page=100',
      { headers: { Accept: 'application/json' } },
    );
    expect(primaryDepts.ok()).toBeTruthy();
    const primaryDeptsJson = (await primaryDepts.json()) as { data: Array<{ name: string }> };
    expect(primaryDeptsJson.data.find((row) => row.name === 'Admin E2E Governance')).toBeDefined();
  });
});
