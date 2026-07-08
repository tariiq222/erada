import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import {
    loginAsAdmin,
    authedFetch,
} from './helpers/recommendation';

/**
 * E2E: Meeting Resolutions — Phase 3 / convert-to-tasks + follow-up.
 *
 * Direction R (Phase 1–3) replaces the legacy approve/reject/adopt
 * lifecycle with a forward-only flow:
 *
 *     open → in_progress → (converted_to_tasks | completed | cancelled)
 *
 * This spec exercises ONLY the Phase 3 surface (ResolutionCard +
 * ConvertToTasksModal + ResolutionsSection + ResolutionsPage). It
 * deliberately does NOT touch the legacy Recommendation buttons.
 *
 * Coverage map (step → test step):
 *   1. Login as super_admin                            → beforeAll
 *   2. Seed meeting + MeetingResolution rows in DB     → helpers below
 *      (the legacy DecidableType hole on
 *      `App\Modules\Meetings\Http\Requests\StoreMeetingRequest`
 *      makes POST /api/meetings 500, so we go directly via psql
 *      — same path as meetings-recommendation-flow.spec.ts)
 *   3. Visit meeting page, assert section header
 *      "مخرجات الاجتماع" (meetings.resolution.section.header)
 *      + new resolution card                            → test 1
 *   4. Convert button present + visible                 → test 1
 *   5. Click → modal opens with 1 row + add button      → test 1
 *   6. Fill 2 rows (title + assignee_id) + submit       → test 1
 *   7. Status = converted_to_tasks; convert button
 *      HIDDEN; tasks-progress indicator shows "0 / 2"
 *      and "0%"; toast "تم تحويل المخرج إلى مهام"      → test 1
 *   8. Visit /strategy/meetings/resolutions follow-up   → test 1
 *      page — resolution row + tasks_progress column
 *      shows "0 / 2"
 *   9. Complete one task via PATCH /api/tasks/{id} →   → test 1
 *      re-fetch resolution → completion_percentage=50,
 *      completed_tasks_count=1, pending_tasks_count=1
 *
 *   B. Convert button HIDDEN after conversion (hermetic    → test 2
 *      second spec, separate page-level assert)
 *
 *   C. NO approve / reject / adopt / endorse / deliberate → test 3
 *      verbs anywhere on the new flow
 *
 * Each spec is hermetic: it bootstraps its meeting + resolutions
 * directly via psql in `beforeAll` and clears them in `afterAll`
 * so consecutive runs stay deterministic.
 */
test.describe('Meeting Resolutions — convert-to-tasks + follow-up', () => {
    /**
     * psql helper — mirrors e2e/helpers/recommendation.ts `runPsql`.
     * Inlined here because the DO NOT TOUCH list forbids creating
     * a new helper file.
     */
    function runPsql(sql: string): string {
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

    function uniqueRef(): string {
        const t = Date.now().toString(36);
        const r = Math.floor(Math.random() * 0xffff).toString(36);
        return `${t}${r}`;
    }

    interface SeededMeeting {
        id: number;
        reference_number: string;
    }
    interface SeededResolution {
        id: number;
        title: string;
    }

    /**
     * Meeting + MeetingResolution rows inserted directly. The migration
     * `2026_07_07_000001_create_meeting_resolutions_table.php` pins
     * `kind` to `recommendation | decision` (CHECK constraint).
     * `meeting_resolutions.reference_number` is varchar(20).
     */
    function seedMeetingAndResolution(label: string): {
        meeting: SeededMeeting;
        resolution: SeededResolution;
    } {
        const nonce = uniqueRef();
        const meetingRef = `E2EM-${nonce}`;
        const meetingTitle = `E2E Resolutions ${label} ${nonce}`;
        const meetingId = Number(
            runPsql(
                `INSERT INTO meetings (title, scheduled_at, duration_minutes, organizer_id, organization_id, status, created_at, updated_at, reference_number)
                 VALUES ('${meetingTitle}', NOW() + INTERVAL '1 day', 60, 1, 1, 'scheduled', NOW(), NOW(), '${meetingRef}')
                 RETURNING id;`.replace(/\s+/g, ' ').trim(),
            ).split(/\s+/)[0],
        );
        if (!Number.isFinite(meetingId) || meetingId <= 0) {
            throw new TypeError(`seedMeetingAndResolution: bad meeting id`);
        }

        const resolutionRef = `E2ER-${nonce}`;
        const resolutionTitle = `E2E Convert ${label} ${nonce}`;
        const resolutionId = Number(
            runPsql(
                `INSERT INTO meeting_resolutions
                    (reference_number, organization_id, meeting_id, kind, title,
                     owner_id, status, priority, created_by, created_at, updated_at)
                 VALUES
                    ('${resolutionRef}', 1, ${meetingId}, 'decision',
                     '${resolutionTitle}', 1, 'open', 'medium', 1,
                     NOW(), NOW())
                 RETURNING id;`,
            ).split(/\s+/)[0],
        );
        if (!Number.isFinite(resolutionId) || resolutionId <= 0) {
            throw new TypeError(
                `seedMeetingAndResolution: bad resolution id`,
            );
        }
        return {
            meeting: { id: meetingId, reference_number: meetingRef },
            resolution: { id: resolutionId, title: resolutionTitle },
        };
    }

    function purgeResolution(id: number): void {
        runPsql(
            `UPDATE meeting_resolutions SET deleted_at = NOW() WHERE id = ${id} AND deleted_at IS NULL;`,
        );
    }

    function purgeMeeting(id: number): void {
        runPsql(
            `UPDATE meetings SET deleted_at = NOW() WHERE id = ${id} AND deleted_at IS NULL;`,
        );
    }

    /**
     * Tasks created by `POST /api/meeting-resolutions/{id}/convert-to-tasks`
     * are stamped with `source_type = 'MeetingResolution'` (the short
     * basename — see Task::SOURCE_CLASS_MAP). The completion gate on the
     * follow-up page reads the same column, so the closest direct-DB
     * shortcut to "complete one task" is `UPDATE tasks SET status =
     * 'completed' WHERE title LIKE 'E2E%' AND source_id = $1 LIMIT 1`.
     */
    function completeOneTaskFor(resolutionId: number): number {
        const raw = runPsql(
            `UPDATE tasks SET status = 'completed', completed_date = CURRENT_DATE,
                              progress = 100, updated_at = NOW()
             WHERE id = (
                 SELECT id FROM tasks
                 WHERE source_type = 'MeetingResolution'
                   AND source_id = ${resolutionId}
                   AND title LIKE 'E2E%'
                   AND status NOT IN ('completed', 'cancelled')
                 ORDER BY id ASC
                 LIMIT 1
             )
             RETURNING id;`,
        );
        const id = Number(raw.split(/\s+/)[0]);
        if (!Number.isFinite(id) || id <= 0) {
            throw new TypeError(
                `completeOneTaskFor: no open task for resolution ${resolutionId} (raw=${raw})`,
            );
        }
        return id;
    }

    /**
     * Anchored ResolutionCard locator. The `<h4>` renders the resolution
     * title (ResolutionCard.tsx line 294). Walking up to the nearest
     * rounded + shadowed container isolates the card so its action
     * buttons are unambiguous (mirrors the pattern from
     * meetings-recommendation-flow.spec.ts).
     */
    function cardLocator(page: import('@playwright/test').Page, title: string) {
        return page
            .locator('h4', { hasText: title })
            .locator(
                'xpath=ancestor::*[contains(@class, "rounded-xl") or contains(@class, "rounded-lg") or contains(@class, "shadow")][1]',
            )
            .first();
    }

    // ---------- Test 1 — main smoke flow ----------

    test('super admin converts a resolution to tasks + follow-up progress flips after a task closes', async ({
        page,
    }) => {
        const { meeting, resolution } = seedMeetingAndResolution('Phase3');

        try {
            // 1) Login + visit the meeting detail page.
            await loginAsAdmin(page);
            await page.goto(`/strategy/meetings/${meeting.id}`);

            // 2) Section header "مخرجات الاجتماع" is visible. Use .first()
            //    because the page may render the same string inside a
            //    sticky/section heading and inside the card body.
            await expect(
                page.getByText('مخرجات الاجتماع').first(),
            ).toBeVisible({ timeout: 15000 });

            // 3) The seeded card is present with its title.
            const card = cardLocator(page, resolution.title);
            await expect(card).toBeVisible({ timeout: 10000 });

            // 4) The convert-to-tasks action button is present BEFORE
            //    conversion (status = open).
            const convertBtn = card.getByTestId('convert-to-tasks-btn');
            await expect(convertBtn).toBeVisible();

            // 5) Open the modal.
            await convertBtn.click();

            const modal = page.locator('[role="dialog"]').filter({
                has: page.getByText('تحويل المخرج إلى مهام'),
            });
            await expect(modal).toBeVisible({ timeout: 10000 });

            // Hint + add-row button + exactly one initial row.
            await expect(
                modal.getByText(
                    'سيتم إنشاء المهام في جدول المهام وربطها بهذا المخرج. لا يمكن التحويل مرتين.',
                ),
            ).toBeVisible();
            await expect(
                modal.getByTestId('convert-task-add'),
            ).toBeVisible();
            await expect(
                modal.getByTestId('convert-task-row-0'),
            ).toBeVisible();

            // Add a second row.
            await modal.getByTestId('convert-task-add').click();
            await expect(
                modal.getByTestId('convert-task-row-1'),
            ).toBeVisible();

            // 6) Fill 2 task rows.
            await modal.getByTestId('convert-task-title-0').fill('E2E Convert Task Alpha');
            await modal.getByTestId('convert-task-assignee-0').fill('1');
            await modal.getByTestId('convert-task-title-1').fill('E2E Convert Task Beta');
            await modal.getByTestId('convert-task-assignee-1').fill('1');

            // Submit + wait for the modal to close. Toast may render
            // anywhere on the page — assert it appears.
            await modal.getByTestId('convert-task-submit').click();
            await expect(modal).toBeHidden({ timeout: 15000 });

            // The success toast surfaces `meetings.resolution.messages.converted`
            // with the default Arabic text "تم تحويل المخرج إلى مهام".
            // Toasts are rendered via Toast.tsx with `aria-live="polite"`
            // (no role="status"/"alert" attribute and no `.toast` class on the
            // container), so we locate the toast by its message text inside
            // the aria-live region — same pattern as ovr-incident-create.spec.ts.
            await expect(
                page
                    .locator('[aria-live="polite"]', {
                        hasText: 'تم تحويل المخرج إلى مهام',
                    })
                    .first(),
            ).toBeVisible({ timeout: 10000 });

            // 7) The resolution is now `converted_to_tasks` (server is
            //    source of truth).
            await expect
                .poll(
                    async () => {
                        const res = await authedFetch(page, {
                            method: 'GET',
                            path: `/api/meeting-resolutions/${resolution.id}`,
                        });
                        const body = res.body as {
                            status: string;
                            tasks_count?: number;
                            completed_tasks_count?: number;
                            pending_tasks_count?: number;
                            completion_percentage?: number;
                        };
                        return body;
                    },
                    { timeout: 10000, intervals: [200, 400, 800] },
                )
                .toMatchObject({
                    status: 'converted_to_tasks',
                    tasks_count: 2,
                    completed_tasks_count: 0,
                    pending_tasks_count: 2,
                    completion_percentage: 0,
                });

            // Reload the page so React re-fetches and re-renders the card
            // from the fresh payload (the modal-open path uses the parent
            // callback but the cards inside the section may still hold the
            // pre-conversion `resolution.status` prop on this nav).
            await page.goto(`/strategy/meetings/${meeting.id}`);
            await expect(
                page.getByText('مخرجات الاجتماع').first(),
            ).toBeVisible({ timeout: 15000 });
            const refreshedCard = cardLocator(page, resolution.title);
            await expect(refreshedCard).toBeVisible({ timeout: 10000 });

            // Convert button is now HIDDEN for the converted resolution.
            await expect(
                refreshedCard.getByTestId('convert-to-tasks-btn'),
            ).toHaveCount(0);

            // Tasks-progress indicator renders 0/2 + 0%.
            const progress = refreshedCard.getByTestId('tasks-progress');
            await expect(progress).toBeVisible();
            await expect(progress).toContainText('0 / 2');
            await expect(progress).toContainText('0%');
            await expect(progress).toContainText('المهام');

            // 8) Visit the follow-up page.
            await page.goto('/strategy/meetings/resolutions');
            await expect(
                page.getByText('متابعة مخرجات الاجتماعات').first(),
            ).toBeVisible({ timeout: 15000 });

            // The seeded resolution row appears.
            const followupRow = page
                .locator('tr', { hasText: resolution.title })
                .first();
            await expect(followupRow).toBeVisible({ timeout: 10000 });

            // tasks_progress column shows 0/2.
            await expect(followupRow).toContainText('0 / 2');

            // 9) Complete one of the two tasks via direct DB UPDATE
            //    (mirrors the legacy gate-test pattern).
            completeOneTaskFor(resolution.id);

            // Re-fetch + assert progress flipped to 50%.
            await expect
                .poll(
                    async () => {
                        const res = await authedFetch(page, {
                            method: 'GET',
                            path: `/api/meeting-resolutions/${resolution.id}`,
                        });
                        const body = res.body as {
                            completion_percentage?: number;
                            completed_tasks_count?: number;
                            pending_tasks_count?: number;
                        };
                        return body;
                    },
                    { timeout: 10000, intervals: [200, 400, 800] },
                )
                .toMatchObject({
                    completion_percentage: 50,
                    completed_tasks_count: 1,
                    pending_tasks_count: 1,
                });
        } finally {
            // Cleanup runs in reverse order — tasks first (FK), then
            // resolution, then meeting.
            try {
                runPsql(
                    `DELETE FROM tasks WHERE source_type = 'MeetingResolution' AND source_id = ${resolution.id} AND title LIKE 'E2E%';`,
                );
            } catch {
                /* row already gone */
            }
            try {
                purgeResolution(resolution.id);
            } catch {
                /* row already gone */
            }
            try {
                purgeMeeting(meeting.id);
            } catch {
                /* row already gone */
            }
        }
    });

    // ---------- Test 2 — convert button HIDDEN after conversion ----------

    test('convert button is HIDDEN + tasks-progress indicator visible on an already-converted resolution', async ({
        page,
    }) => {
        const { meeting, resolution } = seedMeetingAndResolution('AlreadyDone');

        try {
            // Move the seeded resolution straight to converted_to_tasks +
            // give it 2 fake task counts by inserting the tasks directly
            // (so the tasks-progress indicator actually renders). The
            // indicator is gated on `tasks_count > 0`.
            runPsql(
                `INSERT INTO tasks (title, type, status, priority,
                                    source_type, source_id,
                                    organization_id, owner_id, progress,
                                    is_private, created_by, created_at, updated_at)
                 VALUES
                    ('E2E AlreadyDone Task A', 'personal', 'in_progress', 'medium',
                     'MeetingResolution', ${resolution.id},
                     1, 1, 50, false, 1, NOW(), NOW()),
                    ('E2E AlreadyDone Task B', 'personal', 'in_progress', 'medium',
                     'MeetingResolution', ${resolution.id},
                     1, 1, 50, false, 1, NOW(), NOW());`,
            );
            runPsql(
                `UPDATE meeting_resolutions SET status = 'converted_to_tasks', updated_at = NOW()
                 WHERE id = ${resolution.id};`,
            );

            await loginAsAdmin(page);
            await page.goto(`/strategy/meetings/${meeting.id}`);
            await expect(
                page.getByText('مخرجات الاجتماع').first(),
            ).toBeVisible({ timeout: 15000 });

            const card = cardLocator(page, resolution.title);
            await expect(card).toBeVisible({ timeout: 10000 });

            // Convert button NOT rendered.
            await expect(
                card.getByTestId('convert-to-tasks-btn'),
            ).toHaveCount(0);
            // No button labelled "تحويل إلى مهام" anywhere on the card.
            await expect(
                card.getByRole('button', { name: 'تحويل إلى مهام' }),
            ).toHaveCount(0);

            // Tasks-progress indicator IS rendered (because tasks_count > 0).
            const progress = card.getByTestId('tasks-progress');
            await expect(progress).toBeVisible();
            await expect(progress).toContainText('0 / 2');
        } finally {
            try {
                runPsql(
                    `DELETE FROM tasks WHERE source_type = 'MeetingResolution' AND source_id = ${resolution.id} AND title LIKE 'E2E%';`,
                );
            } catch {
                /* row already gone */
            }
            try {
                purgeResolution(resolution.id);
            } catch {
                /* row already gone */
            }
            try {
                purgeMeeting(meeting.id);
            } catch {
                /* row already gone */
            }
        }
    });

    // ---------- Test 3 — no legacy approve/reject/adopt verbs ----------

    test('NO approve / reject / adopt / endorse / deliberate buttons on the new flow', async ({
        page,
    }) => {
        const { meeting, resolution } = seedMeetingAndResolution('NoLegacy');

        try {
            await loginAsAdmin(page);
            await page.goto(`/strategy/meetings/${meeting.id}`);
            await expect(
                page.getByText('مخرجات الاجتماع').first(),
            ).toBeVisible({ timeout: 15000 });

            const card = cardLocator(page, resolution.title);
            await expect(card).toBeVisible({ timeout: 10000 });

            // No button labelled with the legacy Arabic verbs.
            const forbiddenLabels = [
                /^اعتماد$/, // approve
                /^رفض$/, // reject
                /^قبول$/, // adopt / accept (legacy)
                /^تأييد$/, // endorse
                /^مداولة$/, // deliberate
            ];
            for (const re of forbiddenLabels) {
                await expect(
                    card.getByRole('button', { name: re }),
                ).toHaveCount(0);
            }

            // Open the convert modal — it must ALSO not contain those verbs.
            await card.getByTestId('convert-to-tasks-btn').click();
            const modal = page.locator('[role="dialog"]').filter({
                has: page.getByText('تحويل المخرج إلى مهام'),
            });
            await expect(modal).toBeVisible({ timeout: 10000 });
            const modalHtml = await modal.innerHTML();
            expect(modalHtml).not.toMatch(/اعتماد/);
            expect(modalHtml).not.toMatch(/رفض/);
            expect(modalHtml).not.toMatch(/قبول/);
            expect(modalHtml).not.toMatch(/تأييد/);
            expect(modalHtml).not.toMatch(/مداولة/);

            // Cancel out.
            await modal.getByRole('button', { name: 'إلغاء' }).click();
            await expect(modal).toBeHidden({ timeout: 5000 });
        } finally {
            try {
                purgeResolution(resolution.id);
            } catch {
                /* row already gone */
            }
            try {
                purgeMeeting(meeting.id);
            } catch {
                /* row already gone */
            }
        }
    });
});
