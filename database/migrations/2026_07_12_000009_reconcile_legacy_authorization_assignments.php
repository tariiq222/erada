<?php

use App\Modules\Core\Authorization\AccessDecision;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Final additive reconciliation before the authorization cutover.
 *
 * This migration is deliberately forward-only: it copies legacy assignments
 * into the canonical table, records every decision, and never mutates legacy
 * data. Re-running it is safe because every legacy source row receives one
 * durable audit marker and canonical uniqueness is checked before insert.
 */
return new class extends Migration
{
    private const MIGRATION = '2026_07_12_000009_reconcile_legacy_authorization_assignments';

    private const EVENT = 'authorization_assignment_reconciliation_000009';

    /** @var array<string, array{table: string, organization_column: string}> */
    private const SCOPES = [
        'organization' => ['table' => 'organizations', 'organization_column' => 'id'],
        'department' => ['table' => 'departments', 'organization_column' => 'organization_id'],
        'project' => ['table' => 'projects', 'organization_column' => 'organization_id'],
        'program' => ['table' => 'programs', 'organization_column' => 'organization_id'],
        'portfolio' => ['table' => 'portfolios', 'organization_column' => 'organization_id'],
        'kpi' => ['table' => 'kpis', 'organization_column' => 'organization_id'],
        'meeting' => ['table' => 'meetings', 'organization_column' => 'organization_id'],
        'survey' => ['table' => 'surveys', 'organization_column' => 'organization_id'],
    ];

    public function up(): void
    {
        $this->assertPrerequisites();

        DB::transaction(function (): void {
            $this->reconcileSpatieRoles();
            $this->reconcileScopedRoles();
            $this->recordDirectPermissionRows();
        });

        AccessDecision::flushCache();
    }

    /**
     * Reconciliation is an operational record, not a reversible data rewrite.
     * Legacy rows remain untouched and a rollback must never delete canonical
     * assignments that may already have been used or subsequently edited.
     */
    public function down(): void
    {
        // Forward-only by design.
    }

    private function reconcileSpatieRoles(): void
    {
        $rows = DB::table('model_has_roles as mhr')
            ->join('roles as r', 'r.id', '=', 'mhr.role_id')
            ->select('mhr.role_id', 'mhr.model_type', 'mhr.model_id', 'r.name as role_name', 'r.guard_name')
            ->orderBy('mhr.role_id')
            ->orderBy('mhr.model_type')
            ->orderBy('mhr.model_id')
            ->get();

        foreach ($rows as $row) {
            $sourceKey = implode(':', ['spatie_role', $row->role_id, $row->model_type, $row->model_id]);
            if ($this->wasProcessed($sourceKey)) {
                continue;
            }

            if (! in_array((string) $row->model_type, ['App\\Models\\User', 'App\\Modules\\Core\\Models\\User'], true)) {
                $this->audit($sourceKey, 'spatie_role', 'rejected', 'unsupported_model_type', $row);

                continue;
            }
            if ($row->guard_name !== 'web') {
                $this->audit($sourceKey, 'spatie_role', 'rejected', 'unsupported_guard', $row);

                continue;
            }

            $user = DB::table('users')->where('id', $row->model_id)->first(['id', 'organization_id']);
            if ($user === null) {
                $this->audit($sourceKey, 'spatie_role', 'rejected', 'orphan_user', $row);

                continue;
            }

            $role = DB::table('authorization_roles')->where('name', $row->role_name)->first(['id']);
            if ($role === null) {
                $this->audit($sourceKey, 'spatie_role', 'rejected', 'unmapped_role', $row, (int) $user->id);

                continue;
            }

            $isGlobal = $row->role_name === 'super_admin';
            if (! $isGlobal && $user->organization_id === null) {
                $this->audit($sourceKey, 'spatie_role', 'rejected', 'user_without_organization', $row, (int) $user->id);

                continue;
            }

            $this->materialize(
                $sourceKey,
                'spatie_role',
                (int) $role->id,
                (int) $user->id,
                $isGlobal ? 'all' : 'organization',
                $isGlobal ? null : (int) $user->organization_id,
                $isGlobal ? null : (int) $user->organization_id,
                true,
                null,
                null,
                $row,
            );
        }
    }

    private function reconcileScopedRoles(): void
    {
        foreach (DB::table('model_has_scoped_roles')->orderBy('id')->get() as $row) {
            $sourceKey = 'scoped_role:'.$row->id;
            if ($this->wasProcessed($sourceKey)) {
                continue;
            }

            $user = DB::table('users')->where('id', $row->user_id)->first(['id', 'organization_id']);
            if ($user === null) {
                $this->audit($sourceKey, 'scoped_role', 'rejected', 'orphan_user', $row);

                continue;
            }

            $role = DB::table('authorization_roles')->where('name', $row->role)->first(['id']);
            if ($role === null) {
                $this->audit($sourceKey, 'scoped_role', 'rejected', 'unmapped_role', $row, (int) $user->id);

                continue;
            }

            $scope = $this->resolveScope((string) $row->scope_type, $row->scope_id);
            if ($scope['reason'] !== null) {
                $this->audit($sourceKey, 'scoped_role', 'rejected', $scope['reason'], $row, (int) $user->id);

                continue;
            }

            if ($scope['organization_id'] !== null
                && ($user->organization_id === null || (int) $user->organization_id !== $scope['organization_id'])) {
                $this->audit($sourceKey, 'scoped_role', 'rejected', 'cross_organization', $row, (int) $user->id, [
                    'user_organization_id' => $user->organization_id === null ? null : (int) $user->organization_id,
                    'scope_organization_id' => $scope['organization_id'],
                ]);

                continue;
            }

            $grantedBy = $row->granted_by !== null && DB::table('users')->where('id', $row->granted_by)->exists()
                ? (int) $row->granted_by
                : null;

            $this->materialize(
                $sourceKey,
                'scoped_role',
                (int) $role->id,
                (int) $user->id,
                (string) $row->scope_type,
                $row->scope_id === null ? null : (int) $row->scope_id,
                $scope['organization_id'],
                (bool) $row->inherit_to_children,
                $row->expires_at,
                $grantedBy,
                $row,
                $row->granted_by !== null && $grantedBy === null ? ['warning' => 'orphan_grantor_omitted'] : [],
            );
        }
    }

    private function recordDirectPermissionRows(): void
    {
        foreach (DB::table('model_has_permissions as mhp')
            ->join('permissions as p', 'p.id', '=', 'mhp.permission_id')
            ->select('mhp.permission_id', 'mhp.model_type', 'mhp.model_id', 'p.name as permission_name')
            ->orderBy('mhp.permission_id')->orderBy('mhp.model_type')->orderBy('mhp.model_id')->get() as $row) {
            $sourceKey = implode(':', ['spatie_permission', $row->permission_id, $row->model_type, $row->model_id]);
            if (! $this->wasProcessed($sourceKey)) {
                // Canonical authorization intentionally has no user-permission
                // assignment primitive. Synthesizing a role would silently
                // broaden the catalog, so this requires explicit remediation.
                $this->audit($sourceKey, 'spatie_permission', 'rejected', 'direct_permission_requires_manual_role_mapping', $row);
            }
        }
    }

    /** @return array{organization_id: ?int, reason: ?string} */
    private function resolveScope(string $type, mixed $id): array
    {
        if (in_array($type, ['all', 'own'], true)) {
            return $id === null
                ? ['organization_id' => null, 'reason' => null]
                : ['organization_id' => null, 'reason' => 'invalid_null_scope_pairing'];
        }
        if (! isset(self::SCOPES[$type])) {
            return ['organization_id' => null, 'reason' => 'unmapped_scope_type'];
        }
        if ($id === null) {
            return ['organization_id' => null, 'reason' => 'orphan_scope'];
        }

        $mapping = self::SCOPES[$type];
        if (! Schema::hasTable($mapping['table'])) {
            return ['organization_id' => null, 'reason' => 'unmapped_scope_table'];
        }
        $organizationId = DB::table($mapping['table'])->where('id', $id)->value($mapping['organization_column']);

        return $organizationId === null
            ? ['organization_id' => null, 'reason' => 'orphan_scope']
            : ['organization_id' => (int) $organizationId, 'reason' => null];
    }

    private function materialize(
        string $sourceKey,
        string $sourceType,
        int $roleId,
        int $userId,
        string $scopeType,
        ?int $scopeId,
        ?int $organizationId,
        bool $inheritToChildren,
        mixed $expiresAt,
        ?int $grantedBy,
        object $legacy,
        array $extra = [],
    ): void {
        $query = DB::table('authorization_role_assignments')
            ->where('authorization_role_id', $roleId)
            ->where('user_id', $userId)
            ->where('scope_type', $scopeType)
            ->when($scopeId === null, fn ($q) => $q->whereNull('scope_id'), fn ($q) => $q->where('scope_id', $scopeId));
        $existingId = $query->value('id');

        if ($existingId !== null) {
            $this->audit($sourceKey, $sourceType, 'preserved', 'already_canonical', $legacy, $userId, $extra + [
                'authorization_role_assignment_id' => (int) $existingId,
            ]);

            return;
        }

        $id = DB::table('authorization_role_assignments')->insertGetId([
            'authorization_role_id' => $roleId,
            'user_id' => $userId,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'organization_id' => $organizationId,
            'inherit_to_children' => $inheritToChildren,
            'expires_at' => $expiresAt,
            'source' => 'migration',
            'granted_by' => $grantedBy,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->audit($sourceKey, $sourceType, 'migrated', 'canonical_assignment_created', $legacy, $userId, $extra + [
            'authorization_role_assignment_id' => $id,
            'authorization_role_id' => $roleId,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'organization_id' => $organizationId,
            'source' => 'migration',
        ]);
    }

    private function wasProcessed(string $sourceKey): bool
    {
        return DB::table('permission_audits')
            ->where('event', self::EVENT)
            ->whereRaw("new_value ->> 'migration' = ?", [self::MIGRATION])
            ->whereRaw("new_value ->> 'source_key' = ?", [$sourceKey])
            ->exists();
    }

    private function audit(
        string $sourceKey,
        string $sourceType,
        string $outcome,
        string $reason,
        object $legacy,
        ?int $targetUserId = null,
        array $extra = [],
    ): void {
        DB::table('permission_audits')->insert([
            'event' => self::EVENT,
            'actor_id' => null,
            'target_user_id' => $targetUserId,
            'scope_type' => property_exists($legacy, 'scope_type') ? $legacy->scope_type : null,
            'scope_id' => property_exists($legacy, 'scope_id') ? $legacy->scope_id : null,
            'role' => $legacy->role_name ?? $legacy->role ?? null,
            'old_value' => json_encode((array) $legacy, JSON_THROW_ON_ERROR),
            'new_value' => json_encode($extra + [
                'migration' => self::MIGRATION,
                'source_key' => $sourceKey,
                'source_type' => $sourceType,
                'outcome' => $outcome,
                'reason' => $reason,
            ], JSON_THROW_ON_ERROR),
            'reason' => "Authorization cutover reconciliation: {$reason}",
            'ip_address' => null,
            'user_agent' => 'migration',
            'created_at' => now(),
        ]);
    }

    private function assertPrerequisites(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException(self::MIGRATION.' is PostgreSQL-only.');
        }
        foreach (['users', 'roles', 'model_has_roles', 'permissions', 'model_has_permissions', 'model_has_scoped_roles', 'authorization_roles', 'authorization_role_assignments', 'permission_audits'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException(self::MIGRATION." requires {$table}.");
            }
        }
        foreach (['inherit_to_children', 'expires_at', 'source', 'granted_by'] as $column) {
            if (! Schema::hasColumn('authorization_role_assignments', $column)) {
                throw new RuntimeException(self::MIGRATION." requires authorization_role_assignments.{$column}.");
            }
        }
    }
};
