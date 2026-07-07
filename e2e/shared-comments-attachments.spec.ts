import { test, expect } from '@playwright/test';

/**
 * E2E: Shared module — Comments, Attachments, Activity Logs
 *
 * Shared is a cross-cutting module: it has NO dedicated SPA routes of its own.
 * Comments and Attachments are rendered inside the view pages of the modules
 * that own the resource (Tasks, Projects, OVR). Activity logs live behind the
 * Core admin route /admin/activity-logs and are backed by the Shared
 * ActivityLog model.
 *
 * Coverage:
 *   1. Setup & login (admin@admin.com / password).
 *   2. Happy path:
 *      - admin can view the activity-logs admin page.
 *      - user can add a comment on a task (uses the existing Tasks list
 *        modal, then the CommentsSection inside TaskViewModal).
 *   3. Permission edge case: mock POST /api/comments to return 403 and
 *      expect the standard Arabic permission toast.
 *   4. Validation edge case: attempting to submit an empty comment is a
 *      client-side no-op in the current CommentsSection (handleSubmit
 *      returns early), so no inline error is produced. The .exe file
 *      upload rejection is also not driveable from the test because the
 *      underlying input is hidden inside MentionInput.
 *   5. Cross-org isolation: deferred until a second-organization seed
 *      fixture exists.
 *
 * Critical hardening rule exercised (see AGENTS.md "Security Hardening
 * 2026-06-07"): comment attachments are restricted to
 * `pdf,jpg,jpeg,png,doc,docx,xls,xlsx,txt` with a 10 MB cap. We document
 * this in a SKIPPED block below because Playwright cannot easily drive
 * the hidden file input behind MentionInput's attachments UI.
 */

test.describe('Shared: Comments, Attachments, Activity Logs E2E', () => {
  test.beforeEach(async ({ page }) => {
    // Login as super_admin (has view_audit_logs, view_tasks, add_comments, …).
    await page.goto('/login');
    await page.waitForSelector('input[type="email"]', { timeout: 10000 });
    await page.fill('input[type="email"]', 'admin@admin.com');
    await page.fill('input[type="password"]', 'password');
    await page.click('button[type="submit"]');
    await page.waitForURL('/dashboard', { timeout: 10000 });
  });

  test.afterAll(async ({ request }) => {
    // Best-effort cleanup of any comments left behind by the happy-path test.
    // Direct API cleanup uses X-Skip-Csrf: 1 per AGENTS.md (testing env only).
    // We don't know the commentable id up front, so we look up tasks that
    // match the title prefix used in tasks-list.spec.ts and purge their
    // E2E comments.
    try {
      const tasks = await request.get('/api/unified-tasks?search=' + encodeURIComponent('مهمة E2E'), {
        headers: { 'X-Skip-Csrf': '1' },
      });
      if (!tasks.ok()) return;
      const body = (await tasks.json()) as { data?: Array<{ id: number }> };
      for (const t of body.data || []) {
        const comments = await request.get(
          `/api/comments?commentable_type=task&commentable_id=${t.id}&search=` + encodeURIComponent('تعليق E2E مشترك'),
          { headers: { 'X-Skip-Csrf': '1' } }
        );
        if (!comments.ok()) continue;
        const cbody = (await comments.json()) as { data?: Array<{ id: number }> };
        for (const c of cbody.data || []) {
          await request.delete(`/api/comments/${c.id}`, {
            headers: { 'X-Skip-Csrf': '1' },
          });
        }
      }
    } catch {
      // Best-effort cleanup — never fail the suite on teardown.
    }
  });

  // ── Section 2: Happy Path ──────────────────────────────────────────────

  test('admin can view activity logs', async ({ page }) => {
    await page.goto('/admin/activity-logs');
    await page.waitForSelector('text=سجل النشاط', { timeout: 10000 });

    // The page renders a Card with either a table (rows) or the empty state.
    // At minimum the page subtitle must be visible.
    await expect(page.locator('text=سجل بجميع العمليات في النظام')).toBeVisible({ timeout: 10000 });

    // Either the table is rendered (data.length > 0) or the empty state is.
    const tableVisible = await page
      .locator('table thead th:has-text("الوقت")')
      .first()
      .isVisible()
      .catch(() => false);
    const emptyVisible = await page
      .locator('text=لا توجد سجلات')
      .first()
      .isVisible()
      .catch(() => false);
    expect(tableVisible || emptyVisible).toBeTruthy();

    // When the table renders, the column headers must be in Arabic.
    if (tableVisible) {
      await expect(page.locator('th:has-text("المستخدم")').first()).toBeVisible();
      await expect(page.locator('th:has-text("الإجراء")').first()).toBeVisible();
      await expect(page.locator('th:has-text("الوصف")').first()).toBeVisible();
    }

    // SKIPPED: action filter dropdown and search input — the table already
    // exercises the read path; filter behaviour is covered by unit tests on
    // the controller (ActivityLogController::index).
  });

  test('user can add a comment on a task', async ({ page }) => {
    // Visit the unified tasks list. The first row's view button opens
    // TaskViewModal which embeds CommentsSection.
    await page.goto('/tasks');
    await page.waitForSelector('table', { timeout: 10000 });

    // Open the first task via the Eye icon button (table row, actions column).
    await page.locator('table button[aria-label="عرض"]').first().click();

    // Wait for the TaskViewModal to mount, then switch to the comments tab.
    await page.waitForSelector('text=التعليقات', { timeout: 10000 });
    await page.click('button:has-text("التعليقات")');

    // The CommentsSection renders a MentionInput textarea. Type into the
    // first visible textarea on the modal.
    const commentText = 'تعليق E2E مشترك';
    const textarea = page.locator('textarea').first();
    await expect(textarea).toBeVisible({ timeout: 10000 });
    await textarea.fill(commentText);

    // The MentionInput submit is triggered by Enter or a send button. We
    // press Enter — the most stable cross-component path.
    await textarea.press('Enter');

    // After submission the comment should appear in the list. The
    // CommentsSection re-renders the list via onCommentAdded, so we just
    // assert the text becomes visible.
    await expect(page.locator(`text=${commentText}`).first()).toBeVisible({ timeout: 10000 });

    // SKIPPED: attachment upload. The file input is hidden inside
    // MentionInput; the .exe rejection from AGENTS.md "Security Hardening
    // 2026-06-07" is covered by Feature tests against CommentController.
  });

  // ── Section 3: Permission Edge Cases ──────────────────────────────────

  test('posting a comment without permission shows unauthorized toast', async ({ page }) => {
    // Mock the create-comment endpoint BEFORE we trigger the POST.
    await page.route('**/api/comments**', async (route) => {
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

    // Navigate to /tasks and open the first task in the view modal.
    await page.goto('/tasks');
    await page.waitForSelector('table', { timeout: 10000 });
    await page.locator('table button[aria-label="عرض"]').first().click();

    // Switch to the comments tab and submit a comment.
    await page.waitForSelector('text=التعليقات', { timeout: 10000 });
    await page.click('button:has-text("التعليقات")');
    const textarea = page.locator('textarea').first();
    await expect(textarea).toBeVisible({ timeout: 10000 });
    await textarea.fill('تعليق E2E مرفوض');
    await textarea.press('Enter');

    // The 403 from the API mock must surface as the standard Arabic toast.
    await expect(page.locator('[role="alert"]')).toContainText(
      'ليس لديك صلاحية تنفيذ هذا الإجراء',
      { timeout: 10000 }
    );
  });

  // ── Section 4: Validation Edge Cases ──────────────────────────────────

  // SKIPPED: "empty comment" inline validation. The current CommentsSection
  // performs a client-side early return in handleSubmit
  // (`if (!newComment.trim() && attachments.length === 0) return;`) and
  // does not surface a visible error. We cannot assert an inline error
  // that the component does not produce. The server-side validation
  // (`content` required) is covered by Feature tests on CommentController.
  test.skip('submitting empty comment shows inline validation error', async ({ page }) => {
    // Intentionally empty — see SKIPPED comment above.
  });

  // SKIPPED: invalid file extension (.exe) rejection. The comment attachment
  // file input is hidden behind MentionInput's attachments UI; Playwright
  // can drive `setInputFiles` on the hidden input, but MentionInput's
  // client-side filtering rejects unknown mime types before the request
  // is sent, so we cannot assert against the server-side Arabic error
  // message ("نوع الملف غير مسموح به"). This critical negative case is
  // covered by Feature tests in tests/Feature/Shared/CommentAttachmentTest.php
  // per AGENTS.md "Security Hardening 2026-06-07".
  test.skip('uploading .exe attachment is rejected with Arabic error', async ({ page }) => {
    // Intentionally empty — see SKIPPED comment above.
  });

  // ── Section 5: Cross-Org Isolation ────────────────────────────────────

  // SKIPPED: requires a second-organization seed fixture (admin@admin.com
  // belongs to the default org). Once a second org + user is seeded we
  // can: (1) sign in as the org-B user, (2) attempt to GET/POST comments
  // against an org-A task, and (3) assert 403/404.
  test.skip('cross-org isolation: org-B user cannot comment on org-A task', async ({ page }) => {
    // Intentionally empty — see SKIPPED comment above.
  });
});
