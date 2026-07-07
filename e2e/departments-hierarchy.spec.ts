import { test, expect, Page } from '@playwright/test';

/**
 * E2E: Department hierarchy build-out (الأقسام)
 *
 * Drives the real DepartmentForm UI (/hr/departments/new) to construct two
 * demo org trees under one hospital, then verifies the parent/level linkage
 * via the departments API.
 *
 *   مدير المستشفى (level 1)
 *   ├── مساعد المدير للشؤون الطبية (level 2)
 *   │   └── إدارة الخدمات المساندة (level 3)
 *   │       └── المختبر (level 4)
 *   └── إدارة التخطيط (level 2)
 *       └── مكتب إدارة المشاريع (level 4)
 *
 * Rerunnable: a beforeAll pass deletes any pre-existing demo departments
 * (leaf-first, by descending level) using the real Sanctum + CSRF flow.
 */

const LEVEL_LABEL: Record<number, string> = {
  1: 'الإدارة العليا',
  2: 'إدارة تنفيذية',
  3: 'إدارة',
  4: 'قسم',
};

interface DeptSpec {
  name: string;
  level: number;
  parent: string | null;
}

// Creation order: every parent precedes its children.
// No `code` is set on purpose: `code` has a plain unique index that does NOT
// exclude soft-deleted rows, so reusing fixed codes collides with prior
// (trashed) runs and 500s. Names carry no unique constraint, so they stay safe.
const TREE: DeptSpec[] = [
  { name: 'مدير المستشفى', level: 1, parent: null },
  { name: 'مساعد المدير للشؤون الطبية', level: 2, parent: 'مدير المستشفى' },
  { name: 'إدارة الخدمات المساندة', level: 3, parent: 'مساعد المدير للشؤون الطبية' },
  { name: 'المختبر', level: 4, parent: 'إدارة الخدمات المساندة' },
  { name: 'إدارة التخطيط', level: 2, parent: 'مدير المستشفى' },
  { name: 'مكتب إدارة المشاريع', level: 4, parent: 'إدارة التخطيط' },
];

const NAMES = TREE.map((d) => d.name);

async function login(page: Page) {
  await page.goto('/login');
  await page.waitForSelector('input[type="email"]', { timeout: 10000 });
  await page.fill('input[type="email"]', 'admin@admin.com');
  await page.fill('input[type="password"]', 'password');
  await page.click('button[type="submit"]');
  await page.waitForURL('**/dashboard', { timeout: 10000 });
}

// Read the XSRF cookie and replay the real CSRF flow (works in any APP_ENV,
// unlike the X-Skip-Csrf testing bypass).
async function csrfHeaders(page: Page) {
  const cookies = await page.context().cookies();
  const token = cookies.find((c) => c.name === 'XSRF-TOKEN')?.value ?? '';
  return { 'X-XSRF-TOKEN': decodeURIComponent(token) };
}

async function fetchList(page: Page) {
  const res = await page.request.get('/api/hr/departments/list');
  expect(res.ok()).toBeTruthy();
  return (await res.json()) as { id: number; name: string; parent_id: number | null; level: number }[];
}

// Pick the custom Select trigger by its associated <label>, open it, click an option.
async function selectOption(page: Page, label: string, optionName: string, exact = false) {
  await page.getByLabel(label).click();
  await page.getByRole('option', { name: optionName, exact }).click();
}

async function createDepartment(page: Page, spec: DeptSpec) {
  await page.goto('/hr/departments/new');
  await expect(page.getByText('إنشاء قسم').first()).toBeVisible({ timeout: 10000 });

  await page.getByLabel('اسم القسم').fill(spec.name);

  if (spec.parent) {
    // Selecting a parent triggers an async allowed-levels refetch that resets
    // the level field; do it first, then pin the level explicitly.
    await selectOption(page, 'القسم الأعلى', spec.parent);
    await page.waitForLoadState('networkidle');
  }

  await selectOption(page, 'مستوى القسم', LEVEL_LABEL[spec.level], true);

  const [resp] = await Promise.all([
    page.waitForResponse((r) => r.url().includes('/api/hr/departments') && r.request().method() === 'POST'),
    page.getByRole('button', { name: 'إضافة' }).click(),
  ]);
  expect(resp.status(), `create "${spec.name}" failed: ${await resp.text()}`).toBe(201);

  // Success returns to the list (and toasts "تم إنشاء القسم بنجاح").
  await page.waitForURL((url) => url.pathname.endsWith('/hr/departments'), { timeout: 10000 });
}

test.describe.configure({ mode: 'serial' });

test.describe('Department hierarchy E2E', () => {
  test.beforeAll(async ({ browser }) => {
    const page = await browser.newPage();
    await login(page);
    const headers = await csrfHeaders(page);
    // Delete leaf-first (descending level) so parents are childless when removed.
    // Delete leaf-first (descending level) so parents are childless when removed.
    const existing = (await fetchList(page))
      .filter((d) => NAMES.includes(d.name))
      .sort((a, b) => b.level - a.level);
    for (const d of existing) {
      await page.request.delete(`/api/hr/departments/${d.id}`, { headers });
    }
    await page.close();
  });

  test('builds the full hospital department tree via the UI', async ({ page }) => {
    await login(page);

    for (const spec of TREE) {
      await createDepartment(page, spec);
      await expect(page.getByText(spec.name).first()).toBeVisible({ timeout: 10000 });
    }

    // Verify the persisted parent/level linkage.
    const list = await fetchList(page);
    const byName = new Map(list.map((d) => [d.name, d]));

    for (const spec of TREE) {
      const dept = byName.get(spec.name);
      expect(dept, `department "${spec.name}" should exist`).toBeTruthy();
      expect(dept!.level).toBe(spec.level);
      if (spec.parent === null) {
        expect(dept!.parent_id).toBeNull();
      } else {
        expect(dept!.parent_id).toBe(byName.get(spec.parent!)!.id);
      }
    }
  });
});
