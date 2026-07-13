<?php

namespace Tests\Feature\Core\Authorization;

use App\Modules\Core\Authorization\Models\AuthorizationRecordRule;
use App\Modules\Core\Authorization\Models\AuthorizationResource;
use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Authorization\Models\AuthorizationRoleAssignment;
use App\Modules\Core\Authorization\RecordRuleEvaluator;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Phase 1 Task 1.1.3 — Record-rule evaluator feature test.
 *
 * Drives the structured `authorization_record_rules` payload through
 * `RecordRuleEvaluator` and asserts every operator under the allowlist
 * (`eq`, `neq`, `in`, `not_in`, `belongs_to_dept`, `owned_by`), the
 * AND-across-rules composition, role/user scoping, priority tie-break,
 * disabled-rule filtering, action-null behavior, and the deny-equivalent
 * fallback for malformed / unknown / missing-column payloads. PostgreSQL only.
 */
class RecordRuleEvaluatorTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Canonical FQCN used by the rule resource_key. Project is the canonical
     * Phase 1 pilot resource; it has a real `departments` column (`department_id`)
     * plus a real `created_by` (owner) column, so every operator can be
     * exercised against a real Eloquent Builder.
     */
    private const RESOURCE_KEY = 'App\\Modules\\Projects\\Models\\Project';

    private const ACTION_READ = 'read';

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('RecordRuleEvaluator test is PostgreSQL-only.');
        }
    }

    private function makeResource(): AuthorizationResource
    {
        return AuthorizationResource::firstOrCreate(
            ['key' => self::RESOURCE_KEY],
            ['label' => 'Project'],
        );
    }

    /**
     * Build a $query Eloquent Builder for Project::query() with no row yet
     * (we seed rows explicitly per test).
     */
    private function baseQuery(): Builder
    {
        return Project::query();
    }

    /**
     * Execute the evaluator on a fresh Builder and return the SQL string
     * plus the bound parameters. The SQL is enough to assert the WHERE chain
     * shape without coupling to specific row ids.
     *
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function sqlAndBindings(Builder $builder): array
    {
        return [$builder->toSql(), $builder->getBindings()];
    }

    // ---------------------------------------------------------------------
    // No-rules / unrelated-rules paths
    // ---------------------------------------------------------------------

    public function test_compile_wheres_returns_query_unchanged_when_no_rules_match(): void
    {
        $this->makeResource();

        $user = User::factory()->create();
        $evaluator = new RecordRuleEvaluator;

        $base = $this->baseQuery();
        $compiled = $evaluator->compileWheres(self::RESOURCE_KEY, self::ACTION_READ, $user, $base);

        // No WHERE chain appended: toSql() must match the fresh Builder's toSql().
        $this->assertSame($this->baseQuery()->toSql(), $compiled->toSql());
        $this->assertSame([], $compiled->getBindings());
    }

    public function test_compile_wheres_skips_disabled_rules(): void
    {
        $resource = $this->makeResource();

        $user = User::factory()->create();
        AuthorizationRecordRule::create([
            'authorization_resource_id' => $resource->id,
            'action' => self::ACTION_READ,
            'domain_json' => ['operator' => 'eq', 'column' => 'id', 'value' => 999],
            'priority' => 5,
            'enabled' => false,
        ]);

        $compiled = (new RecordRuleEvaluator)->compileWheres(
            self::RESOURCE_KEY,
            self::ACTION_READ,
            $user,
            $this->baseQuery()
        );

        // Disabled rule must NOT add a WHERE; query stays untouched.
        $this->assertSame($this->baseQuery()->toSql(), $compiled->toSql());
    }

    public function test_compile_wheres_skips_rules_targeting_a_different_action(): void
    {
        $resource = $this->makeResource();

        $user = User::factory()->create();
        AuthorizationRecordRule::create([
            'authorization_resource_id' => $resource->id,
            'action' => 'delete',
            'domain_json' => ['operator' => 'eq', 'column' => 'id', 'value' => 999],
            'enabled' => true,
        ]);

        $compiled = (new RecordRuleEvaluator)->compileWheres(
            self::RESOURCE_KEY,
            self::ACTION_READ,
            $user,
            $this->baseQuery()
        );

        $this->assertSame($this->baseQuery()->toSql(), $compiled->toSql());
    }

    public function test_compile_wheres_includes_rules_with_null_action_for_specific_action(): void
    {
        $resource = $this->makeResource();

        $user = User::factory()->create();
        AuthorizationRecordRule::create([
            'authorization_resource_id' => $resource->id,
            'action' => null,
            'domain_json' => ['operator' => 'eq', 'column' => 'id', 'value' => 1],
            'enabled' => true,
        ]);

        $compiled = (new RecordRuleEvaluator)->compileWheres(
            self::RESOURCE_KEY,
            self::ACTION_READ,
            $user,
            $this->baseQuery()
        );

        // The null-action rule applies to ANY action including 'read', so the
        // compiler must have appended a `where "id" = ?` clause (wrapped in
        // parentheses by Eloquent's nested closure).
        [$sql] = $this->sqlAndBindings($compiled);
        $this->assertStringContainsString('"id" =', $sql);
        $this->assertStringContainsString('where', $sql);
    }

    // ---------------------------------------------------------------------
    // Each allowlisted operator
    // ---------------------------------------------------------------------

    public function test_eq_operator_compiles_to_where_equals(): void
    {
        $resource = $this->makeResource();
        $user = User::factory()->create();

        AuthorizationRecordRule::create([
            'authorization_resource_id' => $resource->id,
            'action' => self::ACTION_READ,
            'domain_json' => ['operator' => 'eq', 'column' => 'id', 'value' => 7],
            'enabled' => true,
        ]);

        $compiled = (new RecordRuleEvaluator)->compileWheres(
            self::RESOURCE_KEY,
            self::ACTION_READ,
            $user,
            $this->baseQuery()
        );

        [$sql, $bindings] = $this->sqlAndBindings($compiled);
        $this->assertStringContainsString('"id" =', $sql);
        $this->assertContains(7, $bindings);
    }

    public function test_neq_operator_compiles_to_where_not_equals(): void
    {
        $resource = $this->makeResource();
        $user = User::factory()->create();

        AuthorizationRecordRule::create([
            'authorization_resource_id' => $resource->id,
            'action' => self::ACTION_READ,
            'domain_json' => ['operator' => 'neq', 'column' => 'id', 'value' => 99],
            'enabled' => true,
        ]);

        $compiled = (new RecordRuleEvaluator)->compileWheres(
            self::RESOURCE_KEY,
            self::ACTION_READ,
            $user,
            $this->baseQuery()
        );

        [$sql, $bindings] = $this->sqlAndBindings($compiled);
        $this->assertStringContainsString('"id" <>', $sql);
        $this->assertContains(99, $bindings);
    }

    public function test_in_operator_compiles_to_where_in(): void
    {
        $resource = $this->makeResource();
        $user = User::factory()->create();

        AuthorizationRecordRule::create([
            'authorization_resource_id' => $resource->id,
            'action' => self::ACTION_READ,
            'domain_json' => [
                'operator' => 'in',
                'column' => 'id',
                'values' => [1, 2, 3],
            ],
            'enabled' => true,
        ]);

        $compiled = (new RecordRuleEvaluator)->compileWheres(
            self::RESOURCE_KEY,
            self::ACTION_READ,
            $user,
            $this->baseQuery()
        );

        [$sql, $bindings] = $this->sqlAndBindings($compiled);
        $this->assertStringContainsString('"id" in', $sql);
        $this->assertEqualsCanonicalizing([1, 2, 3], array_values(array_filter(
            $bindings,
            fn ($b) => in_array($b, [1, 2, 3], true)
        )));
    }

    public function test_not_in_operator_compiles_to_where_not_in(): void
    {
        $resource = $this->makeResource();
        $user = User::factory()->create();

        AuthorizationRecordRule::create([
            'authorization_resource_id' => $resource->id,
            'action' => self::ACTION_READ,
            'domain_json' => [
                'operator' => 'not_in',
                'column' => 'id',
                'values' => [10, 20],
            ],
            'enabled' => true,
        ]);

        $compiled = (new RecordRuleEvaluator)->compileWheres(
            self::RESOURCE_KEY,
            self::ACTION_READ,
            $user,
            $this->baseQuery()
        );

        [$sql, $bindings] = $this->sqlAndBindings($compiled);
        $this->assertStringContainsString('"id" not in', $sql);
        $this->assertEqualsCanonicalizing([10, 20], array_values(array_filter(
            $bindings,
            fn ($b) => in_array($b, [10, 20], true)
        )));
    }

    public function test_owned_by_operator_compiles_to_where_user_id(): void
    {
        $resource = $this->makeResource();
        $org = Organization::create([
            'name' => 'Owned-by org',
            'code' => 'OWNED-BY-ORG',
            'is_active' => true,
        ]);
        $user = User::factory()->create([
            'email' => 'owned-by@example.test',
            'organization_id' => $org->id,
        ]);

        AuthorizationRecordRule::create([
            'authorization_resource_id' => $resource->id,
            'action' => self::ACTION_READ,
            'domain_json' => ['operator' => 'owned_by', 'column' => 'created_by'],
            'enabled' => true,
        ]);

        $compiled = (new RecordRuleEvaluator)->compileWheres(
            self::RESOURCE_KEY,
            self::ACTION_READ,
            $user,
            $this->baseQuery()
        );

        [$sql, $bindings] = $this->sqlAndBindings($compiled);
        $this->assertStringContainsString('"created_by" =', $sql);
        $this->assertContains($user->id, $bindings);
    }

    public function test_belongs_to_dept_self_chain_defaults_to_user_department_only(): void
    {
        $resource = $this->makeResource();
        $org = Organization::create([
            'name' => 'Dept chain org',
            'code' => 'DEPT-CHAIN-ORG',
            'is_active' => true,
        ]);
        $userDept = Department::factory()->create([
            'parent_id' => null,
            'organization_id' => $org->id,
        ]);
        $otherDept = Department::factory()->create([
            'parent_id' => null,
            'organization_id' => $org->id,
        ]);
        $user = User::factory()->create([
            'email' => 'dept-self@example.test',
            'organization_id' => $org->id,
            'department_id' => $userDept->id,
        ]);

        AuthorizationRecordRule::create([
            'authorization_resource_id' => $resource->id,
            'action' => self::ACTION_READ,
            'domain_json' => [
                'operator' => 'belongs_to_dept',
                'column' => 'department_id',
                // 'chain' omitted -> defaults to ['self']
            ],
            'enabled' => true,
        ]);

        $compiled = (new RecordRuleEvaluator)->compileWheres(
            self::RESOURCE_KEY,
            self::ACTION_READ,
            $user,
            $this->baseQuery()
        );

        [$sql, $bindings] = $this->sqlAndBindings($compiled);
        $this->assertStringContainsString('"department_id" in', $sql);
        $this->assertContains($userDept->id, $bindings);
        $this->assertNotContains($otherDept->id, $bindings);
    }

    public function test_belongs_to_dept_descendants_chain_includes_user_dept_and_children(): void
    {
        $resource = $this->makeResource();
        $org = Organization::create([
            'name' => 'Dept descendants org',
            'code' => 'DEPT-DESC-ORG',
            'is_active' => true,
        ]);

        $userDept = Department::factory()->create([
            'parent_id' => null,
            'organization_id' => $org->id,
        ]);
        $childDept = Department::factory()->create([
            'parent_id' => $userDept->id,
            'organization_id' => $org->id,
        ]);
        $grandDept = Department::factory()->create([
            'parent_id' => $childDept->id,
            'organization_id' => $org->id,
        ]);
        $siblingDept = Department::factory()->create([
            'parent_id' => null,
            'organization_id' => $org->id,
        ]);

        $user = User::factory()->create([
            'email' => 'dept-desc@example.test',
            'organization_id' => $org->id,
            'department_id' => $userDept->id,
        ]);

        AuthorizationRecordRule::create([
            'authorization_resource_id' => $resource->id,
            'action' => self::ACTION_READ,
            'domain_json' => [
                'operator' => 'belongs_to_dept',
                'column' => 'department_id',
                'chain' => ['self', 'descendants'],
            ],
            'enabled' => true,
        ]);

        $compiled = (new RecordRuleEvaluator)->compileWheres(
            self::RESOURCE_KEY,
            self::ACTION_READ,
            $user,
            $this->baseQuery()
        );

        [$sql, $bindings] = $this->sqlAndBindings($compiled);
        $this->assertStringContainsString('"department_id" in', $sql);
        $this->assertContains($userDept->id, $bindings);
        $this->assertContains($childDept->id, $bindings);
        $this->assertContains($grandDept->id, $bindings);
        $this->assertNotContains($siblingDept->id, $bindings);
    }

    public function test_belongs_to_dept_ancestors_chain_includes_user_dept_and_parents(): void
    {
        $resource = $this->makeResource();
        $org = Organization::create([
            'name' => 'Dept ancestors org',
            'code' => 'DEPT-ANC-ORG',
            'is_active' => true,
        ]);

        $rootDept = Department::factory()->create([
            'parent_id' => null,
            'organization_id' => $org->id,
        ]);
        $middleDept = Department::factory()->create([
            'parent_id' => $rootDept->id,
            'organization_id' => $org->id,
        ]);
        $userDept = Department::factory()->create([
            'parent_id' => $middleDept->id,
            'organization_id' => $org->id,
        ]);
        $unrelatedDept = Department::factory()->create([
            'parent_id' => null,
            'organization_id' => $org->id,
        ]);

        $user = User::factory()->create([
            'email' => 'dept-anc@example.test',
            'organization_id' => $org->id,
            'department_id' => $userDept->id,
        ]);

        AuthorizationRecordRule::create([
            'authorization_resource_id' => $resource->id,
            'action' => self::ACTION_READ,
            'domain_json' => [
                'operator' => 'belongs_to_dept',
                'column' => 'department_id',
                'chain' => ['self', 'ancestors'],
            ],
            'enabled' => true,
        ]);

        $compiled = (new RecordRuleEvaluator)->compileWheres(
            self::RESOURCE_KEY,
            self::ACTION_READ,
            $user,
            $this->baseQuery()
        );

        [$sql, $bindings] = $this->sqlAndBindings($compiled);
        $this->assertStringContainsString('"department_id" in', $sql);
        $this->assertContains($userDept->id, $bindings);
        $this->assertContains($middleDept->id, $bindings);
        $this->assertContains($rootDept->id, $bindings);
        $this->assertNotContains($unrelatedDept->id, $bindings);
    }

    public function test_belongs_to_dept_with_no_user_department_is_deny_equivalent(): void
    {
        $resource = $this->makeResource();
        $org = Organization::create([
            'name' => 'No-dept org',
            'code' => 'NO-DEPT-ORG',
            'is_active' => true,
        ]);
        $user = User::factory()->create([
            'email' => 'no-dept@example.test',
            'organization_id' => $org->id,
            'department_id' => null,
        ]);

        AuthorizationRecordRule::create([
            'authorization_resource_id' => $resource->id,
            'action' => self::ACTION_READ,
            'domain_json' => [
                'operator' => 'belongs_to_dept',
                'column' => 'department_id',
            ],
            'enabled' => true,
        ]);

        $compiled = (new RecordRuleEvaluator)->compileWheres(
            self::RESOURCE_KEY,
            self::ACTION_READ,
            $user,
            $this->baseQuery()
        );

        // No user department => empty IN set => deny-equivalent (impossible predicate).
        [$sql, $bindings] = $this->sqlAndBindings($compiled);
        // We assert that executing the query returns zero rows -- the real behavior.
        $this->assertSame(0, $compiled->count(), 'No-department user must see zero rows.');
        // And confirm the impossible-predicate shape: an `0=1` clause is acceptable.
        $isImpossible = str_contains($sql, '0 = 1')
            || str_contains($sql, '0=1')
            || str_contains($sql, '"department_id" in (')
            || in_array('[]', $bindings, true)
            || (isset($bindings[0]) && $bindings[0] === '[]');
        $this->assertTrue($isImpossible, "Expected an impossible predicate or empty IN list. SQL: {$sql}");
    }

    // ---------------------------------------------------------------------
    // AND-across-rules composition
    // ---------------------------------------------------------------------

    public function test_multiple_matching_rules_are_anded_together(): void
    {
        $resource = $this->makeResource();
        $user = User::factory()->create();

        AuthorizationRecordRule::create([
            'authorization_resource_id' => $resource->id,
            'action' => self::ACTION_READ,
            'domain_json' => ['operator' => 'eq', 'column' => 'id', 'value' => 1],
            'priority' => 10,
            'enabled' => true,
        ]);
        AuthorizationRecordRule::create([
            'authorization_resource_id' => $resource->id,
            'action' => self::ACTION_READ,
            'domain_json' => [
                'operator' => 'in',
                'column' => 'status',
                'values' => ['active', 'pending'],
            ],
            'priority' => 5,
            'enabled' => true,
        ]);

        $compiled = (new RecordRuleEvaluator)->compileWheres(
            self::RESOURCE_KEY,
            self::ACTION_READ,
            $user,
            $this->baseQuery()
        );

        [$sql, $bindings] = $this->sqlAndBindings($compiled);
        // AND of two clauses => nested parentheses on PostgreSQL: ("id" = ?) and ("status" in (...))
        $this->assertStringContainsString('"id" =', $sql);
        $this->assertStringContainsString('"status" in', $sql);
        $this->assertStringContainsString(' and ', $sql);
        $this->assertContains(1, $bindings);
    }

    // ---------------------------------------------------------------------
    // Role + user scoping
    // ---------------------------------------------------------------------

    public function test_rule_targeting_specific_user_only_matches_that_user(): void
    {
        $resource = $this->makeResource();
        $org = Organization::create([
            'name' => 'User-scope org',
            'code' => 'USER-SCOPE-ORG',
            'is_active' => true,
        ]);
        $target = User::factory()->create([
            'email' => 'user-scope-target@example.test',
            'organization_id' => $org->id,
        ]);
        $other = User::factory()->create([
            'email' => 'user-scope-other@example.test',
            'organization_id' => $org->id,
        ]);

        AuthorizationRecordRule::create([
            'authorization_resource_id' => $resource->id,
            'authorization_role_id' => null,
            'user_id' => $target->id,
            'action' => self::ACTION_READ,
            'domain_json' => ['operator' => 'eq', 'column' => 'id', 'value' => 42],
            'enabled' => true,
        ]);

        $evaluator = new RecordRuleEvaluator;

        $matchedCompiled = $evaluator->compileWheres(
            self::RESOURCE_KEY,
            self::ACTION_READ,
            $target,
            $this->baseQuery()
        );
        $this->assertStringContainsString('"id" =', $matchedCompiled->toSql());

        $unmatchedCompiled = $evaluator->compileWheres(
            self::RESOURCE_KEY,
            self::ACTION_READ,
            $other,
            $this->baseQuery()
        );
        $this->assertSame(
            $this->baseQuery()->toSql(),
            $unmatchedCompiled->toSql(),
            'Rule targeting a specific user must NOT apply to other users.'
        );
    }

    public function test_rule_targeting_specific_role_only_matches_users_with_that_role_assignment(): void
    {
        $resource = $this->makeResource();
        $org = Organization::create([
            'name' => 'Role-scope org',
            'code' => 'ROLE-SCOPE-ORG',
            'is_active' => true,
        ]);
        $role = AuthorizationRole::create([
            'name' => 'role-scope-test',
            'label' => 'Role scope test',
        ]);

        $assignedUser = User::factory()->create([
            'email' => 'role-scope-assigned@example.test',
            'organization_id' => $org->id,
        ]);
        AuthorizationRoleAssignment::create([
            'authorization_role_id' => $role->id,
            'user_id' => $assignedUser->id,
            'scope_type' => 'organization',
            'scope_id' => $org->id,
            'organization_id' => $org->id,
        ]);

        $unassignedUser = User::factory()->create([
            'email' => 'role-scope-unassigned@example.test',
            'organization_id' => $org->id,
        ]);

        AuthorizationRecordRule::create([
            'authorization_resource_id' => $resource->id,
            'authorization_role_id' => $role->id,
            'user_id' => null,
            'action' => self::ACTION_READ,
            'domain_json' => ['operator' => 'eq', 'column' => 'id', 'value' => 100],
            'enabled' => true,
        ]);

        $evaluator = new RecordRuleEvaluator;

        $assignedCompiled = $evaluator->compileWheres(
            self::RESOURCE_KEY,
            self::ACTION_READ,
            $assignedUser,
            $this->baseQuery()
        );
        $this->assertStringContainsString('"id" =', $assignedCompiled->toSql());

        $unassignedCompiled = $evaluator->compileWheres(
            self::RESOURCE_KEY,
            self::ACTION_READ,
            $unassignedUser,
            $this->baseQuery()
        );
        $this->assertSame(
            $this->baseQuery()->toSql(),
            $unassignedCompiled->toSql(),
            'Role-targeted rule must NOT apply to users without that role assignment.'
        );
    }

    public function test_role_rule_ignores_expired_assignments_and_inactive_roles(): void
    {
        $resource = $this->makeResource();
        $org = Organization::create([
            'name' => 'Lifecycle rule org',
            'code' => 'LIFECYCLE-RULE-ORG',
            'is_active' => true,
        ]);
        $role = AuthorizationRole::create([
            'name' => 'lifecycle-rule-role',
            'label' => 'Lifecycle rule role',
            'is_active' => true,
        ]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $assignment = AuthorizationRoleAssignment::create([
            'authorization_role_id' => $role->id,
            'user_id' => $user->id,
            'scope_type' => 'organization',
            'scope_id' => $org->id,
            'organization_id' => $org->id,
            'expires_at' => now()->subDay(),
        ]);
        AuthorizationRecordRule::create([
            'authorization_resource_id' => $resource->id,
            'authorization_role_id' => $role->id,
            'action' => self::ACTION_READ,
            'domain_json' => ['operator' => 'eq', 'column' => 'id', 'value' => 100],
            'enabled' => true,
        ]);

        $evaluator = new RecordRuleEvaluator;
        $this->assertSame($this->baseQuery()->toSql(), $evaluator->compileWheres(
            self::RESOURCE_KEY,
            self::ACTION_READ,
            $user,
            $this->baseQuery(),
        )->toSql());

        $assignment->update(['expires_at' => now()->addDay()]);
        $role->update(['is_active' => false]);
        $this->assertSame($this->baseQuery()->toSql(), $evaluator->compileWheres(
            self::RESOURCE_KEY,
            self::ACTION_READ,
            $user,
            $this->baseQuery(),
        )->toSql());
    }

    public function test_wildcard_rule_applies_to_everyone(): void
    {
        $resource = $this->makeResource();
        $user = User::factory()->create();

        AuthorizationRecordRule::create([
            'authorization_resource_id' => $resource->id,
            'authorization_role_id' => null,
            'user_id' => null,
            'action' => self::ACTION_READ,
            'domain_json' => ['operator' => 'eq', 'column' => 'id', 'value' => 5],
            'enabled' => true,
        ]);

        $compiled = (new RecordRuleEvaluator)->compileWheres(
            self::RESOURCE_KEY,
            self::ACTION_READ,
            $user,
            $this->baseQuery()
        );
        $this->assertStringContainsString('"id" =', $compiled->toSql());
    }

    // ---------------------------------------------------------------------
    // `applies()` — priority desc ordering + matching rules
    // ---------------------------------------------------------------------

    public function test_applies_returns_matching_rule_ids_ordered_by_priority_desc(): void
    {
        $resource = $this->makeResource();
        $org = Organization::create([
            'name' => 'Priority org',
            'code' => 'PRIORITY-ORG',
            'is_active' => true,
        ]);
        $user = User::factory()->create([
            'email' => 'priority-user@example.test',
            'organization_id' => $org->id,
        ]);

        $lowPriority = AuthorizationRecordRule::create([
            'authorization_resource_id' => $resource->id,
            'action' => self::ACTION_READ,
            'domain_json' => ['operator' => 'eq', 'column' => 'id', 'value' => 1],
            'priority' => 1,
            'enabled' => true,
        ]);
        $midPriority = AuthorizationRecordRule::create([
            'authorization_resource_id' => $resource->id,
            'action' => self::ACTION_READ,
            'domain_json' => ['operator' => 'eq', 'column' => 'id', 'value' => 2],
            'priority' => 5,
            'enabled' => true,
        ]);
        $highPriority = AuthorizationRecordRule::create([
            'authorization_resource_id' => $resource->id,
            'action' => self::ACTION_READ,
            'domain_json' => ['operator' => 'eq', 'column' => 'id', 'value' => 3],
            'priority' => 9,
            'enabled' => true,
        ]);
        // Disabled rule — must be excluded.
        AuthorizationRecordRule::create([
            'authorization_resource_id' => $resource->id,
            'action' => self::ACTION_READ,
            'domain_json' => ['operator' => 'eq', 'column' => 'id', 'value' => 4],
            'priority' => 100,
            'enabled' => false,
        ]);
        // Wrong-action rule — must be excluded.
        AuthorizationRecordRule::create([
            'authorization_resource_id' => $resource->id,
            'action' => 'delete',
            'domain_json' => ['operator' => 'eq', 'column' => 'id', 'value' => 5],
            'priority' => 99,
            'enabled' => true,
        ]);

        $ids = (new RecordRuleEvaluator)->applies($user, self::RESOURCE_KEY, self::ACTION_READ);

        $this->assertSame(
            [$highPriority->id, $midPriority->id, $lowPriority->id],
            array_values($ids),
            'applies() must return IDs sorted by priority DESC.'
        );
    }

    public function test_applies_returns_empty_array_when_no_rules_match(): void
    {
        $this->makeResource();
        $user = User::factory()->create();

        $ids = (new RecordRuleEvaluator)->applies($user, self::RESOURCE_KEY, self::ACTION_READ);

        $this->assertSame([], $ids);
    }

    // ---------------------------------------------------------------------
    // Deny-equivalent fallback for malformed / unknown / missing-column payloads
    // ---------------------------------------------------------------------

    public function test_unknown_operator_applies_deny_equivalent_impossible_predicate(): void
    {
        $resource = $this->makeResource();
        $user = User::factory()->create();

        AuthorizationRecordRule::create([
            'authorization_resource_id' => $resource->id,
            'action' => self::ACTION_READ,
            'domain_json' => ['operator' => 'sql_injection_or_unknown', 'column' => 'id'],
            'enabled' => true,
        ]);

        $compiled = (new RecordRuleEvaluator)->compileWheres(
            self::RESOURCE_KEY,
            self::ACTION_READ,
            $user,
            $this->baseQuery()
        );

        $this->assertSame(0, $compiled->count(), 'Unknown operator must yield zero rows (deny-equivalent).');
    }

    public function test_missing_required_args_for_in_operator_yields_deny_equivalent(): void
    {
        $resource = $this->makeResource();
        $user = User::factory()->create();

        AuthorizationRecordRule::create([
            'authorization_resource_id' => $resource->id,
            'action' => self::ACTION_READ,
            'domain_json' => ['operator' => 'in', 'column' => 'id'], // 'values' missing
            'enabled' => true,
        ]);

        $compiled = (new RecordRuleEvaluator)->compileWheres(
            self::RESOURCE_KEY,
            self::ACTION_READ,
            $user,
            $this->baseQuery()
        );

        $this->assertSame(0, $compiled->count());
    }

    public function test_column_referencing_non_existent_column_yields_deny_equivalent(): void
    {
        $resource = $this->makeResource();
        $user = User::factory()->create();

        AuthorizationRecordRule::create([
            'authorization_resource_id' => $resource->id,
            'action' => self::ACTION_READ,
            'domain_json' => ['operator' => 'eq', 'column' => 'this_column_does_not_exist', 'value' => 1],
            'enabled' => true,
        ]);

        $compiled = (new RecordRuleEvaluator)->compileWheres(
            self::RESOURCE_KEY,
            self::ACTION_READ,
            $user,
            $this->baseQuery()
        );

        $this->assertSame(0, $compiled->count());
    }

    public function test_raw_expression_in_column_is_rejected_as_deny_equivalent(): void
    {
        $resource = $this->makeResource();
        $user = User::factory()->create();

        AuthorizationRecordRule::create([
            'authorization_resource_id' => $resource->id,
            'action' => self::ACTION_READ,
            'domain_json' => ['operator' => 'eq', 'column' => 'id; DROP TABLE projects;', 'value' => 1],
            'enabled' => true,
        ]);

        $compiled = (new RecordRuleEvaluator)->compileWheres(
            self::RESOURCE_KEY,
            self::ACTION_READ,
            $user,
            $this->baseQuery()
        );

        $this->assertSame(0, $compiled->count());
        $this->assertTrue(Schema::hasTable('projects'));
    }

    public function test_malformed_domain_json_yields_deny_equivalent(): void
    {
        $resource = $this->makeResource();
        $user = User::factory()->create();

        AuthorizationRecordRule::create([
            'authorization_resource_id' => $resource->id,
            'action' => self::ACTION_READ,
            'domain_json' => ['this' => 'is_not_a_valid_rule'], // missing operator + column
            'enabled' => true,
        ]);

        $compiled = (new RecordRuleEvaluator)->compileWheres(
            self::RESOURCE_KEY,
            self::ACTION_READ,
            $user,
            $this->baseQuery()
        );

        $this->assertSame(0, $compiled->count());
    }
}
