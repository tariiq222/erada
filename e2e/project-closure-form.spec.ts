import { test, expect } from '@playwright/test';
import { login, loadFixtures, selectDropdownOption } from './helpers/completion';

/**
 * E2E: Project closure form ("نموذج الإتمام" — ClosureModal) business logic.
 *
 * Uses pre-seeded in_progress projects (scripts/qa/seed-completion-e2e.php).
 * Covers the new vs improvement branching, the required-fields validation,
 * and the frontend↔API contract for completing a project.
 */
const fx = loadFixtures();

async function openClosure(page: import('@playwright/test').Page, projectId: number) {
  await page.goto(`/projects/${projectId}`);
  await page.waitForLoadState('networkidle');
  await page.getByRole('button', { name: 'إغلاق المشروع' }).click();
  await expect(page.getByRole('dialog')).toBeVisible({ timeout: 10000 });
}

test.describe('Project closure form', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
  });

  test('NEW project completes via the closure form (lessons + outcome + achievement)', async ({ page }) => {
    // BUG-016 fix: the closure form now collects achievement_status for "new"
    // projects too, matching what the API requires. Completion must succeed.
    await openClosure(page, fx.projectNewForClosure);

    await page.locator('textarea[placeholder="ما الذي تعلّمناه خلال هذا المشروع؟"]').fill('التخطيط المبكر يقلّل التأخير');
    await page.locator('textarea[placeholder="ما الذي تم تحقيقه؟ هل اكتمل المشروع وفق الأهداف المحددة؟"]').fill('تحقّقت الأهداف الأساسية للمشروع');
    await selectDropdownOption(page, 'اختر حالة التحقق', 'تحقق كامل');

    await page.getByRole('button', { name: 'تأكيد الإغلاق' }).click();

    // Success contract: confirmation toast + closure action no longer offered.
    await expect(page.getByText('تم إغلاق المشروع بنجاح')).toBeVisible({ timeout: 10000 });
    await expect(page.getByRole('button', { name: 'إغلاق المشروع' })).toHaveCount(0);
  });

  test('IMPROVEMENT project completes with sustainability + achievement fields', async ({ page }) => {
    await openClosure(page, fx.projectImpForClosure);

    await page.locator('textarea[placeholder="ما الذي تعلّمناه خلال هذا المشروع؟"]').fill('أهمية قياس خط الأساس');
    await page.locator('textarea[placeholder="ما الذي تم تحقيقه؟ هل اكتمل المشروع وفق الأهداف المحددة؟"]').fill('خفض زمن المعالجة فعلياً');
    await page.locator('textarea[placeholder*="كيف سيتم الحفاظ على التحسين"]').fill('مراجعات دورية شهرية ومسؤول ضبط');
    await page.locator('input[placeholder="0 – 100"]').fill('85');
    await selectDropdownOption(page, 'اختر حالة التحقق', 'تحقق جزئي');

    await page.getByRole('button', { name: 'تأكيد الإغلاق' }).click();

    await expect(page.getByText('تم إغلاق المشروع بنجاح')).toBeVisible({ timeout: 10000 });
    await expect(page.getByRole('button', { name: 'إغلاق المشروع' })).toHaveCount(0);
  });

  test('closure is blocked with inline errors when required fields are empty', async ({ page }) => {
    await openClosure(page, fx.projectNewForValidation);

    // Submit immediately without filling anything
    await page.getByRole('button', { name: 'تأكيد الإغلاق' }).click();

    // Inline validation messages appear and the modal stays open
    await expect(page.getByText('الدروس المستفادة مطلوبة')).toBeVisible({ timeout: 5000 });
    await expect(page.getByText('ملخص النتيجة مطلوب')).toBeVisible();
    await expect(page.getByRole('dialog')).toBeVisible();
    // No success toast was shown
    await expect(page.getByText('تم إغلاق المشروع بنجاح')).toHaveCount(0);
  });

  test('closure modal shows a non-blocking warning when the project has open tasks', async ({ page }) => {
    await openClosure(page, fx.projectWithOpenTask);

    // Warning is shown but does not block closure
    await expect(page.getByText(/مهمة غير مكتملة/)).toBeVisible({ timeout: 5000 });
    await expect(page.getByRole('button', { name: 'تأكيد الإغلاق' })).toBeEnabled();
  });

  test('improvement closure rejects an out-of-range achievement percentage', async ({ page }) => {
    await openClosure(page, fx.projectImpForPercent);

    await page.locator('textarea[placeholder="ما الذي تعلّمناه خلال هذا المشروع؟"]').fill('درس');
    await page.locator('textarea[placeholder="ما الذي تم تحقيقه؟ هل اكتمل المشروع وفق الأهداف المحددة؟"]').fill('نتيجة');
    await page.locator('textarea[placeholder*="كيف سيتم الحفاظ على التحسين"]').fill('خطة ضبط');
    await selectDropdownOption(page, 'اختر حالة التحقق', 'تحقق جزئي');
    await page.locator('input[placeholder="0 – 100"]').fill('150');

    await page.getByRole('button', { name: 'تأكيد الإغلاق' }).click();

    await expect(page.getByText('يجب أن تكون النسبة بين 0 و 100')).toBeVisible({ timeout: 5000 });
    await expect(page.getByRole('dialog')).toBeVisible();
    await expect(page.getByText('تم إغلاق المشروع بنجاح')).toHaveCount(0);
  });
});

test.describe('Closure action visibility by project status', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
  });

  test('closure action is offered for an on_hold project', async ({ page }) => {
    await page.goto(`/projects/${fx.projectOnHold}`);
    await page.waitForLoadState('networkidle');
    await expect(page.getByRole('button', { name: 'إغلاق المشروع' })).toBeVisible({ timeout: 10000 });
  });

  for (const status of ['Draft', 'Completed', 'Cancelled'] as const) {
    test(`closure action is hidden for a ${status.toLowerCase()} project`, async ({ page }) => {
      const id = fx[`project${status}` as keyof typeof fx] as number;
      await page.goto(`/projects/${id}`);
      await page.waitForLoadState('networkidle');
      // Page rendered (header present) but no closure action
      await expect(page.getByRole('button', { name: 'تعديل' }).first()).toBeVisible({ timeout: 10000 });
      await expect(page.getByRole('button', { name: 'إغلاق المشروع' })).toHaveCount(0);
    });
  }
});
