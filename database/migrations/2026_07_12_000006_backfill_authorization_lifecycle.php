<?php

use App\Modules\Core\Authorization\AccessDecision;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Copies legacy assignment expiry and role activation into the canonical
 * authorization tables without guessing when provenance is ambiguous.
 *
 * Assignment provenance comes exclusively from the audited 000021 mapping.
 * A source or destination that participates in more than one mapping is
 * skipped and audited. Role activation is granted only when exactly one
 * legacy definition matches the canonical role by role_key or name and that
 * definition is active. Missing, duplicate, or inactive definitions therefore
 * produce the fail-closed value false.
 */
return new class extends Migration
{
    private const MIGRATION_NAME = '2026_07_12_000006_backfill_authorization_lifecycle';

    private const SOURCE_EVENT = 'legacy_scoped_backfill_000021';

    private const AUDIT_EVENT = 'authorization_lifecycle_backfill_000006';

    public function up(): void
    {
        $this->assertPrerequisites();

        DB::transaction(function (): void {
            $this->backfillAssignmentExpiry();
            $this->backfillRoleActivation();
        });

        AccessDecision::flushCache();
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! Schema::hasTable('permission_audits')) {
            return;
        }

        $audits = DB::table('permission_audits')
            ->where('event', self::AUDIT_EVENT)
            ->orderByDesc('id')
            ->get();

        DB::transaction(function () use ($audits): void {
            $deleteIds = [];

            foreach ($audits as $audit) {
                $payload = json_decode((string) $audit->new_value, true);
                if (! is_array($payload) || ($payload['migration'] ?? null) !== self::MIGRATION_NAME) {
                    continue;
                }

                if (($payload['outcome'] ?? null) === 'updated_assignment') {
                    $current = $this->normalizeTimestamp($payload['written_expires_at'] ?? null);
                    DB::table('authorization_role_assignments')
                        ->where('id', (int) $payload['new_assignment_id'])
                        ->where(function ($query) use ($current): void {
                            $current === null
                                ? $query->whereNull('expires_at')
                                : $query->where('expires_at', $current);
                        })
                        ->update(['expires_at' => $this->normalizeTimestamp($payload['old_expires_at'] ?? null)]);
                }

                if (($payload['outcome'] ?? null) === 'updated_role') {
                    DB::table('authorization_roles')
                        ->where('id', (int) $payload['authorization_role_id'])
                        ->where('is_active', (bool) $payload['written_is_active'])
                        ->update(['is_active' => (bool) $payload['old_is_active']]);
                }

                $deleteIds[] = (int) $audit->id;
            }

            if ($deleteIds !== []) {
                DB::table('permission_audits')->whereIn('id', $deleteIds)->delete();
            }
        });

        AccessDecision::flushCache();
    }

    private function assertPrerequisites(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException(self::MIGRATION_NAME.' is PostgreSQL-only.');
        }

        foreach (['model_has_scoped_roles', 'scoped_role_definitions', 'authorization_roles', 'authorization_role_assignments', 'permission_audits'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException(self::MIGRATION_NAME." requires {$table}.");
            }
        }

        if (! Schema::hasColumn('authorization_roles', 'is_active')
            || ! Schema::hasColumn('authorization_role_assignments', 'expires_at')) {
            throw new RuntimeException(self::MIGRATION_NAME.' requires lifecycle columns from 2026_07_12_000005.');
        }
    }

    private function backfillAssignmentExpiry(): void
    {
        $mappings = [];

        foreach (DB::table('permission_audits')->where('event', self::SOURCE_EVENT)->orderBy('id')->get() as $audit) {
            $payload = json_decode((string) $audit->new_value, true);
            if (! is_array($payload)) {
                continue;
            }
            if (($payload['migration'] ?? null) !== '2026_07_04_000021_backfill_scoped_roles_full_semantics') {
                continue;
            }

            $sourceId = $payload['source_scoped_role_id'] ?? null;
            $assignmentId = $payload['new_assignment_id'] ?? $payload['new_authorization_role_assignment_id'] ?? null;
            if ($sourceId === null || $assignmentId === null) {
                continue;
            }

            $mappings[(int) $sourceId][(int) $assignmentId] = true;
        }

        $assignmentSources = [];
        foreach ($mappings as $sourceId => $assignmentIds) {
            foreach (array_keys($assignmentIds) as $assignmentId) {
                $assignmentSources[$assignmentId][$sourceId] = true;
            }
        }

        foreach ($mappings as $sourceId => $assignmentIds) {
            $assignmentIds = array_map('intval', array_keys($assignmentIds));
            if (count($assignmentIds) !== 1) {
                $this->auditSkip('assignment', $sourceId, null, 'ambiguous_source_mapping');

                continue;
            }

            $assignmentId = $assignmentIds[0];
            if (count($assignmentSources[$assignmentId] ?? []) !== 1) {
                $this->auditSkip('assignment', $sourceId, $assignmentId, 'colliding_destination_mapping');

                continue;
            }

            $source = DB::table('model_has_scoped_roles')->where('id', $sourceId)->first();
            $assignment = DB::table('authorization_role_assignments')->where('id', $assignmentId)->first();
            if ($source === null || $assignment === null) {
                $this->auditSkip('assignment', $sourceId, $assignmentId, 'missing_source_or_destination');

                continue;
            }

            $roleName = DB::table('authorization_roles')->where('id', $assignment->authorization_role_id)->value('name');
            $sameScope = (int) $source->user_id === (int) $assignment->user_id
                && (string) $source->role === (string) $roleName
                && (string) $source->scope_type === (string) $assignment->scope_type
                && $this->nullableInt($source->scope_id) === $this->nullableInt($assignment->scope_id);
            if (! $sameScope) {
                $this->auditSkip('assignment', $sourceId, $assignmentId, 'provenance_tuple_mismatch');

                continue;
            }

            $old = $this->normalizeTimestamp($assignment->expires_at);
            $new = $this->normalizeTimestamp($source->expires_at);
            if ($old === $new) {
                continue;
            }

            DB::table('authorization_role_assignments')->where('id', $assignmentId)->update(['expires_at' => $new]);
            $this->audit([
                'outcome' => 'updated_assignment',
                'source_scoped_role_id' => $sourceId,
                'new_assignment_id' => $assignmentId,
                'old_expires_at' => $old,
                'written_expires_at' => $new,
            ], 'assignment_expiry_backfilled', (int) $source->user_id, (string) $source->scope_type, $this->nullableInt($source->scope_id), (string) $source->role);
        }
    }

    private function backfillRoleActivation(): void
    {
        foreach (DB::table('authorization_roles')->orderBy('id')->get(['id', 'name', 'is_active']) as $role) {
            $definitions = DB::table('scoped_role_definitions')
                ->where(function ($query) use ($role): void {
                    $query->where('role_key', (string) $role->name)
                        ->orWhere('name', (string) $role->name);
                })
                ->orderBy('id')
                ->get(['id', 'is_active']);

            $reason = null;
            $desired = false;
            if ($definitions->count() === 1) {
                $desired = (bool) $definitions->first()->is_active;
                if (! $desired) {
                    $reason = 'source_role_inactive';
                }
            } else {
                $reason = $definitions->isEmpty() ? 'missing_role_definition' : 'ambiguous_role_definitions';
            }

            if ((bool) $role->is_active !== $desired) {
                DB::table('authorization_roles')->where('id', (int) $role->id)->update(['is_active' => $desired]);
                $this->audit([
                    'outcome' => 'updated_role',
                    'authorization_role_id' => (int) $role->id,
                    'authorization_role_name' => (string) $role->name,
                    'legacy_definition_ids' => $definitions->pluck('id')->map(fn ($id) => (int) $id)->all(),
                    'old_is_active' => (bool) $role->is_active,
                    'written_is_active' => $desired,
                    'fail_closed_reason' => $reason,
                ], 'role_activation_backfilled', null, null, null, (string) $role->name);
            } elseif ($reason !== null) {
                $this->auditSkip('role', null, (int) $role->id, $reason);
            }
        }
    }

    private function auditSkip(string $subject, ?int $sourceId, ?int $destinationId, string $reason): void
    {
        $payload = [
            'outcome' => 'skipped',
            'subject' => $subject,
            'source_scoped_role_id' => $sourceId,
            $subject === 'role' ? 'authorization_role_id' : 'new_assignment_id' => $destinationId,
            'reason' => $reason,
        ];

        if ($this->auditExists($payload)) {
            return;
        }

        $this->audit($payload, 'lifecycle_backfill_skipped');
    }

    /** @param array<string, mixed> $payload */
    private function auditExists(array $payload): bool
    {
        return DB::table('permission_audits')
            ->where('event', self::AUDIT_EVENT)
            ->get(['new_value'])
            ->contains(function ($row) use ($payload): bool {
                $stored = json_decode((string) $row->new_value, true);

                return is_array($stored)
                    && ($stored['migration'] ?? null) === self::MIGRATION_NAME
                    && ($stored['outcome'] ?? null) === ($payload['outcome'] ?? null)
                    && ($stored['subject'] ?? null) === ($payload['subject'] ?? null)
                    && ($stored['source_scoped_role_id'] ?? null) === ($payload['source_scoped_role_id'] ?? null)
                    && ($stored['new_assignment_id'] ?? null) === ($payload['new_assignment_id'] ?? null)
                    && ($stored['authorization_role_id'] ?? null) === ($payload['authorization_role_id'] ?? null)
                    && ($stored['reason'] ?? null) === ($payload['reason'] ?? null);
            });
    }

    /** @param array<string, mixed> $payload */
    private function audit(array $payload, string $reason, ?int $userId = null, ?string $scopeType = null, ?int $scopeId = null, ?string $role = null): void
    {
        DB::table('permission_audits')->insert([
            'event' => self::AUDIT_EVENT,
            'actor_id' => null,
            'target_user_id' => $userId,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'role' => $role,
            'old_value' => null,
            'new_value' => json_encode(['migration' => self::MIGRATION_NAME, ...$payload]),
            'reason' => $reason,
            'ip_address' => null,
            'user_agent' => 'migration',
            'created_at' => now(),
        ]);
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    private function normalizeTimestamp(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value instanceof CarbonInterface
            ? $value->utc()->format('Y-m-d H:i:sP')
            : Carbon::parse((string) $value)->utc()->format('Y-m-d H:i:sP');
    }
};
