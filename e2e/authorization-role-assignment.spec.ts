import { expect, test, type Page } from '@playwright/test';

/**
 * Canonical authorization assignment cutover.
 *
 * The live tests deliberately require explicit fixture identifiers. They mutate
 * authorization state and therefore must never guess users, roles, organizations,
 * or a capability probe from shared seed data. CI can enable them by provisioning
 * the variables documented below. The mocked browser tests always run and verify
 * that rejected writes do not leave optimistic/stale UI state behind.
 */

const adminEmail = process.env.E2E_AUTHZ_ADMIN_EMAIL ?? 'admin@admin.com';
const adminPassword = process.env.E2E_AUTHZ_ADMIN_PASSWORD ?? 'password';

async function login(page: Page, email: string, password: string): Promise<void> {
  await page.goto('/login');
  await page.locator('input[type="email"]').fill(email);
  await page.locator('input[type="password"]').fill(password);
  await page.locator('button[type="submit"]').click();
  await page.waitForURL('**/dashboard', { timeout: 10_000 });
}

function requiredLiveFixture(): {
  subjectId: number;
  roleId: number;
  organizationId: number;
  subjectEmail: string;
  subjectPassword: string;
  protectedPath: string;
} | null {
  const values = {
    subjectId: Number(process.env.E2E_AUTHZ_SUBJECT_USER_ID),
    roleId: Number(process.env.E2E_AUTHZ_ROLE_ID),
    organizationId: Number(process.env.E2E_AUTHZ_ORGANIZATION_ID),
    subjectEmail: process.env.E2E_AUTHZ_SUBJECT_EMAIL ?? '',
    subjectPassword: process.env.E2E_AUTHZ_SUBJECT_PASSWORD ?? '',
    protectedPath: process.env.E2E_AUTHZ_PROTECTED_API_PATH ?? '',
  };

  return values.subjectId > 0 && values.roleId > 0 && values.organizationId > 0
    && values.subjectEmail !== '' && values.subjectPassword !== ''
    && values.protectedPath.startsWith('/api/')
    ? values
    : null;
}

test.describe.serial('Canonical role assignment — live vertical path', () => {
  const fixture = requiredLiveFixture();

  test.skip(
    fixture === null,
    'Provision E2E_AUTHZ_SUBJECT_USER_ID, E2E_AUTHZ_ROLE_ID, '
      + 'E2E_AUTHZ_ORGANIZATION_ID, E2E_AUTHZ_SUBJECT_EMAIL, '
      + 'E2E_AUTHZ_SUBJECT_PASSWORD and E2E_AUTHZ_PROTECTED_API_PATH. The role '
      + 'must grant that protected endpoint and the subject must start without it.',
  );

  test('canonical response grants access, then clear revokes it and returns 403', async ({
    browser,
  }) => {
    test.skip(fixture === null);
    if (fixture === null) return;

    const adminContext = await browser.newContext();
    const subjectContext = await browser.newContext();
    const adminPage = await adminContext.newPage();
    const subjectPage = await subjectContext.newPage();

    await login(adminPage, adminEmail, adminPassword);

    try {
      const assignment = await adminPage.request.post('/api/roles/assign', {
        headers: { 'X-Skip-Csrf': '1', 'X-Idempotency-Key': `e2e-authz-grant-${Date.now()}` },
        data: {
          user_id: fixture.subjectId,
          replace_all: true,
          assignments: [{
            role_id: fixture.roleId,
            scope_type: 'organization',
            scope_id: fixture.organizationId,
            inherit_to_children: false,
          }],
        },
      });

      expect(assignment.status()).toBe(200);
      const assignedBody = await assignment.json();
      expect(assignedBody.data.user_id).toBe(fixture.subjectId);
      expect(assignedBody.data.assignments).toEqual(expect.arrayContaining([
        expect.objectContaining({
          role_id: fixture.roleId,
          scope_type: 'organization',
          scope_id: fixture.organizationId,
          source: 'manual',
        }),
      ]));

      await login(subjectPage, fixture.subjectEmail, fixture.subjectPassword);
      const allowed = await subjectPage.request.get(fixture.protectedPath, {
        headers: { Accept: 'application/json' },
      });
      expect(allowed.status()).toBeLessThan(400);

      const clear = await adminPage.request.post('/api/roles/assign', {
        headers: { 'X-Skip-Csrf': '1', 'X-Idempotency-Key': `e2e-authz-clear-${Date.now()}` },
        data: { user_id: fixture.subjectId, replace_all: true, assignments: [] },
      });
      expect(clear.status()).toBe(200);
      expect((await clear.json()).data.assignments).toEqual([]);

      const denied = await subjectPage.request.get(fixture.protectedPath, {
        headers: { Accept: 'application/json' },
      });
      expect(denied.status()).toBe(403);
    } finally {
      // Clearing is idempotent and prevents a failed assertion from leaking access.
      await adminPage.request.post('/api/roles/assign', {
        headers: { 'X-Skip-Csrf': '1', 'X-Idempotency-Key': `e2e-authz-cleanup-${Date.now()}` },
        data: { user_id: fixture.subjectId, replace_all: true, assignments: [] },
      }).catch(() => undefined);
      await subjectContext.close();
      await adminContext.close();
    }
  });
});

test.describe('Canonical role assignment — cross-organization boundary', () => {
  const callerEmail = process.env.E2E_AUTHZ_ORG_ADMIN_EMAIL ?? '';
  const callerPassword = process.env.E2E_AUTHZ_ORG_ADMIN_PASSWORD ?? '';
  const foreignSubjectId = Number(process.env.E2E_AUTHZ_FOREIGN_SUBJECT_USER_ID);
  const foreignRoleId = Number(process.env.E2E_AUTHZ_FOREIGN_ROLE_ID);
  const foreignOrganizationId = Number(process.env.E2E_AUTHZ_FOREIGN_ORGANIZATION_ID);
  const ready = callerEmail !== '' && callerPassword !== ''
    && foreignSubjectId > 0 && foreignRoleId > 0 && foreignOrganizationId > 0;

  test.skip(
    !ready,
    'Provision an organization-admin credential and foreign subject/role/org IDs '
      + 'through E2E_AUTHZ_ORG_ADMIN_*, E2E_AUTHZ_FOREIGN_SUBJECT_USER_ID, '
      + 'E2E_AUTHZ_FOREIGN_ROLE_ID and E2E_AUTHZ_FOREIGN_ORGANIZATION_ID.',
  );

  test('organization administrator cannot assign a role in another organization', async ({ page }) => {
    await login(page, callerEmail, callerPassword);
    const response = await page.request.post('/api/roles/assign', {
      headers: { 'X-Skip-Csrf': '1', 'X-Idempotency-Key': `e2e-authz-cross-org-${Date.now()}` },
      data: {
        user_id: foreignSubjectId,
        replace_all: true,
        assignments: [{
          role_id: foreignRoleId,
          scope_type: 'organization',
          scope_id: foreignOrganizationId,
          inherit_to_children: false,
        }],
      },
    });

    expect(response.status()).toBe(403);
  });
});

test.describe('Canonical assignment errors do not leave stale user-form state', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, adminEmail, adminPassword);
  });

  for (const scenario of [
    { status: 403, message: 'ليس لديك صلاحية تنفيذ هذا الإجراء' },
    { status: 409, message: 'يتعارض الطلب مع تفويض موجود' },
    { status: 422, message: 'بيانات الإسناد غير صالحة', errors: { assignments: ['بيانات الإسناد غير صالحة'] } },
  ]) {
    test(`${scenario.status} keeps the submitted form visible and unsaved`, async ({ page }) => {
      await page.route('**/api/users', async (route) => {
        if (route.request().method() !== 'POST') {
          await route.continue();
          return;
        }

        await route.fulfill({
          status: scenario.status,
          contentType: 'application/json',
          body: JSON.stringify({ message: scenario.message, errors: scenario.errors }),
        });
      });

      await page.goto('/users/create');
      const email = `e2e-authz-rejected-${scenario.status}@example.com`;
      await page.locator('input[placeholder="example@domain.com"]').fill(email);
      await page.locator('input[placeholder*="أدخل اسم"]').fill('مستخدم إسناد مرفوض');
      await page.locator('input[placeholder*="أدخل كلمة المرور"]').fill('Test1234!');
      await page.locator('input[placeholder*="أعد إدخال"]').fill('Test1234!');
      await page.locator('button:has-text("إنشاء مستخدم")').click();

      await expect(page).toHaveURL(/\/users\/create$/);
      await expect(page.locator('input[placeholder="example@domain.com"]')).toHaveValue(email);
      await expect(page.locator('[aria-live="polite"]')).toContainText(scenario.message);
      await expect(page.locator('[aria-live="polite"]')).not.toContainText('تم إنشاء المستخدم');
    });
  }
});
