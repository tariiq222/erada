import { test, expect } from '@playwright/test';

/**
 * E2E: OVR Incident Create Flow
 *
 * Module: Organizational Value & Risk (OVR) — تقارير الحوادث
 * Stack: Laravel 12 API + React 19 SPA
 *
 * Covers:
 * - Navigate to /ovr/incidents list page
 * - Open the create modal (IncidentFormModal)
 * - Fill required fields (incident type, description, severity, date)
 * - Submit successfully and verify the incident appears in the list
 * - Permission edge case: 403 on POST surfaces an Arabic error toast
 * - Validation edge case: missing required fields block submission
 *
 * Skipped sections (documented inline):
 * - Cross-org isolation: requires a second org + user fixture; not implemented yet.
 * - DatePicker portal: custom calendar, no stable accessible input.
 * - Patient-related fields: toggle hidden by default, covered only conditionally.
 *
 * Notes:
 * - The OVR form uses the shared <Select> listbox component, so we reuse the
 *   same `li[role="option"]` selector pattern as project-form.spec.ts.
 * - The form modal title is the i18n key `ovr.new_incident` → "حادثة جديدة".
 * - Toasts are rendered via Toast.tsx with `aria-live="polite"` (not role="alert"),
 *   so we locate toast text by its message <p> inside the toast container.
 */

// Helper: select from custom <Select> listbox component (shared UI)
async function selectDropdownOption(page: any, currentLabel: string, optionText: string) {
  await page.click(`button:has-text("${currentLabel}")`);
  await page.waitForSelector(`li[role="option"]:has-text("${optionText}")`, { timeout: 5000 });
  await page.click(`li[role="option"]:has-text("${optionText}")`);
}

test.describe('OVR Incident Create E2E', () => {
  test.beforeEach(async ({ page }) => {
    // Login as super_admin (has ovr.* permissions)
    await page.goto('/login');
    await page.waitForSelector('input[type="email"]', { timeout: 10000 });
    await page.fill('input[type="email"]', 'admin@admin.com');
    await page.fill('input[type="password"]', 'password');
    await page.click('button[type="submit"]');
    await page.waitForURL('/dashboard', { timeout: 10000 });
  });

  test('admin can create an incident end-to-end', async ({ page }) => {
    await page.goto('/ovr/incidents');
    // Page header title from i18n key ovr.title → "تقارير الحوادث"
    await expect(page.locator('h1:has-text("تقارير الحوادث")').first()).toBeVisible({ timeout: 10000 });

    // Open the create modal via the page-level "الإبلاغ عن حادثة" button
    await page.click('button:has-text("الإبلاغ عن حادثة")');

    // Modal title: ovr.new_incident → "حادثة جديدة"
    const modal = page.locator('div[role="dialog"], [aria-modal="true"]').first();
    await expect(modal.locator('h2:has-text("حادثة جديدة")')).toBeVisible({ timeout: 5000 });

    // Required: incident type. The Select shows its placeholder "اختر نوع الحادثة".
    // Pick the first real category from the open listbox.
    await page.click('button:has-text("اختر نوع الحادثة")');
    await page.waitForSelector('li[role="option"]', { timeout: 5000 });
    const firstCategory = page.locator('li[role="option"]').nth(1);
    const categoryName = (await firstCategory.textContent())?.trim() ?? '';
    await firstCategory.click();

    // Required: severity (defaults to "medium" / متوسط). Change to "high" / عالي.
    await selectDropdownOption(page, 'متوسط', 'عالي');

    // Date defaults to today; time defaults to now — leave as-is.
    // SKIPPED: DatePicker portal — custom calendar widget, no stable accessible input.

    // Required (server-side): description (وصف الحادثة)
    await page.fill('textarea', 'وصف حادثة اختبارية من E2E');

    // Optional: actions taken
    await page
      .locator('textarea')
      .nth(1)
      .fill('إجراءات اختبارية بعد الحادثة');

    // Submit button text: ovr.register → "تسجيل"
    await page.click('button:has-text("تسجيل")');

    // Success toast: ovr.incident_created → "تم إنشاء الحادثة بنجاح"
    await expect(
      page.locator('text=تم إنشاء الحادثة بنجاح').first(),
    ).toBeVisible({ timeout: 10000 });

    // Modal should close and the new incident description should be visible in the table
    await expect(modal).toBeHidden({ timeout: 5000 });
    await expect(page.locator('text=وصف حادثة اختبارية من E2E').first()).toBeVisible({
      timeout: 10000,
    });

    // Suppress unused-var warning for the captured category name (kept for future assertions)
    expect(categoryName.length).toBeGreaterThan(0);
  });

  test('user without ovr.create permission sees Arabic error toast', async ({ page }) => {
    // Mock the POST before navigation so the route is registered first.
    await page.route('**/api/ovr/incidents**', async (route) => {
      if (route.request().method() === 'POST') {
        await route.fulfill({
          status: 403,
          contentType: 'application/json',
          body: JSON.stringify({
            message: 'ليس لديك صلاحية تنفيذ هذا الإجراء',
          }),
        });
      } else {
        await route.continue();
      }
    });

    await page.goto('/ovr/incidents');
    await expect(page.locator('h1:has-text("تقارير الحوادث")').first()).toBeVisible({ timeout: 10000 });

    await page.click('button:has-text("الإبلاغ عن حادثة")');
    const modal = page.locator('div[role="dialog"], [aria-modal="true"]').first();
    await expect(modal.locator('h2:has-text("حادثة جديدة")')).toBeVisible({ timeout: 5000 });

    // Fill minimum to make the form submittable
    await page.click('button:has-text("اختر نوع الحادثة")');
    await page.waitForSelector('li[role="option"]', { timeout: 5000 });
    await page.locator('li[role="option"]').nth(1).click();
    await page.fill('textarea', 'محاولة إنشاء حادثة بدون صلاحية');

    await page.click('button:has-text("تسجيل")');

    // The mocked 403 Arabic message should appear in the toast container
    await expect(
      page.locator('text=ليس لديك صلاحية تنفيذ هذا الإجراء').first(),
    ).toBeVisible({ timeout: 10000 });

    // Modal should remain open (no success → no onSuccess → modal stays)
    await expect(modal.locator('h2:has-text("حادثة جديدة")')).toBeVisible();
  });

  test('submitting empty form blocks submission and keeps modal open', async ({ page }) => {
    // Track POST calls so we can assert none were made.
    let postCount = 0;
    await page.route('**/api/ovr/incidents**', async (route) => {
      if (route.request().method() === 'POST') {
        postCount += 1;
        await route.fulfill({
          status: 422,
          contentType: 'application/json',
          body: JSON.stringify({ message: 'The given data was invalid.' }),
        });
      } else {
        await route.continue();
      }
    });

    await page.goto('/ovr/incidents');
    await expect(page.locator('h1:has-text("تقارير الحوادث")').first()).toBeVisible({ timeout: 10000 });

    await page.click('button:has-text("الإبلاغ عن حادثة")');
    const modal = page.locator('div[role="dialog"], [aria-modal="true"]').first();
    await expect(modal.locator('h2:has-text("حادثة جديدة")')).toBeVisible({ timeout: 5000 });

    // Click submit without touching the required incident_type_id or description.
    // The native <Select required> + browser HTML5 validation should block submission.
    await page.click('button:has-text("تسجيل")');

    // Modal must still be open
    await expect(modal.locator('h2:has-text("حادثة جديدة")')).toBeVisible();

    // No POST should have fired (HTML5 validation stops the form)
    expect(postCount).toBe(0);
  });

  test.afterAll(async ({ request }) => {
    // Cross-org isolation test is deferred — no fixture-based cleanup is required here.
    // Kept as a no-op hook so future cross-org specs can add teardown in one place.
    // SKIPPED: cross-org isolation requires a second organization + user, which
    //   is not seeded by ./scripts/dev-setup.sh; covered separately when fixtures land.
    void request;
  });
});
