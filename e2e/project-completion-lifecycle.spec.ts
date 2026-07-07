import { test, expect } from '@playwright/test';
import { login, selectDropdownOption, pickToday } from './helpers/completion';

/**
 * E2E: Project creation → in-progress, exercising the parts the legacy
 * project-form spec deliberately skipped: real DATE selection (DatePicker
 * portal) and MANAGER ASSIGNMENT ("أنا مدير هذا المشروع").
 *
 * Verifies the chain that precedes completion:
 *  - dates persist (التواريخ) — asserted against the project detail API
 *  - the creator is assigned as project manager (الإسناد)
 *  - the project lands in a completable state (in_progress) → closure offered
 */
test.describe('Project lifecycle — create with dates + manager assignment', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
  });

  test('creates a project with start/end dates and manager, ready for completion', async ({ page }) => {
    const name = `E2E Lifecycle ${Date.now()}`;
    const today = new Date().toISOString().slice(0, 10);

    await page.goto('/projects/create?type=new');
    await page.waitForSelector('input[placeholder="أدخل اسم المشروع"]', { timeout: 15000 });

    await page.fill('input[placeholder="أدخل اسم المشروع"]', name);

    // Dates first (before opening the status listbox, to avoid overlay races)
    await pickToday(page, 'اختر تاريخ البداية');
    await pickToday(page, 'اختر تاريخ الانتهاء');

    // In-progress so the project is completable afterwards
    await selectDropdownOption(page, 'مسودة', 'قيد التنفيذ');

    // Manager assignment — ensure "أنا مدير هذا المشروع" is checked
    const managerCheckbox = page.locator('input[type="checkbox"]').first();
    if (!(await managerCheckbox.isChecked())) {
      await managerCheckbox.check();
    }

    // Required charter fields (PMBOK)
    await page.locator('textarea[placeholder="ما الشروط المطلوبة للموافقة على إنجاز المشروع؟"]').fill('اعتماد الراعي وتحقق المخرجات');
    await page.locator('textarea[placeholder="متى ينتهي المشروع رسمياً؟"]').fill('قبول جميع المخرجات وإغلاق المخاطر');

    // Submit and capture the created project id
    const [resp] = await Promise.all([
      page.waitForResponse((r) => r.url().includes('/api/projects') && r.request().method() === 'POST', { timeout: 15000 }),
      page.getByRole('button', { name: 'إنشاء مشروع', exact: true }).click(),
    ]);
    expect(resp.ok(), `create failed: ${resp.status()}`).toBeTruthy();
    const created = await resp.json();
    const projectId = created.project?.id ?? created.id ?? created.data?.id;
    expect(projectId, 'no project id in create response').toBeTruthy();

    // Verify persisted dates + manager assignment via the detail API
    const detailResp = await page.request.get(`/api/projects/${projectId}`);
    expect(detailResp.ok(), `detail fetch failed: ${detailResp.status()}`).toBeTruthy();
    const detail = await detailResp.json();
    const project = detail.project ?? detail.data ?? detail;

    expect(project.start_date, 'start_date not persisted').toBe(today); // التواريخ
    expect(project.end_date, 'end_date not persisted').toBe(today);
    expect(String(project.manager_id ?? project.manager?.id), 'manager not assigned to creator').toBe('1'); // الإسناد
    expect(project.status).toBe('in_progress');

    // UI confirms the project is in a completable state
    await page.goto(`/projects/${projectId}`);
    await page.waitForLoadState('networkidle');
    await expect(page.getByRole('button', { name: 'إغلاق المشروع' })).toBeVisible({ timeout: 10000 });
  });
});
