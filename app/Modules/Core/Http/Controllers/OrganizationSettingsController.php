<?php

namespace App\Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Http\Requests\UpdateOrganizationSettingsRequest;
use App\Modules\Core\Http\Requests\ViewOrganizationSettingsRequest;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\OrganizationSettings;
use App\Modules\Shared\Models\ActivityLog;
use Illuminate\Database\QueryException;
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
     * - `SELECT FOR UPDATE` first: locks the row if it already exists so
     *   concurrent PUTs serialize on the existing-row path. If no row
     *   exists yet, the lock acquires nothing and we fall through to a
     *   direct INSERT (not `firstOrCreate`, which would race a second
     *   concurrent transaction into a unique-constraint violation that
     *   `lockForUpdate()->firstOrCreate()` does not catch).
     * - The INSERT can still race when two concurrent PUTs arrive before
     *   the row exists. PostgreSQL serializes the first INSERT by an
     *   implicit row lock held until commit; the loser of the race
     *   catches the SQLSTATE 23505 `QueryException` and re-fetches the
     *   row under `SELECT FOR UPDATE`, which now blocks on the
     *   committed winner. The loser's merge then proceeds against the
     *   winner's committed settings — last writer wins, no corrupt
     *   half-state visible to either transaction.
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
            $row = OrganizationSettings::query()
                ->where('organization_id', $organization->id)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                // No row exists yet. Insert the default payload. Under
                // contention, two concurrent transactions may BOTH reach
                // this branch when no row exists yet; the
                // `organization_id` unique index serializes them — the
                // winner of the INSERT commits a default row, the loser
                // catches a SQLSTATE 23505 and re-fetches under
                // `lockForUpdate()` (which now blocks on the winner's
                // implicit row lock until that transaction commits, then
                // returns the winner's row).
                try {
                    $row = OrganizationSettings::query()->create([
                        'organization_id' => $organization->id,
                        'settings' => $this->defaultPayload(),
                        'created_by' => $actor?->id,
                    ]);
                } catch (QueryException $exception) {
                    if ((string) $exception->getCode() !== '23505') {
                        throw $exception;
                    }

                    $row = OrganizationSettings::query()
                        ->where('organization_id', $organization->id)
                        ->lockForUpdate()
                        ->firstOrFail();
                }
            }

            // $row is now exclusively locked by this transaction (we
            // either locked the existing row, won the INSERT race, or
            // re-fetched under lock after losing the INSERT race — every
            // path ends with this transaction holding the row lock).
            $previous = $row->settings ?? $this->defaultPayload();
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
