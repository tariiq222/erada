<?php

use App\Modules\Core\Authorization\AccessDecision;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const DEAD_LADDER_STRINGS = [
        'view_own_projects', 'view_department_projects',
        'view_own_tasks', 'view_department_tasks',
        'ovr.view_own', 'ovr.view_department',
        'ovr.edit_own', 'ovr.delete_own',
    ];

    public function up(): void
    {
        DB::transaction(function () {
            // Rename ovr.view_confidential -> ovr.confidential (matches Capability::OVR_CONFIDENTIAL).
            DB::table('permissions')
                ->where('name', 'ovr.view_confidential')
                ->update(['name' => 'ovr.confidential']);

            // Drop dead ladder grants.
            $deadIds = DB::table('permissions')
                ->whereIn('name', self::DEAD_LADDER_STRINGS)
                ->pluck('id');

            if ($deadIds->isNotEmpty()) {
                DB::table('model_has_permissions')->whereIn('permission_id', $deadIds)->delete();
                DB::table('role_has_permissions')->whereIn('permission_id', $deadIds)->delete();
                DB::table('permissions')->whereIn('id', $deadIds)->delete();
            }

            // Strip legacy ladder strings from scoped_role_definitions.permissions JSON.
            $defs = DB::table('scoped_role_definitions')
                ->whereNotNull('permissions')
                ->get(['id', 'permissions']);

            foreach ($defs as $def) {
                $perms = json_decode($def->permissions, true);
                if (! is_array($perms)) {
                    continue;
                }
                $stripped = array_values(array_diff($perms, self::DEAD_LADDER_STRINGS));
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
        });

        AccessDecision::flushCache();
    }

    public function down(): void
    {
        // Intentionally a no-op: the data dropped in up() is not recoverable
        // from this migration. The drop is documented in the post-cutover
        // decision (see CHANGELOG.md for the engine cutover context). A
        // future backfill would need to re-seed from a snapshot, not from a
        // missing backup. The legacy view-ladder permission strings
        // (view_own_*, view_department_*, ovr.view_own, ovr.view_department,
        // ovr.edit_own, ovr.delete_own) and the ovr.view_confidential rename
        // are engine-dead: AccessDecision no longer reads them, so re-adding
        // them would not restore any access path that exists today.
    }
};
