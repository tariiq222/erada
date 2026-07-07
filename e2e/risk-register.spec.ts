import { test, expect } from '@playwright/test';

/**
 * E2E: Risk Register Full Submission Flow
 *
 * Covers:
 * - Login as admin/super_admin (has view_risks, create_risks, edit_risks)
 * - Happy path: navigate to /risk-management/create, fill the required
 *   title field, optionally fill description/consequences, optionally pick
 *   initial_likelihood and initial_impact from the 1..5 scale, submit, and
 *   verify the new risk appears in /risk-management/risks.
 * - Permission edge case: mock POST /api/risk-management/risks to return
 *   403 and assert the Arabic permission error toast.
 * - Validation edge case: submit with an empty title and expect the
 *   inline Arabic validation error.
 *
 * Skipped sections (documented in test comments):
 * - DatePicker for discovery_date — pre-filled with today, no stable
 *   accessible input.
 * - Department / owner / action-owner Selects — the data is loaded
 *   asynchronously and the dropdowns may be empty in CI; the API accepts
 *   nulls, so they're optional and skipped.
 * - Per-action owner / due_date fields — same reason as above.
 * - Section 5: Cross-org isolation — deferred until a second-organization
 *   seed fixture exists.
 */

// Helper: select from custom Select dropdown component
async function selectDropdownOption(page: any, currentLabel: string, optionText: string) {
  await page.click(`button:has-text("${currentLabel}")`);
  await page.waitForSelector(`li[role="option"]:has-text("${optionText}")`, { timeout: 5000 });
  await page.click(`li[role="option"]:has-text("${optionText}")`);
}

test.describe('Risk Register E2E', () => {
  test.beforeEach(async ({ page }) => {
    // Login as admin/super_admin with view_risks + create_risks permissions
    await page.goto('/login');
    await page.waitForSelector('input[type="email"]', { timeout: 10000 });
    await page.fill('input[type="email"]', 'admin@admin.com');
    await page.fill('input[type="password"]', 'password');
    await page.click('button[type="submit"]');
    await page.waitForURL('/dashboard', { timeout: 10000 });
  });

  test.afterAll(async ({ request }) => {
    if (process.env.APP_ENV !== 'testing') {
      throw new Error(`Refusing to run cleanup: APP_ENV=${process.env.APP_ENV ?? '<unset>'}, expected 'testing'.`);
    }
    // Cleanup any risks created with the "خطر E2E" prefix.
    // Use the request fixture directly with the testing-env CSRF bypass
    // header (see AGENTS.md — gated on app()->environment('testing')).
    try {
      const list = await request.get('/api/risk-management/risks?search=' + encodeURIComponent('خطر E2E'), {
        headers: { 'X-Skip-Csrf': '1' },
      });
      if (!list.ok()) return;
      const body = (await list.json()) as { data?: Array<{ id: number }> };
      const ids = (body.data || []).map((r) => r.id);
      for (const id of ids) {
        await request.delete(`/api/risk-management/risks/${id}`, {
          headers: { 'X-Skip-Csrf': '1' },
        });
      }
    } catch {
      // Best-effort cleanup — never fail the suite on teardown.
    }
  });

  test('create risk with required fields and verify it appears in the list', async ({ page }) => {
    await page.goto('/risk-management/create');
    await page.waitForSelector('text=تسجيل خطر جديد', { timeout: 10000 });

    // Required: risk title (the only strictly required text field)
    await page.fill('input[id="عنوان الخطر"]', 'خطر E2E جديد');

    // Optional: description textarea
    await page.locator('textarea').first().fill('وصف خطر E2E للاختبار');

    // Optional: consequences textarea (second textarea on the form)
    await page.locator('textarea').nth(1).fill('الآثار المتوقعة من الخطر');

    // Optional: scale up initial likelihood (default "2 — منخفض" → "4 — مرتفع")
    await selectDropdownOption(page, '2 — منخفض', '4 — مرتفع');

    // Optional: scale up initial impact (default "2 — منخفض" → "4 — مرتفع")
    await selectDropdownOption(page, '2 — منخفض', '4 — مرتفع');

    // SKIPPED: discovery_date — custom date input; pre-filled with today.
    // SKIPPED: type — defaults to "operational" which is a valid choice.
    // SKIPPED: department_id, owner_id, action.owner_id — async-loaded
    // Selects; the API treats them as nullable, so leaving them blank is OK.
    // SKIPPED: action rows — empty title actions are filtered server-side.

    // Submit
    await page.click('button:has-text("حفظ الخطر")');

    // Verify redirect to the new risk's detail page
    await page.waitForURL(/\/risk-management\/risks\/\d+/, { timeout: 10000 });

    // Then navigate to the list and confirm the new risk is rendered
    await page.goto('/risk-management/risks');
    await expect(page.locator('text=خطر E2E جديد').first()).toBeVisible({ timeout: 10000 });
  });

  test('user without permission sees unauthorized message on submit', async ({ page }) => {
    // Mock the create endpoint BEFORE navigation so it catches the POST.
    await page.route('**/api/risk-management/risks**', async (route) => {
      if (route.request().method() === 'POST') {
        await route.fulfill({
          status: 403,
          contentType: 'application/json',
          body: JSON.stringify({ message: 'Unauthorized' }),
        });
      } else {
        await route.continue();
      }
    });

    await page.goto('/risk-management/create');
    await page.waitForSelector('text=تسجيل خطر جديد', { timeout: 10000 });

    // Fill the only strictly required field
    await page.fill('input[id="عنوان الخطر"]', 'خطر E2E مرفوض');

    await page.click('button:has-text("حفظ الخطر")');

    // Verify Arabic permission error toast appears
    await expect(page.locator('[role="alert"]')).toContainText('ليس لديك صلاحية تنفيذ هذا الإجراء', { timeout: 10000 });
  });

  test('submitting empty title shows inline validation error', async ({ page }) => {
    await page.goto('/risk-management/create');
    await page.waitForSelector('text=تسجيل خطر جديد', { timeout: 10000 });

    // Click submit without filling the title — server-side validation
    // (StoreRiskRequest::rules) will return 422 with errors.title.
    await page.click('button:has-text("حفظ الخطر")');

    // The shared Input component renders FieldError with role="alert" when
    // the server returns a validation error for the bound field.
    await expect(page.locator('p[role="alert"]:has-text("العنوان مطلوب")').first()).toBeVisible({
      timeout: 10000,
    });
  });

  // Section 5 deferred: requires a second-organization seed fixture to
  // exercise cross-org isolation (creating a risk in org A, then asserting
  // that an org B user cannot see it via the API or the /risk-management/risks
  // list). The backend org-scope is enforced by RiskController::orgFilter
  // and assertSameOrganization — but exercising it requires a second seeded
  // organization + user, which does not exist in the current seed.
  test.skip('cross-org isolation', async ({ page }) => {
    // Intentionally empty — see comment above.
  });
});
