<?php

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\OVR\Models\IncidentReport;
use Carbon\CarbonInterface;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const MIGRATION_NAME = '2026_07_05_000027_backfill_authorization_role_permissions_ovr_confidential';

    private const AUDIT_EVENT = 'legacy_ovr_confidential_permission_backfill_000027';

    private const AUDIT_REASON_WRITTEN = 'ovr_confidential_permission_backfilled';

    private const AUDIT_REASON_SKIPPED = 'unmappable_ovr_confidential_permission';

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException(self::MIGRATION_NAME.' is PostgreSQL-only.');
        }

        $this->assertRequiredTables();

        $resourceId = $this->incidentReportResourceId();
        $definitions = DB::table('scoped_role_definitions')
            ->select(['id', 'role_key', 'permissions'])
            ->whereNotNull('role_key')
            ->orderBy('id')
            ->get()
            ->filter(fn ($definition) => $this->hasLegacyConfidentialPermission($definition->permissions));

        if ($definitions->isEmpty()) {
            AccessDecision::flushCache();

            return;
        }

        $now = now();
        $auditRows = [];

        DB::transaction(function () use ($definitions, $resourceId, $now, &$auditRows) {
            foreach ($definitions as $definition) {
                $roleKey = (string) $definition->role_key;
                $role = DB::table('authorization_roles')
                    ->where('name', $roleKey)
                    ->first(['id', 'name']);

                if ($role === null) {
                    if (! $this->hasSkipAuditForDefinition((int) $definition->id)) {
                        $auditRows[] = $this->skippedAuditRow($roleKey, (int) $definition->id, 'no_matching_authorization_role', $now);
                    }

                    continue;
                }

                $existed = DB::table('authorization_role_permissions')
                    ->where('authorization_role_id', (int) $role->id)
                    ->where('authorization_resource_id', $resourceId)
                    ->where('action', 'confidential')
                    ->exists();

                if ($existed) {
                    continue;
                }

                DB::table('authorization_role_permissions')->insert([
                    'authorization_role_id' => (int) $role->id,
                    'authorization_resource_id' => $resourceId,
                    'action' => 'confidential',
                ]);

                $auditRows[] = [
                    'event' => self::AUDIT_EVENT,
                    'actor_id' => null,
                    'target_user_id' => null,
                    'scope_type' => null,
                    'scope_id' => null,
                    'role' => $roleKey,
                    'old_value' => null,
                    'new_value' => json_encode([
                        'migration' => self::MIGRATION_NAME,
                        'authorization_role_id' => (int) $role->id,
                        'authorization_resource_id' => $resourceId,
                        'action' => 'confidential',
                        'legacy_definition_id' => (int) $definition->id,
                    ]),
                    'reason' => self::AUDIT_REASON_WRITTEN,
                    'ip_address' => null,
                    'user_agent' => 'migration',
                    'created_at' => $now,
                ];
            }

            if ($auditRows !== []) {
                DB::table('permission_audits')->insert($auditRows);
            }
        });

        AccessDecision::flushCache();
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $auditRows = DB::table('permission_audits')
            ->where('event', self::AUDIT_EVENT)
            ->orderBy('id')
            ->get();

        if ($auditRows->isEmpty()) {
            AccessDecision::flushCache();

            return;
        }

        $auditIdsToDelete = [];

        DB::transaction(function () use ($auditRows, &$auditIdsToDelete) {
            foreach ($auditRows as $auditRow) {
                $newValue = json_decode((string) $auditRow->new_value, true);
                if (! is_array($newValue) || ($newValue['migration'] ?? null) !== self::MIGRATION_NAME) {
                    continue;
                }

                if (($auditRow->reason ?? null) === self::AUDIT_REASON_WRITTEN) {
                    DB::table('authorization_role_permissions')
                        ->where('authorization_role_id', (int) ($newValue['authorization_role_id'] ?? 0))
                        ->where('authorization_resource_id', (int) ($newValue['authorization_resource_id'] ?? 0))
                        ->where('action', (string) ($newValue['action'] ?? ''))
                        ->delete();
                }

                $auditIdsToDelete[] = (int) $auditRow->id;
            }

            if ($auditIdsToDelete !== []) {
                DB::table('permission_audits')->whereIn('id', $auditIdsToDelete)->delete();
            }
        });

        AccessDecision::flushCache();
    }

    private function assertRequiredTables(): void
    {
        foreach (['authorization_roles', 'authorization_resources', 'authorization_role_permissions', 'scoped_role_definitions', 'permission_audits'] as $table) {
            if (DB::selectOne('SELECT 1 FROM information_schema.tables WHERE table_name = ?', [$table]) === null) {
                throw new RuntimeException(self::MIGRATION_NAME.' requires '.$table.'.');
            }
        }
    }

    private function incidentReportResourceId(): int
    {
        $existing = DB::table('authorization_resources')->where('key', IncidentReport::class)->first(['id']);
        if ($existing !== null) {
            return (int) $existing->id;
        }

        return (int) DB::table('authorization_resources')->insertGetId([
            'key' => IncidentReport::class,
            'label' => 'IncidentReport',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function hasLegacyConfidentialPermission(mixed $permissions): bool
    {
        $decoded = is_string($permissions) ? json_decode($permissions, true) : $permissions;
        if (! is_array($decoded)) {
            return false;
        }

        return in_array(Capability::OVR_VIEW_CONFIDENTIAL, $decoded, true)
            || in_array(Capability::OVR_CONFIDENTIAL, $decoded, true);
    }

    private function hasSkipAuditForDefinition(int $definitionId): bool
    {
        return DB::table('permission_audits')
            ->where('event', self::AUDIT_EVENT)
            ->where('reason', self::AUDIT_REASON_SKIPPED)
            ->whereRaw("new_value::jsonb->>'migration' = ?", [self::MIGRATION_NAME])
            ->whereRaw("(new_value::jsonb->>'legacy_definition_id')::bigint = ?", [$definitionId])
            ->exists();
    }

    private function skippedAuditRow(string $roleKey, int $definitionId, string $reason, CarbonInterface $now): array
    {
        return [
            'event' => self::AUDIT_EVENT,
            'actor_id' => null,
            'target_user_id' => null,
            'scope_type' => null,
            'scope_id' => null,
            'role' => $roleKey,
            'old_value' => null,
            'new_value' => json_encode([
                'migration' => self::MIGRATION_NAME,
                'legacy_definition_id' => $definitionId,
                'reason' => $reason,
            ]),
            'reason' => self::AUDIT_REASON_SKIPPED,
            'ip_address' => null,
            'user_agent' => 'migration',
            'created_at' => $now,
        ];
    }
};
