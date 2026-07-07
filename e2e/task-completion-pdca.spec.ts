import { test, expect, type Page } from '@playwright/test';
import { login, loadFixtures } from './helpers/completion';

/**
 * E2E: Task completion ("الإتمام" on the task level) including the mandatory
 * PDCA documentation for improvement-project tasks, the direct completion path
 * for regular tasks, and the incomplete-subtask guard.
 *
 * Uses pre-seeded in_progress tasks (scripts/qa/seed-completion-e2e.php).
 */
const fx = loadFixtures();

/** Opens the TaskStatusChanger dropdown and clicks the target status item. */
async function changeStatus(page: Page, target: string) {
  // The changer trigger shows the current status label ("قيد التنفيذ").
  const triggers = page.getByRole('button', { name: 'قيد التنفيذ' });
  const count = await triggers.count();
  for (let i = 0; i < count; i++) {
    await triggers.nth(i).click();
    const item = page.getByRole('button', { name: target, exact: true });
    if (await item.isVisible().catch(() => false)) {
      await item.click();
      return;
    }
  }
  throw new Error(`Could not open status dropdown / find item "${target}"`);
}

test.describe('Task completion', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
  });

  test('IMPROVEMENT task completion requires PDCA lessons before confirming', async ({ page }) => {
    await page.goto(`/tasks/${fx.taskImprovement}`);
    await page.waitForLoadState('networkidle');

    await changeStatus(page, 'مكتملة');

    // PDCA documentation modal appears with the confirm button gated on lessons
    const dialog = page.getByRole('dialog');
    await expect(dialog).toBeVisible({ timeout: 10000 });
    const confirm = page.getByRole('button', { name: 'إغلاق المهمة نهائياً' });
    await expect(confirm).toBeDisabled();

    // Fill the mandatory lesson, then confirm
    await page.locator('textarea[placeholder*="ما الدروس التي تعلمتها"]').fill('تعميم الإجراء المحسّن على بقية الفرق');
    await expect(confirm).toBeEnabled();

    const [resp] = await Promise.all([
      page.waitForResponse((r) => /\/api\/unified-tasks\/\d+\/status/.test(r.url()) && r.request().method() === 'PATCH', { timeout: 15000 }),
      confirm.click(),
    ]);
    expect(resp.ok(), `status update failed: ${resp.status()}`).toBeTruthy();

    await expect(page.getByRole('button', { name: 'مكتملة' }).first()).toBeVisible({ timeout: 10000 });
  });

  test('REGULAR task completes directly without PDCA documentation', async ({ page }) => {
    await page.goto(`/tasks/${fx.taskPlain}`);
    await page.waitForLoadState('networkidle');

    const [resp] = await Promise.all([
      page.waitForResponse((r) => /\/api\/unified-tasks\/\d+\/status/.test(r.url()) && r.request().method() === 'PATCH', { timeout: 15000 }),
      changeStatus(page, 'مكتملة'),
    ]);
    expect(resp.ok(), `status update failed: ${resp.status()}`).toBeTruthy();

    await expect(page.getByRole('button', { name: 'مكتملة' }).first()).toBeVisible({ timeout: 10000 });
  });

  test('completion is blocked when subtasks are still incomplete', async ({ page }) => {
    await page.goto(`/tasks/${fx.taskParentWithSubtask}`);
    await page.waitForLoadState('networkidle');

    await changeStatus(page, 'مكتملة');

    // Guard modal appears; no status change happens
    await expect(page.getByText('توجد مهام فرعية غير مكتملة')).toBeVisible({ timeout: 10000 });
    await page.getByRole('button', { name: 'فهمت' }).click();
    await expect(page.getByRole('button', { name: 'قيد التنفيذ' }).first()).toBeVisible();
  });

  test('parent task completes once its subtask is completed', async ({ page }) => {
    // Complete the child first (regular project task → direct completion)
    await page.goto(`/tasks/${fx.taskChildPositive}`);
    await page.waitForLoadState('networkidle');
    await Promise.all([
      page.waitForResponse((r) => /\/api\/unified-tasks\/\d+\/status/.test(r.url()) && r.request().method() === 'PATCH', { timeout: 15000 }),
      changeStatus(page, 'مكتملة'),
    ]);

    // Now the parent has no incomplete subtasks → completion succeeds (no guard)
    await page.goto(`/tasks/${fx.taskParentPositive}`);
    await page.waitForLoadState('networkidle');
    const [resp] = await Promise.all([
      page.waitForResponse((r) => /\/api\/unified-tasks\/\d+\/status/.test(r.url()) && r.request().method() === 'PATCH', { timeout: 15000 }),
      changeStatus(page, 'مكتملة'),
    ]);
    expect(resp.ok(), `parent completion failed: ${resp.status()}`).toBeTruthy();
    await expect(page.getByText('توجد مهام فرعية غير مكتملة')).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'مكتملة' }).first()).toBeVisible({ timeout: 10000 });
  });

  test('moving an improvement task to review requires a comment', async ({ page }) => {
    await page.goto(`/tasks/${fx.taskForReview}`);
    await page.waitForLoadState('networkidle');

    await changeStatus(page, 'قيد المراجعة');

    // Review modal: confirm gated on a required comment
    await expect(page.getByRole('dialog')).toBeVisible({ timeout: 10000 });
    const confirm = page.getByRole('button', { name: 'إرسال للمراجعة' });
    await expect(confirm).toBeDisabled();

    await page.locator('textarea[placeholder="اكتب سبب إرسال المهمة للمراجعة..."]').fill('أنجزت الخطوات، جاهزة للمراجعة');
    await expect(confirm).toBeEnabled();

    const [resp] = await Promise.all([
      page.waitForResponse((r) => /\/api\/unified-tasks\/\d+\/status/.test(r.url()) && r.request().method() === 'PATCH', { timeout: 15000 }),
      confirm.click(),
    ]);
    expect(resp.ok(), `review transition failed: ${resp.status()}`).toBeTruthy();
    await expect(page.getByRole('button', { name: 'قيد المراجعة' }).first()).toBeVisible({ timeout: 10000 });
  });
});
