<?php

use App\Modules\Core\Authorization\AccessDecision;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Legacy permission strings pruned in Task 8 (d1bd9831) from the
     * Permission enum and the RoleController catalog. Production DBs may
     * still carry Spatie grants on these strings; this migration clears them
     * so role assignments no longer hold dead permissions. The engine
     * (AccessDecision) never read these strings, so dropping them is a
     * pure-data hygiene step.
     */
    private const LEGACY_STRINGS = [
        'view_hr', 'manage_hr',
        'view_kpis', 'manage_kpis',
        'view_risks', 'create_risks', 'edit_risks', 'delete_risks',
        'reassess_risks', 'change_risk_status', 'view_risk_reports',
        'view_ovr_categories', 'manage_ovr_categories',
        'ovr.manage_types', 'ovr.delete_all',
        'manage_organization', 'view_settings', 'edit_settings',
    ];

    public function up(): void
    {
        $legacyIds = DB::table('permissions')
            ->whereIn('name', self::LEGACY_STRINGS)
            ->pluck('id');

        if ($legacyIds->isNotEmpty()) {
            DB::table('model_has_permissions')->whereIn('permission_id', $legacyIds)->delete();
            DB::table('role_has_permissions')->whereIn('permission_id', $legacyIds)->delete();
            DB::table('permissions')->whereIn('id', $legacyIds)->delete();
        }

        $defs = DB::table('scoped_role_definitions')
            ->whereNotNull('permissions')
            ->get(['id', 'permissions']);

        foreach ($defs as $def) {
            $perms = json_decode($def->permissions, true);
            if (! is_array($perms)) {
                continue;
            }
            $stripped = array_values(array_diff($perms, self::LEGACY_STRINGS));
            if (count($stripped) === count($perms)) {
                continue;
            }
            if (empty($stripped)) {
                DB::table('scoped_role_definitions')->where('id', $def->id)->delete();
            } else {
                DB::table('scoped_role_definitions')
                    ->where('id', $def->id)
                    ->update(['permissions' => json_encode($stripped)]);
            }
        }

        AccessDecision::flushCache();
    }

    public function down(): void
    {
        // Intentionally a no-op: the data dropped in up() is not recoverable
        // from this migration. The drop is documented in the post-cutover
        // decision (see CHANGELOG.md for the engine cutover context). A
        // future backfill would need to re-seed from a snapshot, not from a
        // missing backup. The pruned legacy permission strings
        // (view_hr, manage_hr, view_kpis, manage_kpis, view_risks,
        // create_risks, edit_risks, delete_risks, reassess_risks,
        // change_risk_status, view_risk_reports, view_ovr_categories,
        // manage_ovr_categories, ovr.manage_types, ovr.delete_all,
        // manage_organization, view_settings, edit_settings) and any
        // scoped_role_definitions.permissions entries that referenced them
        // are engine-dead: AccessDecision no longer reads them, so
        // re-adding them would not restore any access path that exists
        // today.
    }
};
