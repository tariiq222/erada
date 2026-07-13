import { test, expect } from '@playwright/test';

/**
 * E2E: Core Module — Authentication, Organizations, Dashboard
 *
 * Covers:
 * - Anonymous login flow (login page loads, bad credentials rejected, redirects work)
 * - Admin happy path (login, dashboard loads, organizations list loads)
 * - Permission edge cases (anonymous user redirected away from protected routes,
 *   org form 403 surfaces Arabic permission error)
 * - Validation edge cases (empty / wrong password rejected on login form)
 *
 * Cross-Org Isolation: N/A for Core.
 * `Organization` is a top-level entity (super-admin managed), not an org-scoped
 * resource, so there is no cross-org-isolation test in this spec. Org scoping
 * tests live in user-scoped modules (Projects, HR, Tasks, etc.).
 *
 * Skipped sections (documented in test comments):
 * - 2FA / account-setup token flow (requires seeded setup token, out of scope here)
 * - Profile editing (covered by a separate auth/profile spec)
 * - Roles matrix edit (permission-matrix page is complex; tested in roles spec)
 * - Scope-types and activity-logs admin pages (separate admin specs)
 */

// ────────────────────────────────────────────────────────────────────────────
// (a) Anonymous — Login Flow
// ────────────────────────────────────────────────────────────────────────────

test.describe('Core — Anonymous Login Flow', () => {
  // No beforeEach: this block exercises the unauthenticated surface.

  test('login page loads with Arabic heading and form fields', async ({ page }) => {
    await page.goto('/login');
    await page.waitForSelector('input[type="email"]', { timeout: 10000 });

    // The login form is rendered with a translated Arabic heading
    await expect(page.locator('h2:has-text("تسجيل الدخول")')).toBeVisible({ timeout: 10000 });
    await expect(page.locator('input[type="email"]')).toBeVisible();
    await expect(page.locator('input[type="password"]')).toBeVisible();
    await expect(page.locator('button[type="submit"]')).toBeVisible();
  });

  test('anonymous user is redirected to /login when visiting /dashboard', async ({ page }) => {
    await page.goto('/dashboard');

    // AppLayout redirects unauthenticated users to /login
    await page.waitForURL('**/login', { timeout: 10000 });
    await expect(page).toHaveURL(/\/login$/);
  });

  test('anonymous user is redirected to /login when visiting /admin/organizations', async ({ page }) => {
    await page.goto('/admin/organizations');

    // AppLayout wraps the entire admin route; unauthenticated users are sent to /login
    await page.waitForURL('**/login', { timeout: 10000 });
    await expect(page).toHaveURL(/\/login$/);
  });

  test('submitting login with wrong password shows Arabic error', async ({ page }) => {
    await page.goto('/login');
    await page.waitForSelector('input[type="email"]', { timeout: 10000 });

    await page.fill('input[type="email"]', 'admin@admin.com');
    await page.fill('input[type="password"]', 'wrong-password-xyz');
    await page.click('button[type="submit"]');

    // Backend deliberately returns one generic Arabic message for every failed
    // login attempt, avoiding account-enumeration details.
    // The Login page renders it inside a danger-styled alert div.
    await expect(
      page.locator('div.bg-\\[var\\(--status-danger-subtle\\)\\]'),
    ).toContainText('البريد الإلكتروني أو كلمة المرور غير صحيحة', { timeout: 10000 });

    // User must remain on the login page (no redirect on failure)
    await expect(page).toHaveURL(/\/login$/);
  });

  test('submitting login with empty credentials is blocked by browser validation', async ({ page }) => {
    await page.goto('/login');
    await page.waitForSelector('input[type="email"]', { timeout: 10000 });

    // Click submit without filling anything — both fields are `required`
    await page.click('button[type="submit"]');

    // No API call should fire; we should still be on /login
    await expect(page).toHaveURL(/\/login$/);
    // The login heading should still be visible (no navigation occurred)
    await expect(page.locator('h2:has-text("تسجيل الدخول")')).toBeVisible();
  });
});

// ────────────────────────────────────────────────────────────────────────────
// (b) Admin — Dashboard & Organizations
// ────────────────────────────────────────────────────────────────────────────

test.describe('Core — Admin Dashboard & Organizations', () => {
  test.beforeEach(async ({ page }) => {
    // Login as super_admin (admin@admin.com has full org/role permissions)
    await page.goto('/login');
    await page.waitForSelector('input[type="email"]', { timeout: 10000 });
    await page.fill('input[type="email"]', 'admin@admin.com');
    await page.fill('input[type="password"]', 'password');
    await page.click('button[type="submit"]');
    await page.waitForURL('/dashboard', { timeout: 10000 });
  });

  test('admin dashboard loads after login', async ({ page }) => {
    // The dashboard is the post-login destination; beforeEach already navigates here.
    // Re-navigate to be explicit and to assert the heading is rendered.
    await page.goto('/dashboard');
    await page.waitForURL('/dashboard', { timeout: 10000 });

    // The dashboard renders a welcome heading that includes the user name.
    // Section labels include "نظرة عامة" (Overview) and the stat-strip is visible.
    await expect(page.locator('text=نظرة عامة').first()).toBeVisible({ timeout: 15000 });
  });

  test('admin can list organizations', async ({ page }) => {
    await page.goto('/admin/organizations');

    // OrganizationsList renders the Arabic title "إدارة المؤسسات" via t('admin.organizations.title')
    await expect(page.locator('h1:has-text("إدارة المؤسسات")')).toBeVisible({ timeout: 15000 });

    // The search input is part of the list card
    await expect(page.locator('input[placeholder*="البحث"]')).toBeVisible();
  });

  test('organization form surfaces Arabic permission error on 403', async ({ page }) => {
    // Mock the org-create API to return 403 with the backend's CheckPermission message.
    await page.route('**/api/organizations**', async (route) => {
      if (route.request().method() === 'POST') {
        await route.fulfill({
          status: 403,
          contentType: 'application/json',
          body: JSON.stringify({
            message: 'ليس لديك صلاحية للوصول لهذا المورد',
          }),
        });
      } else {
        await route.continue();
      }
    });

    await page.goto('/admin/organizations/new');
    await page.waitForSelector('text=إضافة مؤسسة', { timeout: 15000 });

    // Fill the required fields (name + code are `required` on the form)
    await page.locator('label:has-text("الاسم") + input').fill('مؤسسة اختبار E2E');
    await page.locator('label:has-text("الرمز") + input').fill('E2E-ORG-001');

    // Submit — the mocked POST will return 403 and the form renders the message
    await page.click('button:has-text("حفظ")');

    // The form displays the error inside a danger-styled div
    await expect(
      page.locator('div.bg-\\[var\\(--status-danger-subtle\\)\\]'),
    ).toContainText('ليس لديك صلاحية', { timeout: 10000 });
  });
});

// Cleanup: log out via the API to ensure no auth_token cookie lingers between
// test files. Uses X-Skip-Csrf: 1 per AGENTS.md (testing env CSRF bypass).
test.afterAll(async ({ request }) => {
  await request.post('/api/auth/logout', {
    headers: { 'X-Skip-Csrf': '1' },
  });
});
