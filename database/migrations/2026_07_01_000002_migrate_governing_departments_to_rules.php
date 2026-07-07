<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data migration: copy the three legacy governing-department settings into the
 * unified governance_rules table (ADR-UNIFIED-ROLE-ACCESS, Phase 1).
 *
 * Legacy settings were GLOBAL (a single row per setting key, no organization_id),
 * so the system could only hold ONE governor per resource type at a time. Each
 * governing department id belongs to exactly one organization, so we reproduce
 * the exact current behavior by writing ONE governance_rules row scoped to that
 * department's organization_id. Orgs whose departments were never the global
 * governor get no rule (they had no effective governor before either).
 *
 * Idempotent: upserts on (organization_id, resource_type, resource_subtype).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guard: nothing to migrate if the source tables are absent (fresh install).
        $this->migrateSingle('risk_settings', 'risks_governing_department', 'risk', null, ['risks.*']);
        $this->migrateSingle('ovr_settings', 'ovr_governing_department', 'ovr', null, ['ovr.*']);
        $this->migrateProjectMap();
    }

    public function down(): void
    {
        DB::table('governance_rules')->whereIn('resource_type', ['risk', 'ovr', 'project'])->delete();
    }

    /**
     * Migrate a single-department setting (risk / ovr): one integer department id.
     *
     * @param  array<int, string>  $capabilities
     */
    private function migrateSingle(string $table, string $key, string $resourceType, ?string $subtype, array $capabilities): void
    {
        if (! $this->hasTable($table)) {
            return;
        }

        $value = DB::table($table)->where('key', $key)->value('value');
        $deptId = ($value === null || $value === '') ? null : (int) $value;
        if ($deptId === null) {
            return;
        }

        $orgId = $this->organizationOf($deptId);
        if ($orgId === null) {
            return; // dangling department id — skip rather than orphan a rule
        }

        $this->upsertRule($orgId, $resourceType, $subtype, $deptId, $capabilities);
    }

    /**
     * Migrate the project per-type map: ['improvement' => id, 'development' => id].
     * Each type becomes a row with resource_subtype = the type.
     */
    private function migrateProjectMap(): void
    {
        if (! $this->hasTable('project_settings')) {
            return;
        }

        $raw = DB::table('project_settings')->where('key', 'project_type_governing_departments')->value('value');
        if ($raw === null || $raw === '') {
            return;
        }

        $map = json_decode($raw, true);
        if (! is_array($map)) {
            return;
        }

        foreach ($map as $type => $deptId) {
            if ($deptId === null || $deptId === '') {
                continue;
            }
            $deptId = (int) $deptId;
            $orgId = $this->organizationOf($deptId);
            if ($orgId === null) {
                continue;
            }
            $this->upsertRule($orgId, 'project', (string) $type, $deptId, ['projects.*']);
        }
    }

    /**
     * @param  array<int, string>  $capabilities
     */
    private function upsertRule(int $orgId, string $resourceType, ?string $subtype, int $deptId, array $capabilities): void
    {
        $where = [
            'organization_id' => $orgId,
            'resource_type' => $resourceType,
            'resource_subtype' => $subtype,
        ];

        // Manual upsert (NULL subtype makes DB::table upserts awkward across drivers).
        $exists = DB::table('governance_rules')
            ->where('organization_id', $orgId)
            ->where('resource_type', $resourceType)
            ->when($subtype === null, fn ($q) => $q->whereNull('resource_subtype'), fn ($q) => $q->where('resource_subtype', $subtype))
            ->exists();

        $attrs = [
            'governing_unit_id' => $deptId,
            'capabilities' => json_encode($capabilities),
            'applies_to_children' => true,
            'updated_at' => now(),
        ];

        if ($exists) {
            DB::table('governance_rules')
                ->where('organization_id', $orgId)
                ->where('resource_type', $resourceType)
                ->when($subtype === null, fn ($q) => $q->whereNull('resource_subtype'), fn ($q) => $q->where('resource_subtype', $subtype))
                ->update($attrs);

            return;
        }

        DB::table('governance_rules')->insert(array_merge($where, $attrs, ['created_at' => now()]));
    }

    private function organizationOf(int $departmentId): ?int
    {
        $orgId = DB::table('departments')->where('id', $departmentId)->value('organization_id');

        return $orgId === null ? null : (int) $orgId;
    }

    private function hasTable(string $table): bool
    {
        return DB::getSchemaBuilder()->hasTable($table);
    }
};
