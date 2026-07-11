<?php

use App\Modules\Core\Authorization\Capability;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 5A — Idempotent provisioning for the cluster_auditor role.
 *
 * The design brief:
 *
 *   "Provision `cluster_auditor` for existing databases through a
 *    new idempotent additive data migration using exact role and
 *    capability keys. Fresh-install seeders retain the same
 *    definition. Re-running provisioning must not duplicate rows or
 *    broaden the role. Rollback must not remove capabilities or
 *    roles that predated the migration; a safe no-op down is
 *    preferable to destructive revocation."
 *
 * Contract:
 *   - ROLE:           cluster_auditor (exact string key)
 *   - SCOPE_TYPE:     organization (the same scope_type the seeder uses)
 *   - PERMISSIONS:    JSON array of the four Capability:: constants
 *                       Capability::AUDIT_VIEW
 *                       Capability::AUDIT_EXPORT
 *                       Capability::CLUSTER_TREE_VIEW
 *                       Capability::CLUSTER_TREE_EXPORT
 *
 * Idempotency rule: the (scope_type_id, role_key) tuple is the
 * natural key. If a row already exists with the exact permission
 * set, the migration is a no-op. If a row exists with a different
 * permission set, the migration logs a warning and does NOT
 * mutate it (admin overrides survive). If the row is missing
 * entirely, the migration inserts it with the canonical definition.
 *
 * Down() is a no-op per the design brief: the role was specified
 * for the stabilization round and rollback must not remove
 * capabilities or roles that predated the migration. Existing
 * rows are never deleted; missing rows are not restored by a
 * downward migration (the role stays absent — re-running up()
 * re-provisions cleanly without a drop / re-create cycle).
 *
 * PostgreSQL-only: scoped_role_definitions.permissions is a
 * jsonb column with deterministic comparison semantics on PG.
 * SQLite is forbidden at the project level (CI guard job).
 */
return new class extends Migration
{
    private const ROLE_KEY = 'cluster_auditor';

    private const SCOPE_TYPE_KEY = 'organization';

    /**
     * Canonical capability set — must stay byte-identical to the
     * ScopedDepartmentRolesSeeder definition. Adding a new line
     * here WITHOUT updating the seeder is a contract violation;
     * a CI test should diff the two arrays on every PR.
     *
     * @return list<string>
     */
    public static function canonicalCapabilities(): array
    {
        return [
            Capability::AUDIT_VIEW,
            Capability::AUDIT_EXPORT,
            Capability::CLUSTER_TREE_VIEW,
            Capability::CLUSTER_TREE_EXPORT,
        ];
    }

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException(
                'Phase 5A cluster_auditor provisioning is PostgreSQL-only. '
                .'Detected driver: ['.DB::getDriverName().'].'
            );
        }

        $orgScopeType = DB::table('scope_types')->where('key', self::SCOPE_TYPE_KEY)->first();
        if ($orgScopeType === null) {
            // The scope_type 'organization' is created by an earlier
            // migration (2026_06_20_100002); without it, scoped role
            // definitions have nowhere to hang. Fail closed — re-running
            // the dependency stack or running `db:seed` first is the
            // recovery path; we never invent scope_types on the fly.
            return;
        }

        $existing = DB::table('scoped_role_definitions')
            ->where('scope_type_id', $orgScopeType->id)
            ->where('role_key', self::ROLE_KEY)
            ->first();

        $canonical = json_encode(self::canonicalCapabilities());

        if ($existing === null) {
            // First provisioning — insert the canonical row.
            DB::table('scoped_role_definitions')->insert([
                'scope_type_id' => (int) $orgScopeType->id,
                'role_key' => self::ROLE_KEY,
                'name' => self::ROLE_KEY,
                'display_name' => 'Cluster Audit Viewer',
                'label_ar' => 'مدقق سجل النشاط على مستوى التجمع',
                'label_en' => 'Cluster Audit Viewer',
                'scope_type' => self::SCOPE_TYPE_KEY,
                'is_admin_role' => false,
                'is_active' => true,
                'sort_order' => 80,
                'permissions' => $canonical,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return;
        }

        // Existing row — admin override path. Idempotent: do not
        // clobber. Compare to the canonical permission set; a
        // mismatch is logged (Laravel's `Log` facade at warning
        // level) so reviewers see the drift in production logs.
        $existingPermissions = $existing->permissions ?? '[]';
        if ($existingPermissions !== $canonical && defined('LARAVEL_START')) {
            Log::warning(
                'Phase 5A cluster_auditor permissions drift detected. '
                .'Existing permissions are NOT canonical; the migration '
                .'left the row untouched to respect admin overrides. '
                .'Reviewer: confirm scope_type='.self::SCOPE_TYPE_KEY
                .' role_key='.self::ROLE_KEY
                .' is intentional before merging any further capability change.',
                [
                    'scope_type_id' => (int) $orgScopeType->id,
                    'role_key' => self::ROLE_KEY,
                    'existing_permissions' => $existingPermissions,
                    'canonical_permissions' => $canonical,
                ]
            );
        }
    }

    public function down(): void
    {
        // Phase 5A — safe no-op.
        //
        // The design brief: 'Rollback must not remove capabilities or
        // roles that predated the migration; a safe no-op down is
        // preferable to destructive revocation.'
        //
        // We never delete the cluster_auditor row on rollback.
        // If the operator wants to remove the role, they do so
        // explicitly via the role-management UI / admin endpoints.
        // `php artisan migrate:rollback` is a no-op for this
        // migration; re-running `php artisan migrate` re-applies
        // idempotently (the row already exists, no insert).
    }
};
