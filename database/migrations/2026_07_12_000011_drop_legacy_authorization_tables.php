<?php

use App\Modules\Core\Authorization\CapabilityAlias;
use App\Modules\Core\Authorization\Support\CapabilityToAuthorizationRolePermission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Irreversible authorization cutover cleanup.
 *
 * Migration 000009 reconciled legacy assignments into the canonical store and
 * 000010 preserved their audit history under its canonical name. This final
 * schema step removes only the retired Spatie/scoped-role stores. All
 * prerequisites are checked before the first destructive statement so an
 * incomplete deployment fails without partially removing the rollback data.
 */
return new class extends Migration
{
    private const MIGRATION_NAME = '2026_07_12_000011_drop_legacy_authorization_tables';

    private const RECONCILIATION_MIGRATION = '2026_07_12_000009_reconcile_legacy_authorization_assignments';

    private const RECONCILIATION_EVENT = 'authorization_assignment_reconciliation_000009';

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

    /** @var list<string> */
    private const CANONICAL_TABLES = [
        'authorization_roles',
        'authorization_resources',
        'authorization_role_permissions',
        'authorization_role_assignments',
        'authorization_record_rules',
        'authorization_decision_audits',
        'authorization_assignment_audits',
    ];

    /**
     * Dependency-safe order: child/pivot tables precede their parents.
     *
     * @var list<string>
     */
    private const LEGACY_TABLES = [
        'model_has_scoped_roles',
        'scoped_role_definitions',
        'scope_types',
        'role_has_permissions',
        'model_has_permissions',
        'model_has_roles',
        'permissions',
        'roles',
    ];

    public function up(): void
    {
        $this->assertCanonicalPrerequisites();
        $this->assertLegacyAssignmentsWereReconciled();

        foreach (self::LEGACY_TABLES as $table) {
            Schema::dropIfExists($table);
        }
    }

    /**
     * Forward-only by design.
     *
     * The removed legacy stores are not authoritative after cutover and cannot
     * be reconstructed safely from canonical rows. Rollback requires restoring
     * a pre-cutover database snapshot, never recreating empty legacy tables.
     */
    public function down(): void
    {
        // Intentionally irreversible.
    }

    private function assertCanonicalPrerequisites(): void
    {
        foreach (self::CANONICAL_TABLES as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException(self::MIGRATION_NAME." requires {$table} before legacy authorization cleanup.");
            }
        }
    }

    /**
     * Prove that the legacy stores contain no authorization decision that
     * would be lost by this migration. These checks deliberately read only
     * raw tables and durable reconciliation receipts: the retired package and
     * its models must not be required to deploy the final cutover.
     */
    private function assertLegacyAssignmentsWereReconciled(): void
    {
        if (DB::table('model_has_permissions')->exists()) {
            throw new RuntimeException(self::MIGRATION_NAME.' refuses to drop unresolved direct permission assignments.');
        }

        $this->assertLegacyRolePermissionsWereReconciled();

        $unresolvedReceipt = DB::table('authorization_assignment_audits')
            ->where('event', self::RECONCILIATION_EVENT)
            ->whereRaw("new_value ->> 'migration' = ?", [self::RECONCILIATION_MIGRATION])
            ->where(function ($query): void {
                $query->whereNull(DB::raw("new_value ->> 'outcome'"))
                    ->orWhereNotIn(DB::raw("new_value ->> 'outcome'"), ['migrated', 'preserved']);
            })
            ->first(['id']);

        if ($unresolvedReceipt !== null) {
            throw new RuntimeException(self::MIGRATION_NAME." refuses unresolved reconciliation outcome in authorization_assignment_audits [{$unresolvedReceipt->id}].");
        }

        foreach (DB::table('model_has_roles')->get(['role_id', 'model_type', 'model_id']) as $row) {
            $sourceKey = implode(':', [
                'spatie_role',
                $row->role_id,
                $row->model_type,
                $row->model_id,
            ]);
            $assignment = $this->assertAcceptedReceipt($sourceKey);
            $legacyRole = DB::table('roles')->where('id', $row->role_id)->first(['name', 'guard_name']);
            $user = DB::table('users')->where('id', $row->model_id)->first(['id', 'organization_id']);
            $canonicalRoleId = $legacyRole === null ? null : DB::table('authorization_roles')->where('name', $legacyRole->name)->value('id');
            $isGlobal = $legacyRole?->name === 'super_admin';

            if (! in_array((string) $row->model_type, ['App\\Models\\User', 'App\\Modules\\Core\\Models\\User'], true)
                || $legacyRole === null || $legacyRole->guard_name !== 'web' || $user === null || $canonicalRoleId === null
                || (! $isGlobal && $user->organization_id === null)
                || (int) $assignment->authorization_role_id !== (int) $canonicalRoleId
                || (int) $assignment->user_id !== (int) $user->id
                || $assignment->scope_type !== ($isGlobal ? 'all' : 'organization')
                || ($isGlobal ? $assignment->scope_id !== null : (int) $assignment->scope_id !== (int) $user->organization_id)
                || ($isGlobal ? $assignment->organization_id !== null : (int) $assignment->organization_id !== (int) $user->organization_id)) {
                throw new RuntimeException(self::MIGRATION_NAME." refuses unmapped or inconsistent legacy assignment [{$sourceKey}].");
            }
        }

        foreach (DB::table('model_has_scoped_roles')->get() as $row) {
            $sourceKey = 'scoped_role:'.$row->id;
            $assignment = $this->assertAcceptedReceipt($sourceKey);
            $user = DB::table('users')->where('id', $row->user_id)->first(['id', 'organization_id']);
            $canonicalRoleId = DB::table('authorization_roles')->where('name', $row->role)->value('id');
            $scopeOrganizationId = $this->resolveScopeOrganizationId((string) $row->scope_type, $row->scope_id);

            if ($user === null || $canonicalRoleId === null
                || ($scopeOrganizationId !== null && (int) $user->organization_id !== $scopeOrganizationId)
                || (int) $assignment->authorization_role_id !== (int) $canonicalRoleId
                || (int) $assignment->user_id !== (int) $user->id
                || $assignment->scope_type !== $row->scope_type
                || ($row->scope_id === null ? $assignment->scope_id !== null : (int) $assignment->scope_id !== (int) $row->scope_id)
                || ($scopeOrganizationId === null ? $assignment->organization_id !== null : (int) $assignment->organization_id !== $scopeOrganizationId)) {
                throw new RuntimeException(self::MIGRATION_NAME." refuses unmapped or cross-organization legacy assignment [{$sourceKey}].");
            }
        }
    }

    /**
     * Every surviving legacy role permission must still resolve to the exact
     * canonical role/resource/action pivot. Reconciliation receipts alone are
     * insufficient here: pre-existing canonical pivots were intentionally not
     * receipt-marked, and a legacy row could have been mutated afterwards.
     */
    private function assertLegacyRolePermissionsWereReconciled(): void
    {
        $pairs = DB::table('role_has_permissions as rhp')
            ->leftJoin('roles as r', 'r.id', '=', 'rhp.role_id')
            ->leftJoin('permissions as p', 'p.id', '=', 'rhp.permission_id')
            ->get([
                'rhp.role_id',
                'rhp.permission_id',
                'r.name as role_name',
                'r.guard_name as role_guard',
                'p.name as permission_name',
                'p.guard_name as permission_guard',
            ]);

        foreach ($pairs as $pair) {
            $sourceKey = "spatie_role_permission:{$pair->role_id}:{$pair->permission_id}";
            $capability = is_string($pair->permission_name)
                ? CapabilityAlias::toCapability($pair->permission_name)
                : null;
            $mapping = $capability === null
                ? null
                : CapabilityToAuthorizationRolePermission::map($capability);

            if (! is_string($pair->role_name)
                || $pair->role_guard !== 'web'
                || $pair->permission_guard !== 'web'
                || $mapping === null) {
                throw new RuntimeException(self::MIGRATION_NAME." refuses unmapped or mutated legacy role permission [{$sourceKey}].");
            }

            $canonicalRoleId = DB::table('authorization_roles')
                ->where('name', $pair->role_name)
                ->value('id');
            $canonicalResourceId = DB::table('authorization_resources')
                ->where('key', $mapping['resource'])
                ->value('id');

            if ($canonicalRoleId === null
                || $canonicalResourceId === null
                || ! DB::table('authorization_role_permissions')
                    ->where('authorization_role_id', $canonicalRoleId)
                    ->where('authorization_resource_id', $canonicalResourceId)
                    ->where('action', $mapping['action'])
                    ->exists()) {
                throw new RuntimeException(self::MIGRATION_NAME." refuses missing canonical role permission [{$sourceKey}].");
            }
        }
    }

    private function assertAcceptedReceipt(string $sourceKey): object
    {
        $receipt = DB::table('authorization_assignment_audits')
            ->where('event', self::RECONCILIATION_EVENT)
            ->whereRaw("new_value ->> 'migration' = ?", [self::RECONCILIATION_MIGRATION])
            ->whereRaw("new_value ->> 'source_key' = ?", [$sourceKey])
            ->whereIn(DB::raw("new_value ->> 'outcome'"), ['migrated', 'preserved'])
            ->latest('id')
            ->first(['id', 'new_value']);

        if ($receipt === null) {
            throw new RuntimeException(self::MIGRATION_NAME." refuses unreconciled legacy assignment [{$sourceKey}].");
        }

        $payload = is_string($receipt->new_value)
            ? json_decode($receipt->new_value, true, 512, JSON_THROW_ON_ERROR)
            : (array) $receipt->new_value;
        $assignmentId = $payload['authorization_role_assignment_id'] ?? null;

        if (! is_numeric($assignmentId)
            || ($assignment = DB::table('authorization_role_assignments')->where('id', (int) $assignmentId)->first()) === null) {
            throw new RuntimeException(self::MIGRATION_NAME." refuses legacy assignment [{$sourceKey}] without a live canonical assignment.");
        }

        return $assignment;
    }

    private function resolveScopeOrganizationId(string $type, mixed $id): ?int
    {
        if (in_array($type, ['all', 'own'], true)) {
            if ($id !== null) {
                throw new RuntimeException(self::MIGRATION_NAME." refuses invalid scope pairing [{$type}:{$id}].");
            }

            return null;
        }

        if (! isset(self::SCOPES[$type]) || $id === null || ! Schema::hasTable(self::SCOPES[$type]['table'])) {
            throw new RuntimeException(self::MIGRATION_NAME." refuses unmapped legacy scope [{$type}].");
        }

        $mapping = self::SCOPES[$type];
        $organizationId = DB::table($mapping['table'])->where('id', $id)->value($mapping['organization_column']);
        if ($organizationId === null) {
            throw new RuntimeException(self::MIGRATION_NAME." refuses orphan legacy scope [{$type}:{$id}].");
        }

        return (int) $organizationId;
    }
};
