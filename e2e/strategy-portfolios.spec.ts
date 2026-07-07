import { test, expect } from '@playwright/test';

/**
 * E2E: Strategy — Portfolios (الالتزامات / الأهداف التنفيذية)
 *
 * Module: Strategy → Portfolios
 * API: POST /api/strategy/portfolios (PortfolioController::store)
 * Permission: create_strategy / view_strategy (super_admin bypasses)
 * Org-scoped: yes (organization_id enforced server-side)
 *
 * Covers:
 * - Navigate to /strategy/portfolios
 * - Open /strategy/portfolios/new (DirectionForm)
 * - Fill required name + optional description
 * - Submit and verify the new portfolio appears in the list
 * - Permission edge case: mocked 403 POST surfaces the Arabic permission toast
 * - Validation edge case: empty name blocks submission (HTML5 required)
 *
 * Skipped (documented inline):
 * - Cross-org isolation: deferred — requires a second-organization seed fixture.
 * - DatePickers (start_date / end_date) on DirectionForm: native <input type="date">
 *   is fine, but kept out of the happy path to match project-form scope.
 * - Drag/drop GoldenChainView: not a form interaction.
 * - Programs sub-module: out of scope; this spec covers portfolios only.
 *
 * Notes:
 * - List page title (i18n strategy.portfolios) → "الأهداف التنفيذية"
 * - Form page title (i18n strategy.create_portfolio_title) → "إنشاء هدف تنفيذي جديد"
 * - New-portfolio button label (i18n strategy.new_portfolio) → "هدف جديد"
 * - Name field placeholder (i18n strategy.portfolio_name_placeholder) → "أدخل اسم الهدف التنفيذي"
 * - Submit button label (i18n strategy.create_portfolio) → "إنشاء هدف تنفيذي"
 * - DirectionForm uses the shared <Select> listbox (li[role="option"]) — same pattern
 *   as project-form.spec.ts.
 */

test.describe('Strategy Portfolios E2E', () => {
  test.beforeEach(async ({ page }) => {
    // Login as super_admin (has view_strategy + create_strategy)
    await page.goto('/login');
    await page.waitForSelector('input[type="email"]', { timeout: 10000 });
    await page.fill('input[type="email"]', 'admin@admin.com');
    await page.fill('input[type="password"]', 'password');
    await page.click('button[type="submit"]');
    await page.waitForURL('/dashboard', { timeout: 10000 });
  });

  // ── 1. Setup & Login is shared in beforeEach. ──
  // ── 2. Happy Path — create a new portfolio end-to-end. ──

  test('admin can create a new portfolio end-to-end', async ({ page }) => {
    const uniqueName = `هدف E2E تنفيذي ${Date.now()}`;

    // List page
    await page.goto('/strategy/portfolios');
    await page.waitForSelector('text=الأهداف التنفيذية', { timeout: 10000 });

    // Open create form via the page-level "هدف جديد" button
    await page.click('button:has-text("هدف جديد")');
    await page.waitForURL('/strategy/portfolios/new', { timeout: 10000 });

    // Form page header
    await expect(page.locator('text=إنشاء هدف تنفيذي جديد').first()).toBeVisible({ timeout: 10000 });

    // Required: name (uses Input component → renders a normal <input type="text">)
    await page.fill('input[placeholder="أدخل اسم الهدف التنفيذي"]', uniqueName);

    // Optional: description (Textarea — first textarea on the page is the basic-info card)
    await page.locator('textarea').first().fill('وصف الهدف التنفيذي من اختبار E2E');

    // SKIPPED: directive_source Select, strategic_plan_link Input, rationale Textarea,
    //   dates, status (defaults to "draft" → "مسودة" — fine for submission),
    //   and order (defaults to 1). All optional and not required for create.

    // Submit
    await page.click('button:has-text("إنشاء هدف تنفيذي")');

    // DirectionForm navigates back to /strategy/portfolios on success
    await page.waitForURL('/strategy/portfolios', { timeout: 10000 });

    // The new portfolio name should appear in the DataTable
    await expect(page.locator(`text=${uniqueName}`).first()).toBeVisible({ timeout: 10000 });
  });

  // ── 3. Permission Edge Cases — mocked 403 POST surfaces Arabic error toast. ──

  test('user without create_strategy permission sees Arabic permission toast', async ({ page }) => {
    // Mock the POST before navigation so the route is registered first
    await page.route('**/api/strategy/portfolios**', async (route) => {
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

    await page.goto('/strategy/portfolios/new');
    await expect(page.locator('text=إنشاء هدف تنفيذي جديد').first()).toBeVisible({ timeout: 10000 });

    // Fill the required name; the mocked POST will fire on submit and return 403
    await page.fill('input[placeholder="أدخل اسم الهدف التنفيذي"]', 'هدف مرفوض E2E');

    await page.click('button:has-text("إنشاء هدف تنفيذي")');

    // DirectionForm catches the error and calls showToast('error', error.message).
    // The mocked 403 body has the standard Arabic permission message, so the
    // toast text matches the project-form negative-case pattern.
    await expect(
      page.locator('text=ليس لديك صلاحية تنفيذ هذا الإجراء').first(),
    ).toBeVisible({ timeout: 10000 });

    // We should NOT have navigated back to the list (form stays open on error)
    await expect(page).toHaveURL(/\/strategy\/portfolios\/new/);
  });

  // ── 4. Validation Edge Cases — empty name blocks submission via HTML5 required. ──

  test('submitting empty portfolio form blocks submission and stays on form', async ({ page }) => {
    // Track POST calls so we can assert none were made
    let postCount = 0;
    await page.route('**/api/strategy/portfolios**', async (route) => {
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

    await page.goto('/strategy/portfolios/new');
    await expect(page.locator('text=إنشاء هدف تنفيذي جديد').first()).toBeVisible({ timeout: 10000 });

    // Click submit without filling the required name
    await page.click('button:has-text("إنشاء هدف تنفيذي")');

    // Form should still be on /new
    await expect(page).toHaveURL(/\/strategy\/portfolios\/new/);

    // No POST should have fired (HTML5 `required` on the name Input blocks submit)
    expect(postCount).toBe(0);
  });

  // ── 5. Cross-Org Isolation ──

  test.skip('portfolio created in org A is not visible to org B admin', async () => {
    // SKIPPED: deferred — requires a second-organization seed fixture.
    // DirectionForm submits a portfolio that gets organization_id from the
    // current user's org; server-side scoping is enforced in
    // PortfolioController::index / ::show (see AGENTS.md "Org-scoped: yes").
    // Exercising it end-to-end needs org B user + login swap, which is
    // out of scope for this smoke spec.
  });

  test.afterAll(async ({ request }) => {
    // Cleanup hook: if a future test creates a portfolio that should be
    // removed post-run, call:
    //   await request.delete('/api/strategy/portfolios/<id>', {
    //     headers: { 'X-Skip-Csrf': '1' },
    //   });
    void request;
  });
});
