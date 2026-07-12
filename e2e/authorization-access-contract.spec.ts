import { expect, test, type Page } from '@playwright/test';

/**
 * Browser contract for the canonical authorization read path.
 *
 * This suite mutates authorization state and switches organizations. It is
 * intentionally disabled unless every fixture value is supplied: guessing a
 * seeded user, role, route, or navigation label would turn a missing fixture
 * into a misleading green authorization test.
 */

type AuthzFixture = {
  adminEmail: string;
  adminPassword: string;
  subjectId: number;
  subjectEmail: string;
  subjectPassword: string;
  roleId: number;
  organizationId: number;
  otherOrganizationId: number;
  capability: string;
  protectedApiPath: string;
  protectedUiPath: string;
  navigationLabel: string;
};

function liveFixture(): AuthzFixture | null {
  const fixture: AuthzFixture = {
    adminEmail: process.env.E2E_AUTHZ_ADMIN_EMAIL ?? '',
    adminPassword: process.env.E2E_AUTHZ_ADMIN_PASSWORD ?? '',
    subjectId: Number(process.env.E2E_AUTHZ_SUBJECT_USER_ID),
    subjectEmail: process.env.E2E_AUTHZ_SUBJECT_EMAIL ?? '',
    subjectPassword: process.env.E2E_AUTHZ_SUBJECT_PASSWORD ?? '',
    roleId: Number(process.env.E2E_AUTHZ_ROLE_ID),
    organizationId: Number(process.env.E2E_AUTHZ_ORGANIZATION_ID),
    otherOrganizationId: Number(process.env.E2E_AUTHZ_OTHER_ORGANIZATION_ID),
    capability: process.env.E2E_AUTHZ_CAPABILITY ?? '',
    protectedApiPath: process.env.E2E_AUTHZ_PROTECTED_API_PATH ?? '',
    protectedUiPath: process.env.E2E_AUTHZ_PROTECTED_UI_PATH ?? '',
    navigationLabel: process.env.E2E_AUTHZ_NAVIGATION_LABEL ?? '',
  };

  return fixture.adminEmail !== ''
    && fixture.adminPassword !== ''
    && fixture.subjectId > 0
    && fixture.subjectEmail !== ''
    && fixture.subjectPassword !== ''
    && fixture.roleId > 0
    && fixture.organizationId > 0
    && fixture.otherOrganizationId > 0
    && fixture.otherOrganizationId !== fixture.organizationId
    && /^[a-z_]+\.[a-z_]+$/.test(fixture.capability)
    && fixture.protectedApiPath.startsWith('/api/')
    && fixture.protectedUiPath.startsWith('/')
    && !fixture.protectedUiPath.startsWith('/api/')
    && fixture.navigationLabel !== ''
    ? fixture
    : null;
}

async function login(page: Page, email: string, password: string): Promise<void> {
  await page.goto('/login');
  await page.locator('input[type="email"]').fill(email);
  await page.locator('input[type="password"]').fill(password);
  await page.locator('button[type="submit"]').click();
  await page.waitForURL('**/dashboard', { timeout: 10_000 });
}

async function replaceAssignments(page: Page, fixture: AuthzFixture, assignments: object[]): Promise<void> {
  const response = await page.request.post('/api/roles/assign', {
    headers: {
      'X-Skip-Csrf': '1',
      'X-Idempotency-Key': `e2e-access-contract-${Date.now()}-${Math.random()}`,
    },
    data: { user_id: fixture.subjectId, replace_all: true, assignments },
  });

  expect(response.status(), await response.text()).toBe(200);
}

async function grant(page: Page, fixture: AuthzFixture): Promise<void> {
  await replaceAssignments(page, fixture, [{
    role_id: fixture.roleId,
    scope_type: 'organization',
    scope_id: fixture.organizationId,
    inherit_to_children: false,
  }]);
}

async function revoke(page: Page, fixture: AuthzFixture): Promise<void> {
  await replaceAssignments(page, fixture, []);
}

test.describe.serial('Canonical authorization access contract — live browser path', () => {
  const fixture = liveFixture();

  test.skip(
    fixture === null,
    'Provision all E2E_AUTHZ_* access-contract fixtures: admin and subject credentials, '
      + 'SUBJECT_USER_ID, ROLE_ID, ORGANIZATION_ID, OTHER_ORGANIZATION_ID, CAPABILITY, '
      + 'PROTECTED_API_PATH, PROTECTED_UI_PATH, and NAVIGATION_LABEL. The subject must '
      + 'belong to both organizations and the role must grant CAPABILITY only in ORGANIZATION_ID.',
  );

  test('canonical payload drives API access, route guard, and navigation visibility', async ({ browser }) => {
    test.skip(fixture === null);
    if (fixture === null) return;

    const adminContext = await browser.newContext();
    const subjectContext = await browser.newContext();
    const adminPage = await adminContext.newPage();
    const subjectPage = await subjectContext.newPage();

    await login(adminPage, fixture.adminEmail, fixture.adminPassword);

    try {
      await grant(adminPage, fixture);
      await login(subjectPage, fixture.subjectEmail, fixture.subjectPassword);

      const me = await subjectPage.request.get('/api/auth/me', { headers: { Accept: 'application/json' } });
      expect(me.status()).toBe(200);
      const body = await me.json();
      const user = body.user ?? body.data?.user ?? body.data;
      const [moduleName, action] = fixture.capability.split('.');

      expect(user.capabilities).toContain(fixture.capability);
      expect(user.access?.[moduleName]?.[action]).toBe(true);
      expect(user).not.toHaveProperty('permissions');

      const allowed = await subjectPage.request.get(fixture.protectedApiPath, {
        headers: { Accept: 'application/json' },
      });
      expect(allowed.status()).toBeLessThan(400);

      await subjectPage.goto('/dashboard');
      await expect(subjectPage.getByRole('link', { name: fixture.navigationLabel, exact: true })).toBeVisible();

      await subjectPage.goto(fixture.protectedUiPath);
      await expect(subjectPage).toHaveURL(new RegExp(`${fixture.protectedUiPath.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}(?:[?#/]|$)`));
    } finally {
      await revoke(adminPage, fixture).catch(() => undefined);
      await subjectContext.close();
      await adminContext.close();
    }
  });

  test('revocation refresh removes stale navigation and denies a direct URL', async ({ browser }) => {
    test.skip(fixture === null);
    if (fixture === null) return;

    const adminContext = await browser.newContext();
    const subjectContext = await browser.newContext();
    const adminPage = await adminContext.newPage();
    const subjectPage = await subjectContext.newPage();

    await login(adminPage, fixture.adminEmail, fixture.adminPassword);

    try {
      await grant(adminPage, fixture);
      await login(subjectPage, fixture.subjectEmail, fixture.subjectPassword);
      await expect(subjectPage.getByRole('link', { name: fixture.navigationLabel, exact: true })).toBeVisible();

      await revoke(adminPage, fixture);
      await subjectPage.reload();
      await expect(subjectPage.getByRole('link', { name: fixture.navigationLabel, exact: true })).toHaveCount(0);

      await subjectPage.goto(fixture.protectedUiPath);
      await expect(subjectPage).toHaveURL(/\/dashboard(?:[?#/]|$)/);

      const denied = await subjectPage.request.get(fixture.protectedApiPath, {
        headers: { Accept: 'application/json' },
      });
      expect(denied.status()).toBe(403);
    } finally {
      await revoke(adminPage, fixture).catch(() => undefined);
      await subjectContext.close();
      await adminContext.close();
    }
  });

  test('organization switch cannot retain access from the previous organization', async ({ browser }) => {
    test.skip(fixture === null);
    if (fixture === null) return;

    const adminContext = await browser.newContext();
    const subjectContext = await browser.newContext();
    const adminPage = await adminContext.newPage();
    const subjectPage = await subjectContext.newPage();

    await login(adminPage, fixture.adminEmail, fixture.adminPassword);

    try {
      await grant(adminPage, fixture);
      await login(subjectPage, fixture.subjectEmail, fixture.subjectPassword);

      const switched = await subjectPage.request.post('/api/auth/switch-org', {
        headers: { 'X-Skip-Csrf': '1', Accept: 'application/json' },
        data: { organization_id: fixture.otherOrganizationId },
      });
      expect(switched.status(), await switched.text()).toBeLessThan(400);

      // Reload is part of the production switch contract and forces AuthContext
      // to obtain a fresh canonical payload instead of retaining the old map.
      await subjectPage.reload();
      const me = await subjectPage.request.get('/api/auth/me', { headers: { Accept: 'application/json' } });
      expect(me.status()).toBe(200);
      const body = await me.json();
      const user = body.user ?? body.data?.user ?? body.data;
      const [moduleName, action] = fixture.capability.split('.');
      expect(user.access?.[moduleName]?.[action]).not.toBe(true);
      expect(user.capabilities ?? []).not.toContain(fixture.capability);
      await expect(subjectPage.getByRole('link', { name: fixture.navigationLabel, exact: true })).toHaveCount(0);

      await subjectPage.goto(fixture.protectedUiPath);
      await expect(subjectPage).toHaveURL(/\/dashboard(?:[?#/]|$)/);
      const denied = await subjectPage.request.get(fixture.protectedApiPath, {
        headers: { Accept: 'application/json' },
      });
      expect(denied.status()).toBe(403);
    } finally {
      await subjectPage.request.post('/api/auth/switch-org', {
        headers: { 'X-Skip-Csrf': '1', Accept: 'application/json' },
        data: { organization_id: fixture.organizationId },
      }).catch(() => undefined);
      await revoke(adminPage, fixture).catch(() => undefined);
      await subjectContext.close();
      await adminContext.close();
    }
  });
});
