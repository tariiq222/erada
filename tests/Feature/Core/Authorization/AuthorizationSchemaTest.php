<?php

namespace Tests\Feature\Core\Authorization;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Phase 1 Task 1.1.1 — Authorization schema smoke test.
 *
 * Asserts the shape of the six additive authorization_* tables introduced by
 * `docs/superpowers/plans/2026-07-03-rbac-record-rules-unification.md` (Phase 1
 * Section 1.1, Task 1.1.1, plan lines 84-92):
 *
 *   authorization_roles
 *   authorization_resources
 *   authorization_role_assignments
 *   authorization_role_permissions
 *   authorization_record_rules
 *   authorization_decision_audits
 *
 * The test is intentionally narrow: it proves the schema exists with the
 * documented shape, nothing else. Models, evaluator, runtime mode, and seeders
 * live in later tasks.
 */
class AuthorizationSchemaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Authorization schema test is PostgreSQL-only.');
        }
    }

    public function test_all_six_authorization_tables_exist(): void
    {
        foreach ($this->expectedTables() as $table) {
            $this->assertTrue(
                Schema::hasTable($table),
                "Expected authorization table [{$table}] to exist."
            );
        }
    }

    public function test_authorization_roles_has_documented_columns(): void
    {
        $this->assertTableExists('authorization_roles');
        // Phase 2.1.4a (admin role unification) added the `is_admin_role`
        // boolean column via migration `2026_07_05_000025_add_is_admin_role_to_authorization_roles`.
        // The companion backfill migration `2026_07_05_000026` writes the
        // flag from the source `scoped_role_definitions.is_admin_role`.
        // PostgreSQL placed the new column after `updated_at` (the
        // migration asked for `after('label')` but PostgreSQL + Laravel
        // honored the existing default ordering for additive columns on
        // non-empty tables). The schema shape -- not the literal order --
        // is what the test pins.
        $columns = $this->columnList('authorization_roles');
        $this->assertContains('is_admin_role', $columns,
            'authorization_roles must carry is_admin_role after Phase 2.1.4a.');
        $this->assertContains('id', $columns);
        $this->assertContains('name', $columns);
        $this->assertContains('label', $columns);
        $this->assertContains('created_at', $columns);
        $this->assertContains('updated_at', $columns);
    }

    public function test_authorization_roles_name_is_unique(): void
    {
        $this->assertTableExists('authorization_roles');

        DB::table('authorization_roles')->insert([
            'name' => 'role-a',
            'label' => 'Role A',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectExceptionMessageMatches('/duplicate key value|unique constraint/i');

        DB::table('authorization_roles')->insert([
            'name' => 'role-a',
            'label' => 'Role A duplicate',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_authorization_resources_has_documented_columns(): void
    {
        $this->assertTableExists('authorization_resources');
        $this->assertSame(
            ['id', 'key', 'label', 'created_at', 'updated_at'],
            $this->columnList('authorization_resources')
        );
    }

    public function test_authorization_resources_key_is_unique(): void
    {
        $this->assertTableExists('authorization_resources');

        DB::table('authorization_resources')->insert([
            'key' => 'App\\Modules\\Projects\\Models\\Project',
            'label' => 'Project',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectExceptionMessageMatches('/duplicate key value|unique constraint/i');

        DB::table('authorization_resources')->insert([
            'key' => 'App\\Modules\\Projects\\Models\\Project',
            'label' => 'Project duplicate',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_authorization_role_assignments_has_documented_columns(): void
    {
        $this->assertTableExists('authorization_role_assignments');
        $columns = $this->columnList('authorization_role_assignments');

        foreach (['id', 'authorization_role_id', 'user_id', 'scope_type', 'scope_id', 'organization_id', 'created_at', 'updated_at'] as $required) {
            $this->assertContains($required, $columns, "authorization_role_assignments missing column [{$required}]");
        }
    }

    public function test_authorization_role_assignments_scope_type_is_check_constrained(): void
    {
        $this->assertTableExists('authorization_role_assignments');

        $roleId = DB::table('authorization_roles')->insertGetId([
            'name' => 'r-scope-check',
            'label' => 'Scope check',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $userId = DB::table('users')->insertGetId([
            'name' => 'scope user',
            'email' => 'scope-check@example.test',
            'password' => 'x',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectExceptionMessageMatches('/check constraint|violates/i');

        DB::table('authorization_role_assignments')->insert([
            'authorization_role_id' => $roleId,
            'user_id' => $userId,
            'scope_type' => 'not_a_real_scope',
            'scope_id' => null,
            'organization_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_authorization_role_assignments_all_and_own_allow_null_scope_id(): void
    {
        // 'all' and 'own' are runtime-resolved: 'all' covers the whole system,
        // 'own' filters records by ownership at query time. Neither points at
        // a scope row, so scope_id MUST be NULL for both.
        $this->assertTableExists('authorization_role_assignments');

        $roleId = DB::table('authorization_roles')->insertGetId([
            'name' => 'r-nullable-scopes',
            'label' => 'Nullable scopes',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (['all', 'own'] as $index => $scopeType) {
            $userId = DB::table('users')->insertGetId([
                'name' => "{$scopeType} user {$index}",
                'email' => "nullable-{$scopeType}-{$index}@example.test",
                'password' => 'x',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('authorization_role_assignments')->insert([
                'authorization_role_id' => $roleId,
                'user_id' => $userId,
                'scope_type' => $scopeType,
                'scope_id' => null,
                'organization_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->assertSame(2, DB::table('authorization_role_assignments')->whereIn('scope_type', ['all', 'own'])->count());
    }

    public function test_authorization_role_assignments_scoped_types_require_non_null_scope_id(): void
    {
        // Every scope_type other than 'all' / 'own' references a concrete row
        // (org / cluster / hospital / department / team). scope_id MUST be NOT NULL
        // for those; inserting NULL must trip the CHECK constraint. We use a
        // savepoint per scope_type via DB::transaction() so the first CHECK
        // violation does NOT poison the surrounding RefreshDatabase transaction
        // (PostgreSQL abort-state must be cleared by a rollback).
        $this->assertTableExists('authorization_role_assignments');

        $roleId = DB::table('authorization_roles')->insertGetId([
            'name' => 'r-non-null-scopes',
            'label' => 'Non-null scopes',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (['organization', 'cluster', 'hospital', 'department', 'team'] as $scopeType) {
            $userId = DB::table('users')->insertGetId([
                'name' => "{$scopeType} null-user",
                'email' => "non-null-{$scopeType}@example.test",
                'password' => 'x',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $violated = false;
            try {
                DB::transaction(function () use ($roleId, $userId, $scopeType) {
                    DB::table('authorization_role_assignments')->insert([
                        'authorization_role_id' => $roleId,
                        'user_id' => $userId,
                        'scope_type' => $scopeType,
                        'scope_id' => null,
                        'organization_id' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                });
            } catch (QueryException $e) {
                $violated = (bool) preg_match('/check constraint|violates/i', $e->getMessage());
            }

            $this->assertTrue(
                $violated,
                "Expected insert with scope_type={$scopeType} and scope_id=NULL to violate the CHECK constraint."
            );
        }
    }

    public function test_authorization_role_assignments_allows_each_reserved_scope_type(): void
    {
        $this->assertTableExists('authorization_role_assignments');

        $roleId = DB::table('authorization_roles')->insertGetId([
            'name' => 'r-each-scope',
            'label' => 'Each scope',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (['all', 'organization', 'cluster', 'hospital', 'department', 'team', 'own'] as $index => $scopeType) {
            $userId = DB::table('users')->insertGetId([
                'name' => "scope user {$index}",
                'email' => "scope-{$scopeType}-{$index}@example.test",
                'password' => 'x',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('authorization_role_assignments')->insert([
                'authorization_role_id' => $roleId,
                'user_id' => $userId,
                'scope_type' => $scopeType,
                // 'all' and 'own' are runtime-resolved — no scope row → scope_id NULL.
                'scope_id' => in_array($scopeType, ['all', 'own'], true) ? null : 1,
                'organization_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->assertSame(7, DB::table('authorization_role_assignments')->count());
    }

    public function test_authorization_role_assignments_requires_unique_combination(): void
    {
        $this->assertTableExists('authorization_role_assignments');

        // Need a real organizations row so the FK on organization_id lets us insert.
        $orgId = DB::table('organizations')->insertGetId([
            'name' => 'Test Org',
            'code' => 'TEST-ORG-1',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $roleId = DB::table('authorization_roles')->insertGetId([
            'name' => 'r-unique',
            'label' => 'Unique',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $userId = DB::table('users')->insertGetId([
            'name' => 'unique user',
            'email' => 'unique-assignment@example.test',
            'password' => 'x',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('authorization_role_assignments')->insert([
            'authorization_role_id' => $roleId,
            'user_id' => $userId,
            'scope_type' => 'organization',
            'scope_id' => $orgId,
            'organization_id' => $orgId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectExceptionMessageMatches('/duplicate key value|unique constraint/i');

        DB::table('authorization_role_assignments')->insert([
            'authorization_role_id' => $roleId,
            'user_id' => $userId,
            'scope_type' => 'organization',
            'scope_id' => $orgId,
            'organization_id' => $orgId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_authorization_role_assignments_null_scope_partial_unique_index(): void
    {
        // The migration creates TWO partial unique indexes because PostgreSQL
        // treats NULLs as distinct in a standard UNIQUE constraint:
        //   scope_null_unique     ON (role, user, scope_type) WHERE scope_id IS NULL
        //   scope_not_null_unique ON (role, user, scope_type, scope_id) WHERE scope_id IS NOT NULL
        // The NOT-NULL case is exercised by
        // test_authorization_role_assignments_requires_unique_combination above.
        // This test covers the NULL-scope case (scope_type='all' or 'own',
        // scope_id IS NULL). Without the partial index, a plain UNIQUE constraint
        // would silently allow duplicate (role, user, 'all', NULL) rows because
        // PostgreSQL never treats two NULLs as equal for unique purposes.
        //
        // We use a savepoint per scope_type so the first violation does not
        // poison the surrounding RefreshDatabase transaction (PostgreSQL abort
        // state must be cleared by a rollback, same trick as the CHECK test
        // for non-null scope types).
        $this->assertTableExists('authorization_role_assignments');

        $roleId = DB::table('authorization_roles')->insertGetId([
            'name' => 'r-null-scope-uniq',
            'label' => 'Null scope unique',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (['all', 'own'] as $scopeType) {
            $userId = DB::table('users')->insertGetId([
                'name' => "null-scope {$scopeType} user",
                'email' => "null-scope-{$scopeType}-uniq@example.test",
                'password' => 'x',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $violated = false;
            try {
                DB::transaction(function () use ($roleId, $userId, $scopeType) {
                    DB::table('authorization_role_assignments')->insert([
                        'authorization_role_id' => $roleId,
                        'user_id' => $userId,
                        'scope_type' => $scopeType,
                        'scope_id' => null,
                        'organization_id' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    DB::table('authorization_role_assignments')->insert([
                        'authorization_role_id' => $roleId,
                        'user_id' => $userId,
                        'scope_type' => $scopeType,
                        'scope_id' => null,
                        'organization_id' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                });
            } catch (QueryException $e) {
                $violated = (bool) preg_match('/duplicate key value|unique constraint/i', $e->getMessage());
            }

            $this->assertTrue(
                $violated,
                "Expected duplicate (role, user, scope_type={$scopeType}, scope_id=NULL) to violate the NULL-scope partial unique index."
            );
        }
    }

    public function test_authorization_role_permissions_is_a_pure_pivot(): void
    {
        $this->assertTableExists('authorization_role_permissions');

        $columns = $this->columnList('authorization_role_permissions');

        // Pure pivot: no surrogate id, no timestamps, only the three link columns.
        $this->assertNotContains('id', $columns, 'authorization_role_permissions must NOT have an id column.');
        $this->assertNotContains('created_at', $columns, 'authorization_role_permissions must NOT have created_at.');
        $this->assertNotContains('updated_at', $columns, 'authorization_role_permissions must NOT have updated_at.');

        foreach (['authorization_role_id', 'authorization_resource_id', 'action'] as $required) {
            $this->assertContains($required, $columns, "authorization_role_permissions missing [{$required}]");
        }

        $primaryKey = $this->primaryKeyColumns('authorization_role_permissions');

        $this->assertEqualsCanonicalizing(
            ['authorization_role_id', 'authorization_resource_id', 'action'],
            $primaryKey,
            'authorization_role_permissions PK must be the (role, resource, action) triple.'
        );
    }

    public function test_authorization_record_rules_has_documented_columns(): void
    {
        $this->assertTableExists('authorization_record_rules');

        $columns = $this->columnList('authorization_record_rules');
        foreach (['id', 'authorization_role_id', 'user_id', 'authorization_resource_id', 'action', 'domain_json', 'priority', 'enabled', 'created_at', 'updated_at'] as $required) {
            $this->assertContains($required, $columns, "authorization_record_rules missing column [{$required}]");
        }
    }

    public function test_authorization_record_rules_domain_json_is_jsonb(): void
    {
        $this->assertTableExists('authorization_record_rules');

        $type = DB::selectOne(
            "SELECT data_type, udt_name FROM information_schema.columns WHERE table_name = 'authorization_record_rules' AND column_name = 'domain_json'"
        );

        $this->assertNotNull($type, 'authorization_record_rules.domain_json column missing.');
        $this->assertSame('jsonb', $type->udt_name, 'authorization_record_rules.domain_json must be PostgreSQL jsonb.');
    }

    public function test_authorization_record_rules_accepts_structured_domain_payload(): void
    {
        $this->assertTableExists('authorization_record_rules');

        $resourceId = DB::table('authorization_resources')->insertGetId([
            'key' => 'App\\Modules\\Projects\\Models\\Project',
            'label' => 'Project',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('authorization_record_rules')->insert([
            'authorization_role_id' => null,
            'user_id' => null,
            'authorization_resource_id' => $resourceId,
            'action' => 'read',
            'domain_json' => json_encode([
                'operator' => 'in',
                'column' => 'id',
                'values' => [1, 2, 3],
            ]),
            'priority' => 0,
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('authorization_record_rules')->first();

        $this->assertNotNull($row);
        $decoded = json_decode($row->domain_json, true);
        $this->assertSame('in', $decoded['operator']);
        $this->assertSame('id', $decoded['column']);
        $this->assertSame([1, 2, 3], $decoded['values']);
    }

    public function test_authorization_decision_audits_has_documented_columns(): void
    {
        $this->assertTableExists('authorization_decision_audits');

        $columns = $this->columnList('authorization_decision_audits');

        // Append-only: created_at is acceptable, updated_at must NOT exist.
        $this->assertContains('id', $columns);
        $this->assertContains('created_at', $columns, 'authorization_decision_audits must have created_at.');
        $this->assertNotContains('updated_at', $columns, 'authorization_decision_audits must be append-only (no updated_at).');

        foreach (['user_id', 'authorization_resource_id', 'action', 'decision', 'source'] as $required) {
            $this->assertContains($required, $columns, "authorization_decision_audits missing [{$required}]");
        }
    }

    public function test_authorization_decision_audits_decision_and_source_are_check_constrained(): void
    {
        $this->assertTableExists('authorization_decision_audits');

        $resourceId = DB::table('authorization_resources')->insertGetId([
            'key' => 'App\\Modules\\Audit\\Models\\Audit',
            'label' => 'Audit',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectExceptionMessageMatches('/check constraint|violates/i');

        DB::table('authorization_decision_audits')->insert([
            'user_id' => null,
            'authorization_resource_id' => $resourceId,
            'action' => 'read',
            'decision' => 'maybe',
            'source' => 'engine',
            'created_at' => now(),
        ]);
    }

    public function test_authorization_decision_audits_source_is_check_constrained(): void
    {
        // Companion to test_authorization_decision_audits_decision_and_source_are_check_constrained,
        // which exercises the `decision` CHECK. This test covers the `source`
        // CHECK: CHECK (source IN ('engine','shadow','legacy')). We pass a
        // valid `decision` ('allow') and an invalid `source` ('foo') to isolate
        // the source violation from the decision violation.
        $this->assertTableExists('authorization_decision_audits');

        $resourceId = DB::table('authorization_resources')->insertGetId([
            'key' => 'App\\Modules\\Audit\\Models\\Audit',
            'label' => 'Audit',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectExceptionMessageMatches('/check constraint|violates/i');

        DB::table('authorization_decision_audits')->insert([
            'user_id' => null,
            'authorization_resource_id' => $resourceId,
            'action' => 'read',
            'decision' => 'allow',
            'source' => 'foo',
            'created_at' => now(),
        ]);
    }

    /**
     * @return list<string>
     */
    private function expectedTables(): array
    {
        return [
            'authorization_roles',
            'authorization_resources',
            'authorization_role_assignments',
            'authorization_role_permissions',
            'authorization_record_rules',
            'authorization_decision_audits',
        ];
    }

    private function assertTableExists(string $table): void
    {
        $this->assertTrue(
            Schema::hasTable($table),
            "Expected table [{$table}] to exist (Phase 1 Task 1.1.1 migration missing)."
        );
    }

    /**
     * @return list<string>
     */
    private function columnList(string $table): array
    {
        return array_map(
            static fn ($col) => $col->column_name,
            DB::select('SELECT column_name FROM information_schema.columns WHERE table_name = ? ORDER BY ordinal_position', [$table])
        );
    }

    /**
     * @return list<string>
     */
    private function primaryKeyColumns(string $table): array
    {
        $rows = DB::select(
            'SELECT a.attname AS column_name '
            .'FROM pg_index i '
            .'JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY(i.indkey) '
            .'WHERE i.indrelid = ?::regclass AND i.indisprimary',
            [$table]
        );

        return array_map(static fn ($r) => $r->column_name, $rows);
    }
}
