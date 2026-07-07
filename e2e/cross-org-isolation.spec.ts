import { test, expect, type BrowserContext, type Page } from '@playwright/test';

/**
 * E2E: Cross-Org Isolation
 *
 * Goal: prove that an actor authenticated against org-B cannot view OR mutate a
 * resource that belongs to org-A — both at the API layer (the trust boundary)
 * and at the UI layer (the rendered React route).
 *
 * Strategy: this spec is HERMETIC. It self-bootstraps org-B (and a tenant-B
 * admin user) inside the spec rather than depending on the authz fixture
 * scenario (DEMO_SCENARIO=authz). The five "needs second-organization seed
 * fixture" skips across strategy-portfolios, surveys-create, tasks-list,
 * risk-register, and shared-comments-attachments are now redundant with this
 * spec — see e2e/SKIPPED_TESTS_AUDIT.md.
 *
 * Why we use `page.evaluate` for direct API calls instead of the
 * `request` fixture: the API's `EnsureCsrfForStateChangingApi` middleware only
 * honors `X-Skip-Csrf: 1` when `app()->environment('testing')`. The e2e suite
 * runs against the dev app (APP_ENV=local), so the bypass is a no-op. Driving
 * state-changing requests through `page.evaluate(fetch...)` lets the SPA's
 * own XSRF cookie carry a valid token naturally (it is set by the SPA's login
 * flow). For bootstrap creation we still need server-side auth, so we use the
 * same approach.
 *
 * What gets created and disposed:
 *   1. Tenant-B organization (POST /api/organizations by super_admin).
 *   2. Tenant-B admin user (POST /api/users, scoped to org-B).
 *   3. A throwaway org-A project (POST /api/projects via super_admin).
 *   afterAll deletes the org-B user, the org-A project, then the org-B org
 *   (cascade clears users/departments owned by org-B).
 */

const ORG_B_NAME = 'E2E Tenant B';
const ORG_B_CODE = `E2E-TNTB-${Date.now().toString(36)}`;
const ORG_B_ADMIN_EMAIL = `e2e.tenantB.admin.${Date.now()}@example.com`;
const ORG_B_ADMIN_PASSWORD = 'Password123!';

interface CreatedIds {
    orgAId: number;
    orgBId: number;
    orgBUserId: number;
    orgAProjectId: number;
}

/** Logs in via the real login form and returns nothing (page now has session cookie). */
async function loginViaUi(page: Page, email: string, password: string): Promise<void> {
    await page.goto('/login');
    await page.waitForSelector('input[type="email"]', { timeout: 15000 });
    await page.fill('input[type="email"]', email);
    await page.fill('input[type="password"]', password);
    await page.click('button[type="submit"]');
    await page.waitForURL('**/dashboard', { timeout: 15000 });
}

/**
 * Sends a JSON request using the SPA's own fetch. This is the only path that
 * carries the live session cookie + a server-validated CSRF token (the SPA
 * sets `X-XSRF-TOKEN` from the encrypted cookie). Returning a structurally
 * normalized `{ status, body, ok }` so the test can assert cleanly.
 */
async function authedFetch(page: Page, opts: { method: string; path: string; body?: unknown }): Promise<{
    status: number;
    body: unknown;
    ok: boolean;
}> {
    // The SPA API client sends X-XSRF-TOKEN from the encrypted cookie on
    // POST/PUT/PATCH/DELETE (see resources/js/shared/api/client.ts). We mirror
    // that here so the SPA's own CSRF gate accepts the request — there is no
    // X-Skip-Csrf bypass outside app()->environment('testing').
    return await page.evaluate(
        async ({ m, p, b }) => {
            const headers: Record<string, string> = {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            };
            const upper = m.toUpperCase();
            if (['POST', 'PUT', 'PATCH', 'DELETE'].includes(upper)) {
                const xsrf = document.cookie
                    .split('; ')
                    .find((row) => row.startsWith('XSRF-TOKEN='));
                if (xsrf) {
                    headers['X-XSRF-TOKEN'] = decodeURIComponent(xsrf.split('=')[1]);
                }
            }
            const res = await fetch(p, {
                method: m,
                headers,
                body: b !== undefined ? JSON.stringify(b) : undefined,
                credentials: 'same-origin',
            });
            const text = await res.text();
            let parsed: unknown = text;
            try {
                parsed = text ? JSON.parse(text) : null;
            } catch {
                // body is not JSON (e.g. SPA-fallback HTML on unauth)
            }
            return { status: res.status, body: parsed, ok: res.ok };
        },
        { m: opts.method, p: opts.path, b: opts.body }
    );
}

interface OrgListResponse {
    data?: Array<{ id: number; is_active?: boolean; name?: string }>;
    organizations?: Array<{ id: number; is_active?: boolean; name?: string }>;
}

/**
 * Resolves any existing org to use as "org-A". We pick the first org from
 * GET /api/organizations. This works under any seeded scenario because the
 * super_admin always sees every org.
 */
async function resolveOrgA(page: Page): Promise<number> {
    const res = await authedFetch(page, { method: 'GET', path: '/api/organizations' });
    expect(res.ok).toBeTruthy();
    const list = (res.body as OrgListResponse).data ?? (res.body as OrgListResponse).organizations ?? [];
    expect(Array.isArray(list) && list.length > 0).toBeTruthy();
    return list[0].id;
}

interface CreateOrgResponse {
    data?: { id: number };
    organization?: { id: number };
}

/** Creates a tenant-B organization. Returns the new org id. */
async function createOrgB(page: Page): Promise<number> {
    const res = await authedFetch(page, {
        method: 'POST',
        path: '/api/organizations',
        body: {
            name: ORG_B_NAME,
            code: ORG_B_CODE,
            description: 'E2E cross-org isolation fixture (org-B)',
            is_active: true,
        },
    });
    expect(res.ok).toBeTruthy();
    const body = res.body as CreateOrgResponse;
    const id = body.data?.id ?? body.organization?.id;
    if (!id) throw new Error(`Could not resolve tenant-B org id: ${JSON.stringify(body)}`);
    return id;
}

interface CreateUserResponse {
    data?: { id: number };
    user?: { id: number };
}

/** Creates a tenant-B admin user. Falls back to a no-role user if role escalation is rejected. */
async function createOrgBUser(page: Page, orgBId: number): Promise<number> {
    const withRoles = await authedFetch(page, {
        method: 'POST',
        path: '/api/users',
        body: {
            name: 'E2E Tenant B Admin',
            email: ORG_B_ADMIN_EMAIL,
            password: ORG_B_ADMIN_PASSWORD,
            password_confirmation: ORG_B_ADMIN_PASSWORD,
            organization_id: orgBId,
            is_active: true,
            roles: ['admin'],
        },
    });
    if (withRoles.ok) {
        return (withRoles.body as CreateUserResponse).data?.id
            ?? (withRoles.body as CreateUserResponse).user?.id
            ?? (() => { throw new Error('Missing user id (with roles)'); })();
    }

    const noRoles = await authedFetch(page, {
        method: 'POST',
        path: '/api/users',
        body: {
            name: 'E2E Tenant B Admin',
            email: ORG_B_ADMIN_EMAIL,
            password: ORG_B_ADMIN_PASSWORD,
            password_confirmation: ORG_B_ADMIN_PASSWORD,
            organization_id: orgBId,
            is_active: true,
        },
    });
    expect(noRoles.ok).toBeTruthy();
    return (noRoles.body as CreateUserResponse).data?.id
        ?? (noRoles.body as CreateUserResponse).user?.id
        ?? (() => { throw new Error('Missing user id'); })();
}

interface CreateProjectResponse {
    data?: { id: number };
    project?: { id: number };
}

interface DeptListResponse {
    data?: Array<{ id: number; organization_id?: number | null }>;
    departments?: Array<{ id: number; organization_id?: number | null }>;
}

/**
 * Lists departments and returns the first one belonging to `orgId`.
 * The hospital seed leaves many departments with a null organization_id
 * (created before the org-scope migration), so a generic "first department"
 * would not actually belong to any org. We pick one explicitly scoped to
 * org-A so the project's `organization_id` resolves correctly.
 */
async function findDeptInOrgA(page: Page, orgId: number): Promise<number> {
    const res = await authedFetch(page, { method: 'GET', path: '/api/departments' });
    if (!res.ok) {
        // If the user cannot list departments (e.g. viewer), create without a
        // department — projects can be created with null department_id.
        return -1;
    }
    const list = (res.body as DeptListResponse).data ?? (res.body as DeptListResponse).departments ?? [];
    const match = list.find((d) => d.organization_id === orgId) ?? list[0];
    if (!match) return -1;
    return match.id;
}

/**
 * Creates a minimal org-A project (draft submission so relaxed validation
 * applies). When the org-A pick surfaces a department the spec pins it so
 * `organization_id` resolves to org-A server-side (per ProjectCrudService).
 */
async function createOrgAProject(page: Page, orgId: number): Promise<number> {
    const deptId = await findDeptInOrgA(page, orgId);
    const body: Record<string, unknown> = {
        name: `E2E cross-org ${Date.now()}`,
        type: 'development',
        status: 'draft',
        save_as_draft: true,
    };
    if (deptId > 0) body.department_id = deptId;

    const res = await authedFetch(page, {
        method: 'POST',
        path: '/api/projects',
        body,
    });
    if (!res.ok) {
        throw new Error(`Could not create org-A project (status=${res.status}): ${JSON.stringify(res.body)}`);
    }
    const parsed = res.body as CreateProjectResponse;
    const id = parsed.data?.id ?? parsed.project?.id;
    if (!id) throw new Error(`Could not resolve project id: ${JSON.stringify(parsed)}`);
    return id;
}

async function deleteProject(page: Page, projectId: number): Promise<void> {
    await authedFetch(page, { method: 'DELETE', path: `/api/projects/${projectId}` });
}

test.describe('Cross-Org Isolation E2E', () => {
    const created: Partial<CreatedIds> = {};
    let superAdminPage: Page;
    let superAdminContext: BrowserContext;
    let tenantBContext: BrowserContext;
    let tenantBPage: Page;

    // beforeAll runs once per describe (per worker). We force a single worker
    // so the bootstrap (org/user/project creation) runs exactly once and the
    // cleanup in afterAll deletes the same ids the tests used.
    test.describe.configure({ mode: 'serial' });

    test.beforeAll(async ({ browser }) => {
        // Context 1 — super_admin / org-A.
        superAdminContext = await browser.newContext();
        superAdminPage = await superAdminContext.newPage();
        await loginViaUi(superAdminPage, 'admin@admin.com', 'password');

        // Bootstrap org-A, org-B, and a tenant-B admin user.
        created.orgAId = await resolveOrgA(superAdminPage);
        created.orgBId = await createOrgB(superAdminPage);
        created.orgBUserId = await createOrgBUser(superAdminPage, created.orgBId);
        created.orgAProjectId = await createOrgAProject(superAdminPage, created.orgAId);

        // Context 2 — tenant-B admin (independent cookies / isolation).
        tenantBContext = await browser.newContext();
        tenantBPage = await tenantBContext.newPage();
        await loginViaUi(tenantBPage, ORG_B_ADMIN_EMAIL, ORG_B_ADMIN_PASSWORD);
    });

    test.afterAll(async () => {
        // Best-effort cleanup. Order matters: project (FK target), user, org
        // (cascade clears org-B-owned departments). 404 means already gone.
        //
        // Known limitation: if a previous run was killed mid-afterAll, residue
        // (one of: project / user / org) may stay in the dev DB. The IDs are
        // time-stamped (`E2E cross-org 1782…`) so it's safe to `DELETE WHERE
        // name LIKE 'E2E cross-org%'` from psql between runs — the next
        // beforeAll will not collide (Date.now() differs).
        const cleanupErrors: string[] = [];

        if (superAdminPage) {
            if (created.orgAProjectId) {
                const r = await authedFetch(superAdminPage, {
                    method: 'DELETE',
                    path: `/api/projects/${created.orgAProjectId}`,
                });
                if (!r.ok && r.status !== 404) {
                    cleanupErrors.push(`project ${created.orgAProjectId}: ${r.status}`);
                }
            }
            if (created.orgBUserId) {
                const r = await authedFetch(superAdminPage, {
                    method: 'DELETE',
                    path: `/api/users/${created.orgBUserId}`,
                });
                if (!r.ok && r.status !== 404) {
                    cleanupErrors.push(`user ${created.orgBUserId}: ${r.status}`);
                }
            }
            if (created.orgBId) {
                const r = await authedFetch(superAdminPage, {
                    method: 'DELETE',
                    path: `/api/organizations/${created.orgBId}`,
                });
                if (!r.ok && r.status !== 404) {
                    cleanupErrors.push(`org ${created.orgBId}: ${r.status}`);
                }
            }
        }

        await tenantBContext?.close();
        await superAdminContext?.close();

        if (cleanupErrors.length > 0) {
            // eslint-disable-next-line no-console
            console.warn('[cross-org-isolation] afterAll cleanup partial:', cleanupErrors.join('; '));
        }
    });

    // ── API layer (via page.evaluate so XSRF cookie + session are live) ──

    test('org-B admin GET /api/projects/{orgA-project} is denied (403 or 404)', async () => {
        const res = await authedFetch(tenantBPage, {
            method: 'GET',
            path: `/api/projects/${created.orgAProjectId}`,
        });
        // findOrFail-after-org-filter returns 404 to prevent id enumeration;
        // some legacy payloads may surface 403. Both are acceptable so long
        // as it is NOT 200 with the project body.
        expect([403, 404]).toContain(res.status);
    });

    test('org-B admin PATCH /api/projects/{orgA-project} is denied (403 or 404)', async () => {
        const res = await authedFetch(tenantBPage, {
            method: 'PATCH',
            path: `/api/projects/${created.orgAProjectId}`,
            body: { name: 'cross-org-hijack' },
        });
        expect([403, 404]).toContain(res.status);
    });

    test('org-A super_admin GET /api/projects/{orgA-project} succeeds (control)', async () => {
        const res = await authedFetch(superAdminPage, {
            method: 'GET',
            path: `/api/projects/${created.orgAProjectId}`,
        });
        expect(res.ok).toBeTruthy();
        expect(res.status).toBe(200);
    });

    // ── UI layer ────────────────────────────────────────────────────────
    // The React route must not render the org-A project as if it were visible.
    test('org-B admin GET /projects/{orgA-project} does not render the project body', async () => {
        await tenantBPage.goto(`/projects/${created.orgAProjectId}`);

        // ProjectView surfaces 401/403/404 via the "loadError" boundary which
        // renders a back-link to /projects (see e2e/ProjectView.tsx). For
        // org-scope leaks, the API returns 404 → loadError=true → back link
        // is present. We assert the NEGATIVE — the project body is NOT
        // visible — and the POSITIVE — the back link IS visible — without
        // coupling to a specific translation key.
        const projectNameInput = tenantBPage.locator(`text=E2E cross-org`);
        await expect(projectNameInput).toHaveCount(0, { timeout: 5000 });

        const backLink = tenantBPage.getByRole('link', { name: /العودة|Back|مشاريع/i }).first();
        await expect(backLink).toBeVisible({ timeout: 10000 });
    });
});
