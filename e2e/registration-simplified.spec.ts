import { test, expect } from '@playwright/test';

/**
 * E2E: Public self-registration (simplified flow)
 *
 * Covers the single-step POST /api/register flow that replaced the old
 * invite + admin-approval chain. Each test creates a unique email so
 * the suite can run in parallel without DB-state collisions.
 *
 * Surfaces pinned:
 *  - Single-step form (no OTP step, no invite token, no approval queue).
 *  - User created as active + approved + email_verified + no Spatie role.
 *  - On success, the SPA receives a 201 + HttpOnly auth_token cookie and
 *    refreshes AuthContext, then redirects to /dashboard.
 *  - On validation failure (duplicate email, cross-org department), the
 *    SPA surfaces the Arabic backend message inline.
 *  - Unauthenticated /admin/registrations is gone (404), not a redirect.
 */

function uniqueEmail(prefix: string): string {
  return `${prefix}-${Date.now()}-${Math.floor(Math.random() * 10000)}@e2e.local`;
}

const STRONG_PASSWORD = 'Str0ng!Passw0rd9';

test.describe('Public — Simplified Registration', () => {
  test('register page loads with a single-step form (no OTP, no invite)', async ({ page }) => {
    await page.goto('/register');
    await page.waitForSelector('#register-name', { timeout: 10000 });

    // Single-step: name, email, password, password_confirmation are present.
    await expect(page.locator('#register-name')).toBeVisible();
    await expect(page.locator('#register-email')).toBeVisible();
    await expect(page.locator('#register-password')).toBeVisible();
    await expect(page.locator('#register-password-confirmation')).toBeVisible();
    await expect(page.locator('button[type="submit"]')).toBeVisible();

    // Markers of the old flow must NOT be present.
    await expect(page.locator('text=/رمز التحقق|verification code/i')).toHaveCount(0);
    await expect(page.locator('text=/رمز الدعوة|invite token/i')).toHaveCount(0);
    await expect(page.locator('text=/تغيير البريد|change email/i')).toHaveCount(0);
    await expect(page.locator('text=/إعادة الإرسال|resend/i')).toHaveCount(0);
  });

  test('user can register and is redirected to /dashboard with auth_token cookie', async ({ page, context }) => {
    const email = uniqueEmail('happy');

    await page.goto('/register');
    await page.waitForSelector('#register-name', { timeout: 10000 });

    await page.fill('#register-name', 'E2E Test User');
    await page.fill('#register-email', email);
    await page.fill('#register-password', STRONG_PASSWORD);
    await page.fill('#register-password-confirmation', STRONG_PASSWORD);
    await page.click('button[type="submit"]');

    // After successful registration the SPA navigates to /dashboard.
    await page.waitForURL('**/dashboard', { timeout: 15000 });
    await expect(page).toHaveURL(/\/dashboard$/);

    // The auth_token cookie must be set + HttpOnly so the subsequent
    // /api/user call (which AuthContext fires on mount) succeeds.
    const cookies = await context.cookies();
    const auth = cookies.find((c) => c.name === 'auth_token');
    expect(auth, 'auth_token cookie must be set after registration').toBeTruthy();
    expect(auth?.httpOnly, 'auth_token cookie must be HttpOnly').toBe(true);
  });

  test('submitting with a duplicate email shows Arabic validation error', async ({ page }) => {
    const email = uniqueEmail('dup');
    const password = STRONG_PASSWORD;

    // Pre-create the user via the API. We do this through the SPA the first
    // time so the second visit hits the duplicate-email validation path.
    await page.goto('/register');
    await page.waitForSelector('#register-name', { timeout: 10000 });
    await page.fill('#register-name', 'First User');
    await page.fill('#register-email', email);
    await page.fill('#register-password', password);
    await page.fill('#register-password-confirmation', password);
    await page.click('button[type="submit"]');
    await page.waitForURL('**/dashboard', { timeout: 15000 });

    // Sign out via /api/logout so we can try to register the same email again.
    await page.request.post('/api/logout');

    // Second registration with the same email.
    await page.goto('/register');
    await page.waitForSelector('#register-name', { timeout: 10000 });
    await page.fill('#register-name', 'Second User');
    await page.fill('#register-email', email);
    await page.fill('#register-password', password);
    await page.fill('#register-password-confirmation', password);
    await page.click('button[type="submit"]');

    // Backend returns 422 with errors.email — the form must surface it inline.
    const alert = page.locator('div[role="alert"]');
    await expect(alert).toBeVisible({ timeout: 10000 });
    await expect(alert).toContainText(/البريد|email/i);

    // We must NOT have been redirected to /dashboard.
    await expect(page).not.toHaveURL(/\/dashboard$/);
  });

  test('password mismatch is blocked by browser-level required/confirm validation', async ({ page }) => {
    await page.goto('/register');
    await page.waitForSelector('#register-name', { timeout: 10000 });

    await page.fill('#register-name', 'Mismatch');
    await page.fill('#register-email', uniqueEmail('mismatch'));
    await page.fill('#register-password', STRONG_PASSWORD);
    await page.fill('#register-password-confirmation', 'Different!Passw0rd9');
    await page.click('button[type="submit"]');

    // Laravel's `confirmed` rule returns 422 with errors.password.
    // The SPA's catch block puts the message in the [role=alert] div.
    const alert = page.locator('div[role="alert"]');
    await expect(alert).toBeVisible({ timeout: 10000 });
    await expect(page).not.toHaveURL(/\/dashboard$/);
  });

  test('the legacy /admin/registrations route is no longer reachable', async ({ page }) => {
    // The admin-approval queue page is gone in the cutover. The URL no longer
    // matches a FE Route element, so React Router falls through to the
    // `path="*"` catch-all which navigates to /dashboard — and /dashboard's
    // AppLayout auth guard then redirects to /login (because the test
    // user is unauthenticated). The registration-approval queue UI must
    // therefore NEVER render.
    await page.goto('/admin/registrations');
    await page.waitForTimeout(2000);

    // We end up on /login (via the catch-all → /dashboard → AppLayout guard),
    // not on any /admin/registrations-style page.
    expect(page.url()).toMatch(/\/login$/);

    // The page content must NOT contain any registration-approval markers.
    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/بانتظار الاعتماد|pending approval|اعتماد|approve|reject/i);
  });
});
