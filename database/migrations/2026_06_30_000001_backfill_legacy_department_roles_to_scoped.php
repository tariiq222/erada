<?php

use App\Modules\HR\Support\LegacyRoleMap;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 — additive backfill of the legacy department-role data onto the
 * scoped model. Idempotent (existence checks), reversible, and audited.
 *
 *  - department_default_roles -> department_capacity_roles (capacity='member')
 *  - department_role_grants   -> model_has_scoped_roles (source='auto')
 *
 * Run BEFORE the drop migration (2026_06_30_000002). After running, execute
 * `php artisan roles:reconcile` to recompute auto rows from HR facts and
 * self-correct any gap (e.g. manager-capacity rows the legacy member-only
 * model never expressed).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Defensive: if the legacy tables are already gone (e.g. a re-run after
        // the drop migration), there is nothing to backfill.
        if (! Schema::hasTable('department_default_roles') || ! Schema::hasTable('department_role_grants')) {
            return;
        }

        $now = now();

        // 1. policy: department_default_roles -> department_capacity_roles (member)
        $policies = DB::table('department_default_roles as ddr')
            ->join('roles as r', 'r.id', '=', 'ddr.role_id')
            ->select('ddr.department_id', 'r.name')->get();

        foreach ($policies as $row) {
            $key = LegacyRoleMap::toScopedKey($row->name);
            if ($key === null) {
                continue;
            }

            $exists = DB::table('department_capacity_roles')->where([
                'department_id' => $row->department_id, 'capacity' => 'member', 'role_key' => $key,
            ])->exists();

            if (! $exists) {
                DB::table('department_capacity_roles')->insert([
                    'department_id' => $row->department_id, 'capacity' => 'member', 'role_key' => $key,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }

        // 2. grants: department_role_grants -> model_has_scoped_roles (auto)
        $grants = DB::table('department_role_grants as g')
            ->join('roles as r', 'r.id', '=', 'g.role_id')
            ->select('g.user_id', 'g.department_id', 'r.name')->get();

        foreach ($grants as $row) {
            $key = LegacyRoleMap::toScopedKey($row->name);
            if ($key === null) {
                continue;
            }

            // Source-agnostic existence check: the unique key is
            // (user_id, role, scope_type, scope_id) and does NOT include source.
            // If a row already exists (manual or auto) we skip — a manual row
            // shadows and protects the grant, exactly like grantAutoScopedRole().
            $exists = DB::table('model_has_scoped_roles')->where([
                'user_id' => $row->user_id, 'role' => $key,
                'scope_type' => 'department', 'scope_id' => $row->department_id,
            ])->exists();

            if (! $exists) {
                DB::table('model_has_scoped_roles')->insert([
                    'user_id' => $row->user_id, 'role' => $key,
                    'scope_type' => 'department', 'scope_id' => $row->department_id,
                    'inherit_to_children' => true, 'granted_by' => null, 'source' => 'auto',
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }

        DB::table('permission_audits')->insert([
            'event' => 'migration', 'actor_id' => null, 'target_user_id' => null,
            'scope_type' => null, 'scope_id' => null, 'role' => null, 'old_value' => null,
            'new_value' => json_encode([
                'migration' => 'backfill_legacy_department_roles_to_scoped',
                'policies' => $policies->count(), 'grants' => $grants->count(),
            ]),
            'reason' => 'Phase 4: backfill legacy department roles to scoped model',
            'ip_address' => null, 'user_agent' => 'migration', 'created_at' => $now,
        ]);
    }

    public function down(): void
    {
        // CRITICAL: do NOT blanket-delete source='auto' rows — that would erase
        // the legitimate auto grants produced by Phase 1's normal sync, not just
        // the backfill. Delete ONLY rows that correspond to a legacy grant (same
        // user + department + mapped key) AND only while the legacy table still
        // exists (this migration runs before the drop migration 000002).
        if (! Schema::hasTable('department_role_grants')) {
            return; // legacy tables already dropped — rely on roles:reconcile to rebuild
        }

        $grants = DB::table('department_role_grants as g')
            ->join('roles as r', 'r.id', '=', 'g.role_id')
            ->select('g.user_id', 'g.department_id', 'r.name')->get();

        foreach ($grants as $row) {
            $key = LegacyRoleMap::toScopedKey($row->name);
            if ($key === null) {
                continue;
            }

            DB::table('model_has_scoped_roles')
                ->where('user_id', $row->user_id)
                ->where('role', $key)
                ->where('scope_type', 'department')
                ->where('scope_id', $row->department_id)
                ->where('source', 'auto')
                ->delete();
        }

        // Capacity policy rows are left in place (reproducible from the still-present
        // legacy tables); operator may truncate department_capacity_roles manually if needed.
    }
};
