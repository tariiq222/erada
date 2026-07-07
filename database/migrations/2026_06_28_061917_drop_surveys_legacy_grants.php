<?php

use App\Modules\Core\Authorization\AccessDecision;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const SURVEYS_LEGACY_STRINGS = [
        'view_surveys', 'edit_surveys', 'create_surveys', 'delete_surveys',
    ];

    public function up(): void
    {
        DB::transaction(function () {
            $deadIds = DB::table('permissions')
                ->whereIn('name', self::SURVEYS_LEGACY_STRINGS)
                ->pluck('id');

            if ($deadIds->isNotEmpty()) {
                DB::table('model_has_permissions')->whereIn('permission_id', $deadIds)->delete();
                DB::table('role_has_permissions')->whereIn('permission_id', $deadIds)->delete();
                DB::table('permissions')->whereIn('id', $deadIds)->delete();
            }

            // Strip legacy strings from scoped_role_definitions.permissions JSON.
            $defs = DB::table('scoped_role_definitions')
                ->whereNotNull('permissions')
                ->get(['id', 'permissions']);

            foreach ($defs as $def) {
                $perms = json_decode($def->permissions, true);
                if (! is_array($perms)) {
                    continue;
                }
                $stripped = array_values(array_diff($perms, self::SURVEYS_LEGACY_STRINGS));
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
        // missing backup. The legacy Surveys permission strings
        // (view_surveys, create_surveys, edit_surveys, delete_surveys) and
        // any scoped_role_definitions.permissions entries that referenced
        // them are engine-dead: AccessDecision now reads surveys.view /
        // surveys.create / surveys.edit / surveys.delete from
        // Capability::SURVEYS_*, so re-adding the legacy strings would not
        // restore any access path that exists today.
    }
};
