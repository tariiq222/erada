<?php

namespace App\Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Http\Requests\UpdateOrganizationSettingsRequest;
use App\Modules\Core\Http\Requests\ViewOrganizationSettingsRequest;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\OrganizationSettings;
use App\Modules\Shared\Models\ActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class OrganizationSettingsController extends Controller
{
    /**
     * Strictly non-mutating GET. Returns the persisted settings payload
     * if the row exists; otherwise returns the default payload WITHOUT
     * inserting a row. The persisted row is only ever created by a
     * PUT (lock-then-insert inside a transaction) so the GET path is a
     * pure read — no audit row, no lock, no DB write of any kind.
     */
    public function show(ViewOrganizationSettingsRequest $request, Organization $organization): JsonResponse
    {
        $row = OrganizationSettings::query()
            ->where('organization_id', $organization->id)
            ->first();

        return response()->json([
            'data' => $row?->settings ?? $this->defaultPayload(),
        ]);
    }

    /**
     * Deep-merge PUT.
     *
     * - Atomic row upsert: `INSERT ... ON CONFLICT (organization_id) DO
     *   NOTHING` (PostgreSQL 9.5+) is used instead of `SELECT FOR
     *   UPDATE → firstOrCreate / create`. The two naive approaches BOTH
     *   race when two concurrent PUTs arrive before the row exists:
     *   both transactions see `lockForUpdate()->first() === null` and
     *   both attempt the INSERT. The loser's unique-constraint hit
     *   raises a SQLSTATE 23505 `QueryException` — and in PostgreSQL
     *   that single 23505 marks the WHOLE transaction as in-error
     *   state, so every subsequent query inside the same transaction
     *   (including the follow-up `SELECT FOR UPDATE` that the
     *   "catch-23505-then-re-fetch" pattern used to rely on) fails
     *   with `current transaction is aborted, commands ignored until
     *   end of transaction block`. The only safe recovery is
     *   `ROLLBACK` plus a full retry of the transaction, which
     *   re-emits the audit log and re-runs the deep-merge work for
     *   what was a benign race. `ON CONFLICT DO NOTHING` is atomic at
     *   the row level: the second writer's INSERT becomes a no-op
     *   without ever raising 23505, no transaction is ever aborted,
     *   and the second writer proceeds to a normal `SELECT FOR
     *   UPDATE` (which now sees the just-inserted-or-already-present
     *   row and acquires the existing row lock if any other
     *   transaction holds it).
     * - The loser's `SELECT FOR UPDATE` then blocks on the winner's
     *   implicit row lock until the winner commits, at which point
     *   the loser sees the winner's committed `settings` and
     *   deep-merges its own payload against that committed state —
     *   last writer wins on the union, no half-state visible to
     *   either transaction.
     * - Deep merge across the three top-level keys
     *   (`locale_overrides`, `branding_overrides`, `notification_templates`).
     *   Only the keys present in the validated payload are written; sibling
     *   keys in the existing payload are preserved.
     * - Empty arrays (`notification_templates => []`) are NOT treated as
     *   deletions; they leave the existing map intact (the merge helper
     *   treats `[]` as "no changes").
     * - Explicit `null` on nullable scalar fields (e.g.
     *   `branding_overrides.primary_color => null`) clears that single
     *   key only.
     * - ActivityLog row carries `metadata.provenance='organization_super_admin'`
     *   and `metadata.request_id` from the `X-Request-Id` header so audit
     *   consumers can correlate by request id.
     */
    public function update(UpdateOrganizationSettingsRequest $request, Organization $organization): JsonResponse
    {
        $actor = $request->user();
        $validated = $request->validated();
        unset($validated['__missing_idempotency_key']); // internal sentinel, never persisted.

        $settings = DB::transaction(function () use ($actor, $organization, $validated, $request): OrganizationSettings {
            // Step 1: atomic row upsert. Either we just inserted the
            // default-payload row, or the row already existed and our
            // INSERT was a silent no-op. Either way the row is now
            // present in storage and uniquely identified by
            // `organization_id`. No SQLSTATE 23505 is ever raised; no
            // transaction is ever aborted by this statement.
            $defaultPayload = $this->defaultPayload();
            $now = now();
            DB::insert(
                'INSERT INTO organization_settings '
                .'(organization_id, settings, created_by, updated_by, created_at, updated_at) '
                .'VALUES (?, ?::jsonb, ?, NULL, ?, ?) '
                .'ON CONFLICT (organization_id) DO NOTHING',
                [
                    $organization->id,
                    json_encode($defaultPayload, JSON_THROW_ON_ERROR),
                    $actor?->id,
                    $now,
                    $now,
                ],
            );

            // Step 2: lock the row. The upsert above is committed
            // (this is a single transaction; the row is now visible to
            // our own session). `lockForUpdate` acquires the row lock
            // immediately on the no-op path; on the win path it locks
            // our own just-inserted row. If a concurrent PUT holds the
            // row lock, we block here until that transaction commits
            // or rolls back, then proceed against whichever row state
            // it left behind.
            $row = OrganizationSettings::query()
                ->where('organization_id', $organization->id)
                ->lockForUpdate()
                ->firstOrFail();

            $previous = $row->settings ?? $defaultPayload;
            $merged = $this->deepMergeSettings($previous, $validated);

            $row->fill([
                'settings' => $merged,
                'updated_by' => $actor?->id,
            ])->save();

            ActivityLog::create([
                'user_id' => $actor?->id,
                'action' => ActivityLog::ACTION_UPDATED,
                'description' => "تحديث إعدادات المؤسسة: {$organization->name}",
                'loggable_type' => Organization::class,
                'loggable_id' => $organization->id,
                'old_values' => ['settings' => $previous],
                'new_values' => ['settings' => $row->settings],
                'metadata' => [
                    'provenance' => 'organization_super_admin',
                    'request_id' => $request->header('X-Request-Id'),
                ],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            return $row->refresh();
        });

        return response()->json(['data' => $settings->settings]);
    }

    /**
     * Deep-merge the three top-level settings keys. For each key in
     * `$validated`, recursively merge into the corresponding object in
     * `$previous`. Sibling keys not present in `$validated` are preserved.
     * Empty arrays in `$validated` are no-ops (they leave the existing
     * object intact); explicit nulls on scalar fields clear that field.
     *
     * @param  array<string, mixed>  $previous
     * @param  array<string, mixed>  $validated
     * @return array{locale_overrides: array<string, string|null>, branding_overrides: array<string, string|null>, notification_templates: array<string, string>}
     */
    private function deepMergeSettings(array $previous, array $validated): array
    {
        $merged = $previous;
        foreach (['locale_overrides', 'branding_overrides', 'notification_templates'] as $topKey) {
            if (! array_key_exists($topKey, $validated)) {
                continue;
            }
            $incoming = $validated[$topKey];
            if ($incoming === []) {
                // Empty object is a no-op — leave existing object intact.
                continue;
            }
            if (! is_array($incoming)) {
                continue;
            }
            $base = $merged[$topKey] ?? [];
            // array_replace_recursive is the canonical deep-merge for
            // assoc-only arrays: incoming keys overwrite at the same
            // depth; sibling keys in $base are preserved. Explicit nulls
            // in $incoming clear that scalar field.
            $merged[$topKey] = array_replace_recursive($base, $incoming);
        }

        return [
            'locale_overrides' => $merged['locale_overrides'] ?? [],
            'branding_overrides' => $merged['branding_overrides'] ?? [],
            'notification_templates' => $merged['notification_templates'] ?? [],
        ];
    }

    /**
     * @return array{locale_overrides: array<string, string|null>, branding_overrides: array<string, string|null>, notification_templates: array<string, string>}
     */
    private function defaultPayload(): array
    {
        return [
            'locale_overrides' => [],
            'branding_overrides' => [],
            'notification_templates' => [],
        ];
    }
}
