<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Idempotent data cleanup: strip the legacy 'ovr.view_confidential' string
 * from `scoped_role_definitions.permissions` JSON wherever it appears.
 *
 * Following commit 655d50c7 ("fix(authz): OVR engine-only + retire
 * OVR_VIEW_CONFIDENTIAL dual-key"), no application code path reads that
 * key — the dual-key check was removed from IncidentReportPolicy,
 * OvrAuthorizationService::mayViewConfidential, Task::userMayViewConfidential,
 * and TaskPolicy::userMayViewConfidential in favor of the canonical
 * OVR_CONFIDENTIAL. Capability::OVR_VIEW_CONFIDENTIAL is retained ONLY as
 * a class-load shim for the already-applied migration
 * 2026_07_05_000027 (LR-004 forbids editing applied migrations).
 *
 * Without this strip, old role-definitions still carry the dead key in
 * their permissions JSON, which would be confusing in role-management UI
 * and noisy in audit reports. The capability engine ignores the key
 * today, but the cleanup closes the loop for ops dashboards.
 */
return new class extends Migration
{
    private const MIGRATION_NAME = '2026_07_07_000010_strip_legacy_ovr_view_confidential';

    private const LEGACY_KEY = 'ovr.view_confidential';

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return; // irrelevant on non-PG drivers; scoped_role_definitions is PG-only.
        }

        $rows = DB::table('scoped_role_definitions')
            ->select(['id', 'permissions'])
            ->whereNotNull('permissions')
            ->get();

        $touched = 0;
        foreach ($rows as $row) {
            $decoded = json_decode($row->permissions, true);
            if (! is_array($decoded) || ! in_array(self::LEGACY_KEY, $decoded, true)) {
                continue;
            }

            $filtered = array_values(array_filter(
                $decoded,
                fn ($p) => is_string($p) && $p !== self::LEGACY_KEY
            ));

            DB::table('scoped_role_definitions')
                ->where('id', $row->id)
                ->update(['permissions' => json_encode($filtered)]);

            $touched++;
        }

        // Idempotent; no-op on a clean install.
        // \Log::info(self::MIGRATION_NAME.': stripped legacy OVR confidential key from '.$touched.' rows.');
    }

    public function down(): void
    {
        // No reverse path: the legacy key is dead code and re-adding it
        // would re-introduce the dual-key check we explicitly retired.
    }
};
