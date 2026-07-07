import { test, expect } from '@playwright/test';

/**
 * E2E: Project Form Full Submission Flow
 *
 * Covers:
 * - Navigate to project creation form
 * - Fill all practical fields across all 6 steps:
 *   Step 0: Basic info (name, description, status, priority, budget)
 *   Step 1: Objectives & Scope (objectives, in_scope, out_of_scope)
 *   Step 2: Team & Stakeholders (skipped — requires InviteUserModal)
 *   Step 3: Milestones (name, description, deliverable)
 *   Step 4: Tasks (name, description, priority, milestone link)
 *   Step 5: Risks & Resources (description, impact, probability, mitigation, all resource textareas)
 * - Submit successfully
 * - Verify redirect to projects list
 *
 * Skipped sections (documented in test comments):
 * - DatePickers: custom calendar portal, no accessible input
 * - User selection modals (sponsor, manager, supervisor, team, stakeholder, assignee)
 * - Additional items beyond defaults (add buttons work but kept minimal for stability)
 *
 * Negative:
 * - User without create_projects permission sees 403/unauthorized message
 */

// Helper: select from custom Select dropdown component
async function selectDropdownOption(page: any, currentLabel: string, optionText: string) {
  await page.click(`button:has-text("${currentLabel}")`);
  await page.waitForSelector(`li[role="option"]:has-text("${optionText}")`, { timeout: 5000 });
  await page.click(`li[role="option"]:has-text("${optionText}")`);
}

test.describe('Project Form E2E', () => {
  test.beforeEach(async ({ page }) => {
    // Login as admin/super_admin with create_projects permission
    await page.goto('/login');
    await page.waitForSelector('input[type="email"]', { timeout: 10000 });
    await page.fill('input[type="email"]', 'admin@admin.com');
    await page.fill('input[type="password"]', 'password');
    await page.click('button[type="submit"]');
    await page.waitForURL('/dashboard', { timeout: 10000 });
  });

  test('full project creation flow submits successfully', async ({ page }) => {
    await page.goto('/projects/create');
    await page.waitForSelector('text=إنشاء مشروع جديد', { timeout: 10000 });

    // ── Step 0: Basic Info ──────────────────────────────────────────────

    // Required: project name
    await page.fill('input[placeholder="أدخل اسم المشروع"]', 'مشروع E2E شامل');

    // Optional: description (textarea in basic-info card)
    await page.locator('textarea').first().fill('وصف شامل لمشروع E2E');

    // Optional: status dropdown (default "مسودة" → "قيد التنفيذ")
    await selectDropdownOption(page, 'مسودة', 'قيد التنفيذ');

    // Required: priority dropdown (default "متوسطة" → "عالية")
    await selectDropdownOption(page, 'متوسطة', 'عالية');

    // Optional: budget (single number input on this step)
    await page.fill('input[type="number"]', '1000000');

    // SKIPPED: start_date, end_date — custom DatePicker portal, no stable accessible input.
    // SKIPPED: department, program, sponsor, manager, supervisor — Select/modal interactions.
    // Note: manager auto-fills to current user, which is sufficient for submission.

    await page.click('button:has-text("التالي")');

    // ── Step 1: Objectives & Scope ──────────────────────────────────────

    // Fill the default objective input (placeholder: "أدخل الهدف")
    await page.locator('input[placeholder="أدخل الهدف"]').fill('هدف E2E رئيسي');

    // Fill the default in_scope item (placeholder: "أدخل عنصراً")
    await page.locator('div:has(> h4:has-text("ضمن النطاق")) input[placeholder="أدخل عنصراً"]').fill('نطاق E2E داخلي');

    // Fill the default out_of_scope item
    await page.locator('div:has(> h4:has-text("خارج النطاق")) input[placeholder="أدخل عنصراً"]').fill('نطاق E2E خارجي');

    await page.click('button:has-text("التالي")');

    // ── Step 2: Team & Stakeholders ─────────────────────────────────────

    // SKIPPED: team member / stakeholder user selection requires InviteUserModal.
    // The step displays read-only manager chip (auto-filled from Step 0).
    await page.click('button:has-text("التالي")');

    // ── Step 3: Milestones ──────────────────────────────────────────────

    // Fill the default milestone name
    await page.fill('input[placeholder="أدخل اسم المرحلة"]', 'مرحلة E2E أولى');

    // Fill the default milestone description
    await page.fill('input[placeholder="أدخل وصف المرحلة (اختياري)"]', 'وصف مرحلة E2E');

    // Fill the default deliverable name
    await page.fill('input[placeholder="اسم المخرج"]', 'مخرج E2E أول');

    // SKIPPED: milestone start_date / due_date — DatePicker disabled without project dates.
    // SKIPPED: adding extra milestones — button disabled without project dates.

    await page.click('button:has-text("التالي")');

    // ── Step 4: Tasks ───────────────────────────────────────────────────

    // Fill the default task name
    await page.fill('input[placeholder="أدخل اسم المهمة"]', 'مهمة E2E أولى');

    // Link task to the milestone created in Step 3
    await selectDropdownOption(page, 'لا توجد مراحل', 'مرحلة E2E أولى');

    // Set task priority
    await selectDropdownOption(page, 'متوسطة', 'عالية');

    // Fill task description
    await page.fill('input[placeholder="أدخل وصف المهمة"]', 'وصف مهمة E2E');

    // SKIPPED: task assignee — requires InviteUserModal.
    // SKIPPED: task start_date / due_date — DatePicker disabled without project dates.

    await page.click('button:has-text("التالي")');

    // ── Step 5: Risks & Resources ───────────────────────────────────────

    // Fill the default risk description
    await page.fill('input[placeholder="أدخل وصف المخاطرة"]', 'خطر E2E محتمل');

    // Set risk impact (default "متوسط" → "عالي")
    await selectDropdownOption(page, 'متوسط', 'عالي');

    // Set risk probability (default "متوسطة" → "عالية")
    await selectDropdownOption(page, 'متوسطة', 'عالية');

    // Fill mitigation plan
    await page.fill('input[placeholder="أدخل خطة التخفيف"]', 'خطة تخفيف E2E');

    // Fill resource textareas
    await page.locator('div:has(> label:has-text("الموارد البشرية")) textarea').fill('فريق E2E بشري');
    await page.locator('div:has(> label:has-text("الموارد التقنية")) textarea').fill('أدوات E2E تقنية');
    await page.locator('div:has(> label:has-text("الموارد المالية")) textarea').fill('ميزانية E2E مالية');

    // Required closure/charter fields added to the final step.
    await page.locator('textarea[placeholder="ما الشروط المطلوبة للموافقة على إنجاز المشروع؟"]').fill('اعتماد راعي المشروع وتحقق المخرجات');
    await page.locator('textarea[placeholder="متى ينتهي المشروع رسمياً؟"]').fill('عند قبول جميع المخرجات وإغلاق المخاطر');

    // Save the project
    await page.click('button:has-text("إنشاء مشروع")');

    // Verify redirect to projects list and project appears
    await page.waitForURL('/projects', { timeout: 10000 });
    await expect(page.locator('text=مشروع E2E شامل').first()).toBeVisible({ timeout: 10000 });
  });

  test('user without permission sees unauthorized message', async ({ page }) => {
    // Set up mock BEFORE navigation so it catches the API call
    await page.route('**/api/projects**', async (route) => {
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

    await page.goto('/projects/create');
    await page.waitForSelector('text=إنشاء مشروع جديد', { timeout: 10000 });

    // Fill minimal required fields
    await page.fill('input[placeholder="أدخل اسم المشروع"]', 'مشروع مرفوض');
    await selectDropdownOption(page, 'متوسطة', 'متوسطة');

    // Navigate to step 5 and save (the API mock will trigger on submit)
    for (let i = 0; i < 5; i++) {
      await page.click('button:has-text("التالي")');
    }

    await page.locator('textarea[placeholder="ما الشروط المطلوبة للموافقة على إنجاز المشروع؟"]').fill('شروط قبول اختبارية');
    await page.locator('textarea[placeholder="متى ينتهي المشروع رسمياً؟"]').fill('معايير إنهاء اختبارية');

    await page.click('button:has-text("إنشاء مشروع")');

    // Verify Arabic permission error toast appears
    await expect(page.locator('[role="alert"]')).toContainText('ليس لديك صلاحية تنفيذ هذا الإجراء', { timeout: 10000 });
  });
});
