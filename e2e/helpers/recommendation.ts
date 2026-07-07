import type { Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';

/**
 * The Task source-aware completion gate queries
 * `tasks WHERE source_type = recommendation_fqcn AND source_id = ?`.
 * Posting `source_type: 'Recommendation'` to /api/unified-tasks stores
 * 'Recommendation' verbatim — the gate then sees no match. Pass the
 * FQCN so the test exercises the actual production semantics.
 */
export const RECOMMENDATION_FQCN = String.raw`App\Modules\Meetings\Models\Recommendation`;

/**
 * Shared utilities for the recommendation E2E suites.
 *
 * Both specs run against the dev stack on http://localhost:8000 (see
 * playwright.config.ts). The dev DB has only the base seed (admin /
 * manager / pm users + portal org) — no meetings, no recommendations.
 *
 * IMPORTANT — bootstrap path: Phase R2 of Direction B removed the
 * `DecidableType` support class but left dangling `use` statements in
 * `MeetingController`, `StoreMeetingRequest`, and
 * `UpdateMeetingRequest`. That makes POST /api/meetings return 500.
 * To stay unblocked while the Phase R2 hole is closed we bootstrap
 * meetings and recommendations DIRECTLY in the database via psql,
 * then clean them up the same way. Recommendation routes do not
 * touch DecidableType and remain healthy.
 */
const DEV_BASE = 'http://localhost:8000';

/**
 * Logs in as the seeded super_admin via the real login form. The SPA
 * picks up the Sanctum stateful cookie + XSRF cookie on its own.
 */
export async function loginAsAdmin(page: Page): Promise<void> {
    await page.goto('/login');
    await page.waitForSelector('input[type="email"]', { timeout: 15000 });
    await page.fill('input[type="email"]', 'admin@admin.com');
    await page.fill('input[type="password"]', 'password');
    await page.click('button[type="submit"]');
    await page.waitForURL('**/dashboard', { timeout: 15000 });
}

/**
 * Sends a JSON request through the SPA's fetch stack so the live
 * session cookie + XSRF cookie are honoured (the only working CSRF
 * path against APP_ENV=local — see e2e/cross-org-isolation.spec.ts).
 */
export async function authedFetch(
    page: Page,
    opts: { method: string; path: string; body?: unknown },
): Promise<{ status: number; body: unknown; ok: boolean }> {
    return page.evaluate(
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
                    .find((c) => c.startsWith('XSRF-TOKEN='))
                    ?.split('=')[1] ?? '';
                headers['X-XSRF-TOKEN'] = decodeURIComponent(xsrf);
            }
            const res = await fetch(`${location.origin}${p}`, {
                method: m,
                credentials: 'include',
                headers,
                body: b === undefined ? undefined : JSON.stringify(b),
            });
            let parsed: unknown = null;
            try {
                parsed = await res.json();
            } catch {
                /* empty / non-JSON body */
            }
            return { status: res.status, body: parsed, ok: res.ok };
        },
        { m: opts.method, p: opts.path, b: opts.body },
    );
}

function runPsql(sql: string): string {
    // `-q` keeps psql from emitting `INSERT 0 1` notices into stdout;
    // `-t -A` gives a plain single-column scalar. Even so psql may
    // emit the trailing notice on stdout — `split(/\s+/)` strips it.
    return execFileSync(
        'docker',
        [
            'compose',
            'exec',
            '-T',
            'postgres',
            'psql',
            '-q',
            '-U',
            'iradah',
            '-d',
            'iradah_pmo',
            '-t',
            '-A',
            '-c',
            sql,
        ],
        { encoding: 'utf-8' },
    ).trim();
}

/**
 * Short (≤14 chars) base36 nonce so reference numbers stay under
 * the 20-char `varchar(20)` cap together with the `E2E-` prefix.
 * `Date.now().toString(36)` is 8 chars; a 4-char random suffix
 * survives parallel workers that share a millisecond.
 */
function uniqueNonce(): string {
    const t = Date.now().toString(36);
    const r = Math.floor(Math.random() * 0xffff).toString(36);
    return `${t}${r}`;
}

export interface CreatedMeeting {
    id: number;
    reference_number: string;
    title: string;
}

/**
 * Inserts a meeting row directly into the DB. Returns the new id.
 * The reference number embeds a timestamp + random nonce so
 * parallel Playwright workers do not collide on the
 * `meetings_reference_number_unique` partial index.
 */
export function seedMeeting(label: string): CreatedMeeting {
    const nonce = uniqueNonce();
    const reference = `E2E-${nonce}`;
    const title = `E2E ${label} ${nonce}`;
    const raw = runPsql(
        `INSERT INTO meetings (title, scheduled_at, duration_minutes, organizer_id, organization_id, status, created_at, updated_at, reference_number)
         VALUES ('${title}', NOW() + INTERVAL '1 day', 60, 1, 1, 'scheduled', NOW(), NOW(), '${reference}')
         RETURNING id;`,
    );
    const id = Number(raw.split(/\s+/)[0]);
    if (!Number.isFinite(id) || id <= 0) {
        throw new TypeError(`seedMeeting returned non-numeric id: ${raw}`);
    }
    return { id, reference_number: reference, title };
}

/**
 * Soft-deletes a meeting row (sets deleted_at + cleans up).
 */
export function purgeMeeting(id: number): void {
    runPsql(
        `UPDATE meetings SET deleted_at = NOW() WHERE id = ${id} AND deleted_at IS NULL;`,
    );
}

export interface CreatedRecommendation {
    id: number;
    title: string;
}

/**
 * Inserts a recommendation row directly. The Direction B columns
 * (kind, type, meeting_id, etc.) accept any of the supported values.
 * Returns the new id.
 */
export function seedRecommendation(args: {
    titleSeed?: string;
    meetingId: number;
    kind: 'ruling' | 'action_item';
    type?: 'approval' | string | null;
    assigneeId?: number;
    dueDate?: string;
}): CreatedRecommendation {
    const nonce = uniqueNonce();
    const title = args.titleSeed
        ? `${args.titleSeed} ${nonce}`
        : `E2E Rec ${nonce}`;
    const refNumber = `E2E-REC-${nonce}`;
    const status = args.kind === 'ruling' ? 'pending' : 'proposed';
    const safeTitle = title.replace(/'/g, "''");
    const safeType = args.type
        ? `'${args.type.replace(/'/g, "''")}'`
        : 'NULL';
    const assigneeClause = args.assigneeId ?? 'NULL';
    const dueClause = args.dueDate ? `'${args.dueDate}'` : 'NULL';
    const sql = `
        INSERT INTO recommendations
            (title, kind, status, priority, meeting_id, type, assignee_id,
             due_date, organization_id, reference_number, created_at, updated_at)
        VALUES
            ('${safeTitle}', '${args.kind}', '${status}',
             'medium', ${args.meetingId}, ${safeType}, ${assigneeClause},
             ${dueClause}, 1, '${refNumber}', NOW(), NOW())
        RETURNING id;`;
    const raw = runPsql(sql);
    const id = Number(raw.split(/\s+/)[0]);
    if (!Number.isFinite(id) || id <= 0) {
        throw new TypeError(
            `seedRecommendation returned non-numeric id: ${raw}`,
        );
    }
    return { id, title };
}

export function purgeRecommendation(id: number): void {
    runPsql(
        `DELETE FROM recommendations WHERE id = ${id} AND title LIKE 'E2E%';`,
    );
}

/**
 * Inserts a Task row with `source_type` set to the Recommendation
 * FQCN. Phase R2 did NOT add `source_type` / `source_id` to the
 * Task model's `$fillable`, so POST /api/unified-tasks silently
 * drops them. To exercise the production completion gate
 * (RecommendationController::pendingTaskIdsFor) we have to bypass
 * the model and write the link directly.
 */
export interface CreatedTask {
    id: number;
    title: string;
}

export function seedTaskLinkedToRecommendation(args: {
    titleSeed?: string;
    recommendationId: number;
    status?: 'todo' | 'in_progress' | 'in_review' | 'completed' | 'cancelled';
}): CreatedTask {
    const nonce = uniqueNonce();
    const title = args.titleSeed
        ? `${args.titleSeed} ${nonce}`
        : `E2E Task ${nonce}`;
    const status = args.status ?? 'in_progress';
    const safeTitle = title.replaceAll(`'`, `''`);
    // PostgreSQL `text` literals interpret `\` as a plain character.
    // The PHP accessor resolves Recommendation::class to
    // "App\Modules\Meetings\Models\Recommendation" — single backslashes
    // — which is exactly what we want stored here. Sending the JS
    // string verbatim (no doubling) makes the byte sequence match.
    const sql = `
        INSERT INTO tasks
            (title, type, status, priority, source_type, source_id,
             organization_id, owner_id, progress, is_private,
             created_by, created_at, updated_at)
        VALUES
            ('${safeTitle}', 'personal', '${status}', 'medium',
             '${RECOMMENDATION_FQCN}', ${args.recommendationId},
             1, 1, 0, false,
             1, NOW(), NOW())
        RETURNING id;`;
    const raw = runPsql(sql);
    const id = Number(raw.split(/\s+/)[0]);
    if (!Number.isFinite(id) || id <= 0) {
        throw new TypeError(`seedTask returned non-numeric id: ${raw}`);
    }
    return { id, title };
}

export function purgeTask(id: number): void {
    runPsql(`DELETE FROM tasks WHERE id = ${id} AND title LIKE 'E2E%';`);
}

/**
 * Marks a task as completed for tests that need to clear the
 * recommendation completion gate. The unified-tasks completion
 * endpoint is not wired in Phase R3, so the seed link + close is
 * driven entirely from the DB. Returns the task's id (for chaining).
 */
export function closeTaskDirect(taskId: number): void {
    runPsql(
        `UPDATE tasks SET status='completed', completed_date=CURRENT_DATE, progress=100, updated_at=NOW() WHERE id = ${taskId} AND title LIKE 'E2E%';`,
    );
}

export { DEV_BASE };
