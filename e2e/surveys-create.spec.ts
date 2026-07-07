import { test, expect } from '@playwright/test';

/**
 * E2E: Surveys — Create Form
 *
 * Module: Surveys → SurveyForm
 * API: POST /api/surveys (SurveyController::store, guarded by permission:create_surveys)
 * Permission: view_surveys / create_surveys (super_admin bypasses)
 * Org-scoped: yes (organization_id set server-side from the authenticated user)
 *
 * Covers:
 * - Navigate to /surveys
 * - Open /surveys/create (SurveyForm)
 * - Fill required title + optional description
 * - Submit and verify the new survey appears in the list
 * - Permission edge case: mocked 403 POST surfaces the Arabic permission toast
 * - Validation edge case: empty title blocks submission (HTML5 required)
 *
 * Skipped (documented inline):
 * - Cross-org isolation: deferred — requires a second-organization seed fixture.
 * - SurveyBuilder drag/drop: dnd-kit interactions are not stable in Playwright;
 *   the create flow ends at the form layer per AGENTS.md.
 * - Consent / welcome / thank-you textareas: optional, kept out of the happy
 *   path to match project-form scope.
 * - Time-period fields: optional datetime-local inputs, kept out of scope.
 * - Access settings checkboxes: default values are fine for submission.
 * - PublicSurveyPage / survey responses / statistics: separate flows.
 *
 * Notes:
 * - List page title (i18n surveys.title) → "الاستبيانات"
 * - Form page title (i18n surveys.new) → "جديد" with subtitle "إنشاء استبيان جديد"
 * - New-survey button label (i18n surveys.new) → "جديد"
 * - Title field placeholder (i18n surveys.survey_title_placeholder) → "أدخل عنوان الاستبيان"
 * - Submit button label (i18n surveys.create_and_continue) → "إنشاء والمتابعة"
 * - On successful create, SurveyForm navigates to /surveys/<id>/builder
 *   (NOT back to the list), so the spec goes to /surveys afterwards to assert
 *   the new survey is in the table.
 */

test.describe('Surveys Create E2E', () => {
  test.beforeEach(async ({ page }) => {
    // Login as super_admin (has view_surveys + create_surveys)
    await page.goto('/login');
    await page.waitForSelector('input[type="email"]', { timeout: 10000 });
    await page.fill('input[type="email"]', 'admin@admin.com');
    await page.fill('input[type="password"]', 'password');
    await page.click('button[type="submit"]');
    await page.waitForURL('/dashboard', { timeout: 10000 });
  });

  // ── 1. Setup & Login is shared in beforeEach. ──
  // ── 2. Happy Path — create a new survey end-to-end. ──

  test('admin can create a new survey end-to-end', async ({ page }) => {
    const uniqueTitle = `استبيان E2E ${Date.now()}`;

    // List page
    await page.goto('/surveys');
    await page.waitForSelector('h1:has-text("الاستبيانات")', { timeout: 10000 });

    // Open create form via the page-level "جديد" button (rendered inside an <a> link)
    await page.click('a[href="/surveys/create"] button:has-text("جديد")');
    await page.waitForURL('/surveys/create', { timeout: 10000 });

    // Form page header
    await expect(page.locator('h1:has-text("جديد")').first()).toBeVisible({ timeout: 10000 });
    await expect(page.locator('text=إنشاء استبيان جديد').first()).toBeVisible();

    // Required: title
    await page.fill('input[placeholder="أدخل عنوان الاستبيان"]', uniqueTitle);

    // Optional: description (first textarea on the page is the basic-info card)
    await page.locator('textarea').first().fill('وصف استبيان اختباري من E2E');

    // SKIPPED: survey_type Select, category Select (both have safe defaults),
    //   access-settings checkboxes (all default to safe values),
    //   start_date / end_date (optional datetime-local),
    //   consent_required checkbox + consent_text,
    //   welcome_message + thank_you_message textareas.

    // Submit
    await page.click('button:has-text("إنشاء والمتابعة")');

    // SurveyForm navigates to /surveys/<id>/builder on successful create
    await page.waitForURL(/\/surveys\/\d+\/builder/, { timeout: 10000 });

    // Now navigate to the list and verify the new survey title appears
    await page.goto('/surveys');
    await page.waitForSelector('h1:has-text("الاستبيانات")', { timeout: 10000 });
    await expect(page.locator(`text=${uniqueTitle}`).first()).toBeVisible({ timeout: 10000 });
  });

  // ── 3. Permission Edge Cases — mocked 403 POST surfaces Arabic error toast. ──

  test('user without create_surveys permission sees Arabic permission toast', async ({ page }) => {
    // Mock the POST before navigation so the route is registered first
    await page.route('**/api/surveys**', async (route) => {
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

    await page.goto('/surveys/create');
    await expect(page.locator('h1:has-text("جديد")').first()).toBeVisible({ timeout: 10000 });

    // Fill the required title; the mocked POST will fire on submit and return 403
    await page.fill('input[placeholder="أدخل عنوان الاستبيان"]', 'استبيان مرفوض E2E');

    await page.click('button:has-text("إنشاء والمتابعة")');

    // SurveyForm catches the error and calls showToast('error', error.message).
    // The mocked 403 body has the standard Arabic permission message.
    await expect(
      page.locator('text=ليس لديك صلاحية تنفيذ هذا الإجراء').first(),
    ).toBeVisible({ timeout: 10000 });

    // We should NOT have navigated to the builder (form stays open on error)
    await expect(page).toHaveURL(/\/surveys\/create/);
  });

  // ── 4. Validation Edge Cases — empty title blocks submission via HTML5 required. ──

  test('submitting empty survey form blocks submission and stays on form', async ({ page }) => {
    // Track POST calls so we can assert none were made
    let postCount = 0;
    await page.route('**/api/surveys**', async (route) => {
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

    await page.goto('/surveys/create');
    await expect(page.locator('h1:has-text("جديد")').first()).toBeVisible({ timeout: 10000 });

    // Click submit without filling the required title
    await page.click('button:has-text("إنشاء والمتابعة")');

    // Form should still be on /surveys/create
    await expect(page).toHaveURL(/\/surveys\/create/);

    // No POST should have fired (HTML5 `required` on the title Input blocks submit)
    expect(postCount).toBe(0);
  });

  // ── 5. Cross-Org Isolation ──

  test.skip('survey created in org A is not visible to org B admin', async () => {
    // SKIPPED: deferred — requires a second-organization seed fixture.
    // SurveyController::store sets organization_id from the authenticated user;
    // ::index scopes with forOrganization($user->organization_id) for non-super_admin
    // (see AGENTS.md "Org-scoped: yes"). Exercising it end-to-end needs
    // org B user + login swap, which is out of scope for this smoke spec.
  });

  test.afterAll(async ({ request }) => {
    // Cleanup hook: if a future test creates a survey that should be
    // removed post-run, call:
    //   await request.delete('/api/surveys/<id>', {
    //     headers: { 'X-Skip-Csrf': '1' },
    //   });
    void request;
  });
});
