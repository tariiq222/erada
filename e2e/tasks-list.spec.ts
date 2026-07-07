import { test, expect } from '@playwright/test';

/**
 * E2E: Tasks List & Form
 *
 * Covers:
 * - Login as admin/super_admin (has view_tasks, create_tasks)
 * - Happy path: create a minimal task from the unified Tasks form
 *   (title + status + priority + description) and verify it appears in /tasks.
 * - Permission edge case: mock POST /api/unified-tasks to return 403 and
 *   assert the Arabic permission error toast.
 * - Validation edge case: submit the form with an empty title and expect
 *   the inline "عنوان المهمة مطلوب" error.
 *
 * Skipped sections (documented in test comments):
 * - DatePicker portal (start_date / due_date) — custom calendar, no
 *   stable accessible input.
 * - UserModal assignee picker — user-search modal, not Playwright-friendly.
 * - MilestoneModal — only used when a project is selected.
 * - Kanban / Cards view interactions — primary flows use Table view.
 * - Section 5: Cross-org isolation — deferred until a second-organization
 *   seed fixture exists.
 */

// Helper: select from custom Select dropdown component
async function selectDropdownOption(page: any, currentLabel: string, optionText: string) {
  await page.click(`button:has-text("${currentLabel}")`);
  await page.waitForSelector(`li[role="option"]:has-text("${optionText}")`, { timeout: 5000 });
  await page.click(`li[role="option"]:has-text("${optionText}")`);
}

test.describe('Tasks List & Form E2E', () => {
  test.beforeEach(async ({ page }) => {
    // Login as admin/super_admin with view_tasks + create_tasks permissions
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
    // Cleanup any tasks created with the "مهمة E2E" prefix.
    // Use the request fixture directly with the testing-env CSRF bypass
    // header (see AGENTS.md — gated on app()->environment('testing')).
    try {
      const list = await request.get('/api/unified-tasks?search=' + encodeURIComponent('مهمة E2E'), {
        headers: { 'X-Skip-Csrf': '1' },
      });
      if (!list.ok()) return;
      const body = (await list.json()) as { data?: Array<{ id: number }> };
      const ids = (body.data || []).map((t) => t.id);
      for (const id of ids) {
        await request.delete(`/api/unified-tasks/${id}`, {
          headers: { 'X-Skip-Csrf': '1' },
        });
      }
    } catch {
      // Best-effort cleanup — never fail the suite on teardown.
    }
  });

  test('create task with minimal fields and verify it appears in the list', async ({ page }) => {
    await page.goto('/tasks/create');
    await page.waitForSelector('text=إنشاء مهمة جديدة', { timeout: 10000 });

    // Required: task title
    await page.fill('input[placeholder="أدخل عنوان المهمة"]', 'مهمة E2E جديدة');

    // Optional: status (default "للتنفيذ" → "قيد التنفيذ")
    await selectDropdownOption(page, 'للتنفيذ', 'قيد التنفيذ');

    // Optional: priority (default "متوسطة" → "عالية")
    await selectDropdownOption(page, 'متوسطة', 'عالية');

    // Optional: description
    await page.fill('input[placeholder="أدخل وصف المهمة"]', 'وصف مهمة E2E');

    // SKIPPED: start_date / due_date — custom DatePicker portal, no stable
    // accessible input. The form pre-fills start_date with today.
    // SKIPPED: assignee — UserModal user-search picker is not Playwright-friendly.
    // SKIPPED: project / milestone — TaskTypeSection defaults to "personal" and
    // the form omits project_id, so the unified-tasks endpoint is used.

    // Submit
    await page.click('button:has-text("إنشاء مهمة")');

    // Verify redirect to /tasks and the new task appears in the table
    await page.waitForURL('/tasks', { timeout: 10000 });
    await expect(page.locator('text=مهمة E2E جديدة').first()).toBeVisible({ timeout: 10000 });
  });

  test('user without permission sees unauthorized message on submit', async ({ page }) => {
    // Mock the create endpoint BEFORE navigation so it catches the POST.
    await page.route('**/api/unified-tasks**', async (route) => {
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

    await page.goto('/tasks/create');
    await page.waitForSelector('text=إنشاء مهمة جديدة', { timeout: 10000 });

    // Fill minimal required fields
    await page.fill('input[placeholder="أدخل عنوان المهمة"]', 'مهمة E2E مرفوضة');

    await page.click('button:has-text("إنشاء مهمة")');

    // Verify Arabic permission error toast appears
    await expect(page.locator('[role="alert"]')).toContainText('ليس لديك صلاحية تنفيذ هذا الإجراء', { timeout: 10000 });
  });

  test('submitting empty title shows inline validation error', async ({ page }) => {
    await page.goto('/tasks/create');
    await page.waitForSelector('text=إنشاء مهمة جديدة', { timeout: 10000 });

    // Click submit without filling the title — server-side validation
    // (StoreTaskRequest::rules) will return 422 with errors.title.
    await page.click('button:has-text("إنشاء مهمة")');

    // Inline field error is rendered via FieldError (role="alert") by the
    // shared Input component when `error` is set.
    await expect(page.locator('p[role="alert"]:has-text("عنوان المهمة مطلوب")').first()).toBeVisible({
      timeout: 10000,
    });
  });

  // Section 5 deferred: requires a second-organization seed fixture to
  // exercise cross-org isolation (creating a task in org A, then asserting
  // that an org B user cannot see it via the API or the /tasks list).
  test.skip('cross-org isolation', async ({ page }) => {
    // Intentionally empty — see comment above.
  });
});
