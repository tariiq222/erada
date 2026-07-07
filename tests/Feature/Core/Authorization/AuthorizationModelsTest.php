<?php

namespace Tests\Feature\Core\Authorization;

use App\Modules\Core\Authorization\Models\AuthorizationDecisionAudit;
use App\Modules\Core\Authorization\Models\AuthorizationRecordRule;
use App\Modules\Core\Authorization\Models\AuthorizationResource;
use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Authorization\Models\AuthorizationRoleAssignment;
use App\Modules\Core\Authorization\Models\AuthorizationRolePermission;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\TestCase;

/**
 * Phase 1 Task 1.1.2 — Authorization models feature test.
 *
 * Exercises the six Eloquent models under
 * `App\Modules\Core\Authorization\Models` against the six additive
 * `authorization_*` tables from Task 1.1.1. Asserts:
 *
 *  - table name + primary key wiring
 *  - fillable + guarded columns
 *  - casts (especially `domain_json` array, `enabled` boolean, `priority` int,
 *    and the `created_at` datetime cast on the append-only audit table)
 *  - pivot model `$incrementing = false`, `$timestamps = false`, no surrogate id
 *  - audit model append-only (no `updated_at`, no Eloquent timestamp writes)
 *  - relationships (role, user, organization, resource, permissions,
 *    assignments, recordRules, decisionAudits, matchedRole,
 *    matchedRoleAssignment, matchedRecordRule)
 *  - query scopes on `AuthorizationRecordRule`:
 *      enabled(), forResource(string), forAction(?string),
 *      forRoleOrUser(?int, ?int)
 *
 * The test is intentionally narrow: it pins the model surface that the
 * engine, evaluators, seeders, and policies in later tasks will rely on.
 */
class AuthorizationModelsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Authorization models test is PostgreSQL-only.');
        }
    }

    // ---------------------------------------------------------------------
    // AuthorizationRole
    // ---------------------------------------------------------------------

    public function test_authorization_role_maps_to_authorization_roles_table(): void
    {
        $role = new AuthorizationRole;

        $this->assertSame('authorization_roles', $role->getTable());
    }

    public function test_authorization_role_fillable_columns(): void
    {
        $role = new AuthorizationRole;

        // Phase 2.1.4a (admin role unification) added `is_admin_role`
        // to the fillable set so the backfill migration + the engine's
        // admin gate can both write the flag through Eloquent. The
        // schema migration `2026_07_05_000025` adds the column;
        // `2026_07_05_000026` reads `scoped_role_definitions.is_admin_role`
        // and writes `authorization_roles.is_admin_role`.
        $this->assertEqualsCanonicalizing(
            ['name', 'label', 'is_admin_role'],
            $role->getFillable()
        );
    }

    public function test_authorization_role_persists_name_and_label(): void
    {
        $role = AuthorizationRole::create([
            'name' => 'project_manager',
            'label' => 'Project Manager',
        ]);

        $this->assertNotNull($role->id);
        $this->assertSame('project_manager', $role->name);
        $this->assertSame('Project Manager', $role->label);

        $this->assertDatabaseHas('authorization_roles', [
            'id' => $role->id,
            'name' => 'project_manager',
            'label' => 'Project Manager',
        ]);
    }

    public function test_authorization_role_assignments_relationship(): void
    {
        $role = new AuthorizationRole;
        $relation = $role->assignments();

        $this->assertInstanceOf(HasMany::class, $relation);
        $this->assertSame('authorization_role_id', $relation->getForeignKeyName());
        $this->assertInstanceOf(AuthorizationRoleAssignment::class, $relation->getRelated());
    }

    public function test_authorization_role_permissions_relationship(): void
    {
        $role = new AuthorizationRole;
        $relation = $role->permissions();

        $this->assertInstanceOf(HasMany::class, $relation);
        $this->assertSame('authorization_role_id', $relation->getForeignKeyName());
        $this->assertInstanceOf(AuthorizationRolePermission::class, $relation->getRelated());
    }

    public function test_authorization_role_can_eager_load_assignments_and_permissions(): void
    {
        $role = AuthorizationRole::create(['name' => 'admin', 'label' => 'Admin']);
        $resource = AuthorizationResource::create([
            'key' => 'App\\Modules\\Projects\\Models\\Project',
            'label' => 'Project',
        ]);

        $org = Organization::create([
            'name' => 'Test Org',
            'code' => 'TEST-ORG-MODELS',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'email' => 'models-role-load@example.test',
            'organization_id' => $org->id,
        ]);

        AuthorizationRoleAssignment::create([
            'authorization_role_id' => $role->id,
            'user_id' => $user->id,
            'scope_type' => 'all',
            'scope_id' => null,
            'organization_id' => null,
        ]);

        AuthorizationRolePermission::create([
            'authorization_role_id' => $role->id,
            'authorization_resource_id' => $resource->id,
            'action' => 'read',
        ]);

        $loaded = AuthorizationRole::with(['assignments', 'permissions'])->find($role->id);

        $this->assertCount(1, $loaded->assignments);
        $this->assertSame($user->id, $loaded->assignments->first()->user_id);
        $this->assertCount(1, $loaded->permissions);
        $this->assertSame('read', $loaded->permissions->first()->action);
    }

    // ---------------------------------------------------------------------
    // AuthorizationResource
    // ---------------------------------------------------------------------

    public function test_authorization_resource_maps_to_authorization_resources_table(): void
    {
        $resource = new AuthorizationResource;

        $this->assertSame('authorization_resources', $resource->getTable());
    }

    public function test_authorization_resource_fillable_columns(): void
    {
        $resource = new AuthorizationResource;

        $this->assertEqualsCanonicalizing(
            ['key', 'label'],
            $resource->getFillable()
        );
    }

    public function test_authorization_resource_persists_key_and_label(): void
    {
        $resource = AuthorizationResource::create([
            'key' => 'App\\Modules\\Projects\\Models\\Project',
            'label' => 'Project',
        ]);

        $this->assertNotNull($resource->id);
        $this->assertSame('App\\Modules\\Projects\\Models\\Project', $resource->key);
        $this->assertSame('Project', $resource->label);
    }

    public function test_authorization_resource_permissions_relationship(): void
    {
        $resource = new AuthorizationResource;
        $relation = $resource->permissions();

        $this->assertInstanceOf(HasMany::class, $relation);
        $this->assertSame('authorization_resource_id', $relation->getForeignKeyName());
        $this->assertInstanceOf(AuthorizationRolePermission::class, $relation->getRelated());
    }

    public function test_authorization_resource_record_rules_relationship(): void
    {
        $resource = new AuthorizationResource;
        $relation = $resource->recordRules();

        $this->assertInstanceOf(HasMany::class, $relation);
        $this->assertSame('authorization_resource_id', $relation->getForeignKeyName());
        $this->assertInstanceOf(AuthorizationRecordRule::class, $relation->getRelated());
    }

    public function test_authorization_resource_decision_audits_relationship(): void
    {
        $resource = new AuthorizationResource;
        $relation = $resource->decisionAudits();

        $this->assertInstanceOf(HasMany::class, $relation);
        $this->assertSame('authorization_resource_id', $relation->getForeignKeyName());
        $this->assertInstanceOf(AuthorizationDecisionAudit::class, $relation->getRelated());
    }

    // ---------------------------------------------------------------------
    // AuthorizationRoleAssignment
    // ---------------------------------------------------------------------

    public function test_authorization_role_assignment_maps_to_authorization_role_assignments_table(): void
    {
        $assignment = new AuthorizationRoleAssignment;

        $this->assertSame('authorization_role_assignments', $assignment->getTable());
    }

    public function test_authorization_role_assignment_fillable_columns(): void
    {
        $assignment = new AuthorizationRoleAssignment;

        // Phase 2.1.2 hardening: inherit_to_children was added so the
        // backfill can preserve legacy model_has_scoped_roles semantics
        // on the new path. The resolver reads the column directly, so
        // it must be fillable for the migration's INSERT to land it.
        $this->assertEqualsCanonicalizing(
            ['authorization_role_id', 'user_id', 'scope_type', 'scope_id', 'organization_id', 'inherit_to_children'],
            $assignment->getFillable()
        );
    }

    public function test_authorization_role_assignment_inherit_to_children_is_boolean_cast(): void
    {
        $assignment = new AuthorizationRoleAssignment;

        $casts = $assignment->getCasts();
        $this->assertSame(
            'boolean',
            $casts['inherit_to_children'] ?? null,
            'inherit_to_children must be cast to boolean so the resolver sees a concrete true/false (no widening).'
        );
    }

    public function test_authorization_role_assignment_role_relationship_uses_authorization_role(): void
    {
        $assignment = new AuthorizationRoleAssignment;
        $relation = $assignment->role();

        $this->assertInstanceOf(BelongsTo::class, $relation);
        $this->assertSame('authorization_role_id', $relation->getForeignKeyName());
        $this->assertInstanceOf(AuthorizationRole::class, $relation->getRelated());
    }

    public function test_authorization_role_assignment_user_relationship_uses_core_user(): void
    {
        $assignment = new AuthorizationRoleAssignment;
        $relation = $assignment->user();

        $this->assertInstanceOf(BelongsTo::class, $relation);
        $this->assertSame('user_id', $relation->getForeignKeyName());
        $this->assertSame(User::class, $relation->getRelated()::class);
    }

    public function test_authorization_role_assignment_organization_relationship_is_nullable(): void
    {
        $assignment = new AuthorizationRoleAssignment;
        $relation = $assignment->organization();

        $this->assertInstanceOf(BelongsTo::class, $relation);
        $this->assertSame('organization_id', $relation->getForeignKeyName());
        $this->assertSame(Organization::class, $relation->getRelated()::class);
        $this->assertTrue(
            $this->isForeignKeyNullable('authorization_role_assignments', 'organization_id'),
            'authorization_role_assignments.organization_id must be a nullable column.'
        );
    }

    public function test_authorization_role_assignment_persists_all_scope_columns(): void
    {
        $role = AuthorizationRole::create(['name' => 'org-admin', 'label' => 'Org Admin']);

        $org = Organization::create([
            'name' => 'Assignment Org',
            'code' => 'ASSIGNMENT-ORG',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'email' => 'assignment-org-admin@example.test',
            'organization_id' => $org->id,
        ]);

        $assignment = AuthorizationRoleAssignment::create([
            'authorization_role_id' => $role->id,
            'user_id' => $user->id,
            'scope_type' => 'organization',
            'scope_id' => $org->id,
            'organization_id' => $org->id,
        ]);

        $this->assertNotNull($assignment->id);
        $this->assertSame('organization', $assignment->scope_type);
        $this->assertSame($org->id, $assignment->scope_id);
        $this->assertSame($org->id, $assignment->organization_id);

        $loaded = AuthorizationRoleAssignment::with(['role', 'user', 'organization'])->find($assignment->id);

        $this->assertSame('org-admin', $loaded->role->name);
        $this->assertSame($user->id, $loaded->user->id);
        $this->assertSame($org->id, $loaded->organization->id);
    }

    public function test_authorization_role_assignment_allows_null_organization(): void
    {
        $role = AuthorizationRole::create(['name' => 'global-viewer', 'label' => 'Global Viewer']);
        $user = User::factory()->create([
            'email' => 'assignment-null-org@example.test',
        ]);

        $assignment = AuthorizationRoleAssignment::create([
            'authorization_role_id' => $role->id,
            'user_id' => $user->id,
            'scope_type' => 'all',
            'scope_id' => null,
            'organization_id' => null,
        ]);

        $this->assertNull($assignment->organization_id);

        $loaded = AuthorizationRoleAssignment::with('organization')->find($assignment->id);
        $this->assertNull($loaded->organization);
    }

    // ---------------------------------------------------------------------
    // AuthorizationRolePermission (pure pivot)
    // ---------------------------------------------------------------------

    public function test_authorization_role_permission_maps_to_authorization_role_permissions_table(): void
    {
        $pivot = new AuthorizationRolePermission;

        $this->assertSame('authorization_role_permissions', $pivot->getTable());
    }

    public function test_authorization_role_permission_has_no_incrementing_or_timestamps(): void
    {
        $pivot = new AuthorizationRolePermission;

        $this->assertFalse($pivot->getIncrementing(), 'Pivot model must not be incrementing (no surrogate id).');
        $this->assertFalse($pivot->usesTimestamps(), 'Pivot model must NOT use Eloquent timestamps.');
    }

    public function test_authorization_role_permission_extends_eloquent_pivot(): void
    {
        // Review finding I-2: an idiomatic Eloquent pivot should extend
        // `Illuminate\Database\Eloquent\Relations\Pivot` rather than the
        // base Model — Pivot is the framework type the engine + sync code
        // pattern-match on, and it documents the no-surrogate-id,
        // no-timestamps contract in its constructor.
        $pivot = new AuthorizationRolePermission;

        $this->assertInstanceOf(
            Pivot::class,
            $pivot,
            'AuthorizationRolePermission must extend Illuminate\\Database\\Eloquent\\Relations\\Pivot.'
        );
    }

    public function test_authorization_role_permission_does_not_have_id_or_timestamp_columns(): void
    {
        // The pivot lives on the (role, resource, action) composite primary key.
        // A surrogate id or timestamps would leak runtime state into the schema.
        $pivot = new AuthorizationRolePermission;

        $this->assertFalse(Schema::hasColumn('authorization_role_permissions', 'id'));
        $this->assertFalse(Schema::hasColumn('authorization_role_permissions', 'created_at'));
        $this->assertFalse(Schema::hasColumn('authorization_role_permissions', 'updated_at'));
    }

    public function test_authorization_role_permission_fillable_columns(): void
    {
        $pivot = new AuthorizationRolePermission;

        // Phase 2.1.3 adds the per-pivot `reach` column so the new path can
        // enforce a per-(role, resource, action) reach cap without falling
        // back to the legacy scoped_role_definitions read on every call.
        // The column is nullable: a NULL value signals "no cap on this row"
        // and the engine falls back to the legacy read in that case.
        $this->assertEqualsCanonicalizing(
            ['authorization_role_id', 'authorization_resource_id', 'action', 'reach'],
            $pivot->getFillable()
        );
    }

    public function test_authorization_role_permission_reach_is_array_cast(): void
    {
        // Phase 2.1.3: the new `reach` JSON column must round-trip as an
        // array so the engine can read module-keyed entries
        // (`$pivot->reach['projects']`) directly. A raw-string cast would
        // force callers to json_decode() on every read, which would
        // silently break the new-path reach check when the column is set
        // but the cast is misconfigured.
        $pivot = new AuthorizationRolePermission;
        $casts = $pivot->getCasts();

        $this->assertSame(
            'array',
            $casts['reach'] ?? null,
            'AuthorizationRolePermission::reach must be cast to array so the new-path reach check sees a decoded JSON object (no stringly-typed read).'
        );
    }

    public function test_authorization_role_permission_role_and_resource_relationships(): void
    {
        $pivot = new AuthorizationRolePermission;

        $roleRelation = $pivot->role();
        $this->assertInstanceOf(BelongsTo::class, $roleRelation);
        $this->assertSame('authorization_role_id', $roleRelation->getForeignKeyName());
        $this->assertSame(AuthorizationRole::class, $roleRelation->getRelated()::class);

        $resourceRelation = $pivot->resource();
        $this->assertInstanceOf(BelongsTo::class, $resourceRelation);
        $this->assertSame('authorization_resource_id', $resourceRelation->getForeignKeyName());
        $this->assertSame(AuthorizationResource::class, $resourceRelation->getRelated()::class);
    }

    public function test_authorization_role_permission_persists_composite_key_row(): void
    {
        $role = AuthorizationRole::create(['name' => 'pivot-role', 'label' => 'Pivot Role']);
        $resource = AuthorizationResource::create([
            'key' => 'App\\Modules\\Projects\\Models\\Project',
            'label' => 'Project',
        ]);

        $pivot = AuthorizationRolePermission::create([
            'authorization_role_id' => $role->id,
            'authorization_resource_id' => $resource->id,
            'action' => 'create',
        ]);

        $loaded = AuthorizationRolePermission::with(['role', 'resource'])
            ->where('authorization_role_id', $role->id)
            ->where('authorization_resource_id', $resource->id)
            ->where('action', 'create')
            ->first();

        $this->assertNotNull($loaded);
        $this->assertSame('pivot-role', $loaded->role->name);
        $this->assertSame('App\\Modules\\Projects\\Models\\Project', $loaded->resource->key);
        $this->assertSame('create', $loaded->action);
    }

    public function test_authorization_role_permission_reach_round_trips_as_array(): void
    {
        // Phase 2.1.3: the per-pivot `reach` JSON column must round-trip
        // as a decoded array on both write and read. The engine's new
        // path applies the cap by reading `$pivot->reach[$module]`, so a
        // misconfigured cast that returned a string here would break the
        // reach check silently.
        $role = AuthorizationRole::create(['name' => 'reach-roundtrip-role', 'label' => 'Reach Round-trip']);
        $resource = AuthorizationResource::create([
            'key' => 'App\\Modules\\Projects\\Models\\Project',
            'label' => 'Project',
        ]);

        $pivot = AuthorizationRolePermission::create([
            'authorization_role_id' => $role->id,
            'authorization_resource_id' => $resource->id,
            'action' => 'view',
            'reach' => [
                'projects' => 'department',
                'tasks' => 'own',
            ],
        ]);

        // Cast on the in-memory model
        $this->assertSame(
            ['projects' => 'department', 'tasks' => 'own'],
            $pivot->reach
        );

        // Reload from DB to prove the column is JSON in the schema (not a
        // string that Eloquent re-decodes on every read).
        $loaded = AuthorizationRolePermission::query()
            ->where('authorization_role_id', $role->id)
            ->where('authorization_resource_id', $resource->id)
            ->where('action', 'view')
            ->first();

        $this->assertNotNull($loaded);
        $this->assertEqualsCanonicalizing(
            ['projects' => 'department', 'tasks' => 'own'],
            $loaded->reach
        );
    }

    public function test_authorization_role_permission_reach_accepts_null(): void
    {
        // Phase 2.1.3: a pivot with no reach set (NULL) means "no cap on
        // this row" -- the new path falls back to the legacy
        // scoped_role_definitions read in that case. Pre-fix behavior
        // (no reach column) is represented by this NULL, so the column
        // must accept and round-trip null safely.
        $role = AuthorizationRole::create(['name' => 'reach-null-role', 'label' => 'Reach Null']);
        $resource = AuthorizationResource::create([
            'key' => 'App\\Modules\\Projects\\Models\\Project',
            'label' => 'Project',
        ]);

        $pivot = AuthorizationRolePermission::create([
            'authorization_role_id' => $role->id,
            'authorization_resource_id' => $resource->id,
            'action' => 'view',
        ]);

        $this->assertNull($pivot->reach);

        $loaded = AuthorizationRolePermission::query()
            ->where('authorization_role_id', $role->id)
            ->where('authorization_resource_id', $resource->id)
            ->where('action', 'view')
            ->first();

        $this->assertNotNull($loaded);
        $this->assertNull($loaded->reach);
    }

    // ---------------------------------------------------------------------
    // AuthorizationRecordRule
    // ---------------------------------------------------------------------

    public function test_authorization_record_rule_maps_to_authorization_record_rules_table(): void
    {
        $rule = new AuthorizationRecordRule;

        $this->assertSame('authorization_record_rules', $rule->getTable());
    }

    public function test_authorization_record_rule_fillable_columns(): void
    {
        $rule = new AuthorizationRecordRule;

        $this->assertEqualsCanonicalizing(
            [
                'authorization_role_id',
                'user_id',
                'authorization_resource_id',
                'action',
                'domain_json',
                'priority',
                'enabled',
            ],
            $rule->getFillable()
        );
    }

    public function test_authorization_record_rule_casts(): void
    {
        $rule = new AuthorizationRecordRule;
        $casts = $rule->getCasts();

        $this->assertSame('array', $casts['domain_json'] ?? null);
        $this->assertSame('integer', $casts['priority'] ?? null);
        $this->assertSame('boolean', $casts['enabled'] ?? null);
    }

    public function test_authorization_record_rule_round_trips_domain_json_as_array(): void
    {
        $resource = AuthorizationResource::create([
            'key' => 'App\\Modules\\Projects\\Models\\Project',
            'label' => 'Project',
        ]);

        $rule = AuthorizationRecordRule::create([
            'authorization_resource_id' => $resource->id,
            'action' => 'read',
            'domain_json' => [
                'operator' => 'in',
                'column' => 'id',
                'values' => [1, 2, 3],
            ],
            'priority' => 7,
            'enabled' => true,
        ]);

        $this->assertSame([
            'operator' => 'in',
            'column' => 'id',
            'values' => [1, 2, 3],
        ], $rule->domain_json);
        $this->assertSame(7, $rule->priority);
        $this->assertTrue($rule->enabled);
    }

    public function test_authorization_record_rule_relationships(): void
    {
        $rule = new AuthorizationRecordRule;

        $roleRelation = $rule->role();
        $this->assertInstanceOf(BelongsTo::class, $roleRelation);
        $this->assertSame('authorization_role_id', $roleRelation->getForeignKeyName());
        $this->assertSame(AuthorizationRole::class, $roleRelation->getRelated()::class);

        $userRelation = $rule->user();
        $this->assertInstanceOf(BelongsTo::class, $userRelation);
        $this->assertSame('user_id', $userRelation->getForeignKeyName());
        $this->assertSame(User::class, $userRelation->getRelated()::class);

        $resourceRelation = $rule->resource();
        $this->assertInstanceOf(BelongsTo::class, $resourceRelation);
        $this->assertSame('authorization_resource_id', $resourceRelation->getForeignKeyName());
        $this->assertSame(AuthorizationResource::class, $resourceRelation->getRelated()::class);
    }

    public function test_authorization_record_rule_role_and_user_relationships_are_nullable(): void
    {
        $rule = new AuthorizationRecordRule;

        // NULL role + NULL user on authorization_record_rules means "applies
        // to everyone who reaches this resource". The FK columns must accept
        // NULL at the schema level for this to work.
        $this->assertTrue(
            $this->isForeignKeyNullable('authorization_record_rules', 'authorization_role_id'),
            'authorization_record_rules.authorization_role_id must be a nullable column.'
        );
        $this->assertTrue(
            $this->isForeignKeyNullable('authorization_record_rules', 'user_id'),
            'authorization_record_rules.user_id must be a nullable column.'
        );
    }

    public function test_authorization_record_rule_scope_enabled_filters_disabled_rules(): void
    {
        $resource = AuthorizationResource::create([
            'key' => 'App\\Modules\\Projects\\Models\\Project',
            'label' => 'Project',
        ]);

        AuthorizationRecordRule::create([
            'authorization_resource_id' => $resource->id,
            'action' => 'read',
            'domain_json' => ['operator' => 'in', 'column' => 'id', 'values' => [1]],
            'enabled' => true,
        ]);
        AuthorizationRecordRule::create([
            'authorization_resource_id' => $resource->id,
            'action' => 'read',
            'domain_json' => ['operator' => 'in', 'column' => 'id', 'values' => [2]],
            'enabled' => false,
        ]);

        $enabled = AuthorizationRecordRule::enabled()->get();

        $this->assertCount(1, $enabled);
        $this->assertTrue($enabled->first()->enabled);
    }

    public function test_authorization_record_rule_scope_for_resource(): void
    {
        $projectResource = AuthorizationResource::create([
            'key' => 'App\\Modules\\Projects\\Models\\Project',
            'label' => 'Project',
        ]);
        $taskResource = AuthorizationResource::create([
            'key' => 'App\\Modules\\Tasks\\Models\\Task',
            'label' => 'Task',
        ]);

        AuthorizationRecordRule::create([
            'authorization_resource_id' => $projectResource->id,
            'action' => 'read',
            'domain_json' => ['operator' => 'in', 'column' => 'id', 'values' => [1]],
        ]);
        AuthorizationRecordRule::create([
            'authorization_resource_id' => $taskResource->id,
            'action' => 'read',
            'domain_json' => ['operator' => 'in', 'column' => 'id', 'values' => [2]],
        ]);

        $matched = AuthorizationRecordRule::forResource('App\\Modules\\Projects\\Models\\Project')->get();

        $this->assertCount(1, $matched);
        $this->assertSame($projectResource->id, $matched->first()->authorization_resource_id);
    }

    public function test_authorization_record_rule_scope_for_action_accepts_null(): void
    {
        $resource = AuthorizationResource::create([
            'key' => 'App\\Modules\\Projects\\Models\\Project',
            'label' => 'Project',
        ]);

        $nullActionRule = AuthorizationRecordRule::create([
            'authorization_resource_id' => $resource->id,
            'action' => null,
            'domain_json' => ['operator' => 'in', 'column' => 'id', 'values' => [1]],
        ]);
        $readActionRule = AuthorizationRecordRule::create([
            'authorization_resource_id' => $resource->id,
            'action' => 'read',
            'domain_json' => ['operator' => 'in', 'column' => 'id', 'values' => [2]],
        ]);

        // forAction(null) returns only the rule whose action IS NULL
        // (i.e. "rule with no specific action binding").
        $nullMatched = AuthorizationRecordRule::forAction(null)->get();
        $this->assertCount(1, $nullMatched);
        $this->assertNull($nullMatched->first()->action);
        $this->assertSame($nullActionRule->id, $nullMatched->first()->id);

        // forAction('read') returns BOTH the rule with action='read'
        // AND the rule with action IS NULL (the null-action rule applies
        // to every action including 'read').
        $readMatched = AuthorizationRecordRule::forAction('read')->get();
        $this->assertCount(2, $readMatched);
        $this->assertEqualsCanonicalizing(
            [$nullActionRule->id, $readActionRule->id],
            $readMatched->pluck('id')->all()
        );
    }

    public function test_authorization_record_rule_scope_for_role_or_user_matches_role_user_or_wildcard(): void
    {
        $resource = AuthorizationResource::create([
            'key' => 'App\\Modules\\Projects\\Models\\Project',
            'label' => 'Project',
        ]);

        $role = AuthorizationRole::create(['name' => 'rule-role', 'label' => 'Rule Role']);
        $user = User::factory()->create(['email' => 'rule-user@example.test']);

        $roleScoped = AuthorizationRecordRule::create([
            'authorization_role_id' => $role->id,
            'authorization_resource_id' => $resource->id,
            'domain_json' => ['operator' => 'in', 'column' => 'id', 'values' => [1]],
        ]);
        $userScoped = AuthorizationRecordRule::create([
            'user_id' => $user->id,
            'authorization_resource_id' => $resource->id,
            'domain_json' => ['operator' => 'in', 'column' => 'id', 'values' => [2]],
        ]);
        $wildcard = AuthorizationRecordRule::create([
            'authorization_resource_id' => $resource->id,
            'domain_json' => ['operator' => 'in', 'column' => 'id', 'values' => [3]],
        ]);

        $matched = AuthorizationRecordRule::forRoleOrUser($role->id, $user->id)->get();
        $matchedIds = $matched->pluck('id')->all();

        $this->assertEqualsCanonicalizing(
            [$roleScoped->id, $userScoped->id, $wildcard->id],
            $matchedIds,
            'forRoleOrUser must include role-targeted, user-targeted, AND wildcard (NULL/NULL) rules.'
        );

        // When both role and user are null, only the wildcard (NULL/NULL) row matches.
        $wildcardOnly = AuthorizationRecordRule::forRoleOrUser(null, null)->get();
        $this->assertCount(1, $wildcardOnly);
        $this->assertSame($wildcard->id, $wildcardOnly->first()->id);
    }

    // ---------------------------------------------------------------------
    // AuthorizationDecisionAudit (append-only)
    // ---------------------------------------------------------------------

    public function test_authorization_decision_audit_maps_to_authorization_decision_audits_table(): void
    {
        $audit = new AuthorizationDecisionAudit;

        $this->assertSame('authorization_decision_audits', $audit->getTable());
    }

    public function test_authorization_decision_audit_is_append_only(): void
    {
        $audit = new AuthorizationDecisionAudit;

        $this->assertFalse(
            $audit->usesTimestamps(),
            'AuthorizationDecisionAudit must NOT use Eloquent timestamps (append-only).'
        );
        $this->assertFalse(
            Schema::hasColumn('authorization_decision_audits', 'updated_at'),
            'authorization_decision_audits table must NOT have updated_at.'
        );
    }

    public function test_authorization_decision_audit_fillable_columns(): void
    {
        $audit = new AuthorizationDecisionAudit;

        $this->assertEqualsCanonicalizing(
            [
                'user_id',
                'authorization_resource_id',
                'action',
                'decision',
                'matched_authorization_role_id',
                'matched_authorization_role_assignment_id',
                'matched_authorization_record_rule_id',
                'source',
            ],
            $audit->getFillable()
        );
    }

    public function test_authorization_decision_audit_casts_created_at_as_datetime(): void
    {
        $audit = new AuthorizationDecisionAudit;
        $casts = $audit->getCasts();

        $this->assertSame('datetime', $casts['created_at'] ?? null);
    }

    public function test_authorization_decision_audit_relationships(): void
    {
        $audit = new AuthorizationDecisionAudit;

        $userRelation = $audit->user();
        $this->assertInstanceOf(BelongsTo::class, $userRelation);
        $this->assertSame('user_id', $userRelation->getForeignKeyName());
        $this->assertSame(User::class, $userRelation->getRelated()::class);
        $this->assertTrue(
            $this->isForeignKeyNullable('authorization_decision_audits', 'user_id'),
            'authorization_decision_audits.user_id must be nullable (system audits allowed).'
        );

        $resourceRelation = $audit->resource();
        $this->assertInstanceOf(BelongsTo::class, $resourceRelation);
        $this->assertSame('authorization_resource_id', $resourceRelation->getForeignKeyName());
        $this->assertSame(AuthorizationResource::class, $resourceRelation->getRelated()::class);

        $matchedRole = $audit->matchedRole();
        $this->assertInstanceOf(BelongsTo::class, $matchedRole);
        $this->assertSame('matched_authorization_role_id', $matchedRole->getForeignKeyName());
        $this->assertSame(AuthorizationRole::class, $matchedRole->getRelated()::class);
        $this->assertTrue($this->isForeignKeyNullable('authorization_decision_audits', 'matched_authorization_role_id'));

        $matchedAssignment = $audit->matchedRoleAssignment();
        $this->assertInstanceOf(BelongsTo::class, $matchedAssignment);
        $this->assertSame('matched_authorization_role_assignment_id', $matchedAssignment->getForeignKeyName());
        $this->assertSame(AuthorizationRoleAssignment::class, $matchedAssignment->getRelated()::class);
        $this->assertTrue($this->isForeignKeyNullable('authorization_decision_audits', 'matched_authorization_role_assignment_id'));

        $matchedRule = $audit->matchedRecordRule();
        $this->assertInstanceOf(BelongsTo::class, $matchedRule);
        $this->assertSame('matched_authorization_record_rule_id', $matchedRule->getForeignKeyName());
        $this->assertSame(AuthorizationRecordRule::class, $matchedRule->getRelated()::class);
        $this->assertTrue($this->isForeignKeyNullable('authorization_decision_audits', 'matched_authorization_record_rule_id'));
    }

    public function test_authorization_decision_audit_persists_and_reads_created_at(): void
    {
        $resource = AuthorizationResource::create([
            'key' => 'App\\Modules\\Audit\\Models\\Audit',
            'label' => 'Audit',
        ]);

        $audit = AuthorizationDecisionAudit::create([
            'user_id' => null,
            'authorization_resource_id' => $resource->id,
            'action' => 'read',
            'decision' => 'allow',
            'source' => 'engine',
        ]);

        $this->assertNotNull($audit->created_at);
        $this->assertInstanceOf(Carbon::class, $audit->created_at);

        $reloaded = AuthorizationDecisionAudit::find($audit->id);

        // `created_at` is populated from two clocks: PHP `now()` (in the
        // `creating` boot hook) and PostgreSQL `useCurrent()` (on the DB side).
        // They are not the same instant, and string equality is brittle
        // against sub-second truncation, so we assert the value is a valid
        // datetime that lands within a small tolerance of the in-memory one.
        $this->assertNotNull($reloaded->created_at);
        $this->assertInstanceOf(Carbon::class, $reloaded->created_at);

        $deltaSeconds = abs($audit->created_at->diffInSeconds($reloaded->created_at, true));
        $this->assertLessThanOrEqual(
            5,
            $deltaSeconds,
            sprintf(
                'created_at must round-trip within a 5-second tolerance (in-memory=%s db=%s, delta=%ss).',
                $audit->created_at->toIso8601String(),
                $reloaded->created_at->toIso8601String(),
                $deltaSeconds
            )
        );
    }

    public function test_authorization_decision_audit_update_throws_logic_exception(): void
    {
        // Append-only at the Eloquent layer: any attempt to update an
        // existing audit row must throw a clear LogicException. Review
        // finding I-1: the engine must never silently rewrite history.
        $resource = AuthorizationResource::create([
            'key' => 'App\\Modules\\Audit\\Models\\Audit',
            'label' => 'Audit',
        ]);

        $audit = AuthorizationDecisionAudit::create([
            'user_id' => null,
            'authorization_resource_id' => $resource->id,
            'action' => 'read',
            'decision' => 'allow',
            'source' => 'engine',
        ]);

        $audit->decision = AuthorizationDecisionAudit::DECISION_DENY;

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/append-only|immutable|not.*update/i');

        $audit->save();
    }

    public function test_authorization_decision_audit_failed_update_does_not_mutate_db(): void
    {
        // Defense in depth: when the append-only guard trips, the DB row
        // must remain unchanged. We assert this by reading the row back via
        // the query builder (bypassing the model guard) and confirming the
        // original decision is still there.
        $resource = AuthorizationResource::create([
            'key' => 'App\\Modules\\Audit\\Models\\Audit',
            'label' => 'Audit',
        ]);

        $audit = AuthorizationDecisionAudit::create([
            'user_id' => null,
            'authorization_resource_id' => $resource->id,
            'action' => 'read',
            'decision' => AuthorizationDecisionAudit::DECISION_ALLOW,
            'source' => 'engine',
        ]);

        $audit->decision = AuthorizationDecisionAudit::DECISION_DENY;

        try {
            $audit->save();
            $this->fail('Expected LogicException was not thrown.');
        } catch (LogicException) {
            // expected
        }

        $row = DB::table('authorization_decision_audits')->where('id', $audit->id)->first();

        $this->assertNotNull($row);
        $this->assertSame(
            AuthorizationDecisionAudit::DECISION_ALLOW,
            $row->decision,
            'DB row decision must be unchanged after a blocked update.'
        );
    }

    public function test_authorization_decision_audit_does_not_write_updated_at(): void
    {
        $resource = AuthorizationResource::create([
            'key' => 'App\\Modules\\Audit\\Models\\Audit',
            'label' => 'Audit',
        ]);

        $audit = AuthorizationDecisionAudit::create([
            'user_id' => null,
            'authorization_resource_id' => $resource->id,
            'action' => 'read',
            'decision' => 'deny',
            'source' => 'engine',
        ]);

        // Eloquent must NOT silently write updated_at on append-only rows.
        // We use Schema::hasColumn to assert the column never existed in the
        // first place; this test guards against anyone re-enabling timestamps
        // and shipping a follow-up migration that adds updated_at.
        $row = DB::table('authorization_decision_audits')->where('id', $audit->id)->first();

        $this->assertNotNull($row);
        $this->assertObjectNotHasProperty('updated_at', $row);
    }

    /**
     * Read the nullability of a foreign-key column from information_schema
     * via Laravel's portable Schema::getColumns() helper. This lets the
     * model tests assert "this belongsTo FK is nullable" without depending
     * on Laravel's `Relation::isNullable()` helper, which is not exposed
     * in Laravel 12.
     */
    private function isForeignKeyNullable(string $table, string $column): bool
    {
        foreach (Schema::getColumns($table) as $col) {
            if ($col['name'] === $column) {
                return (bool) $col['nullable'];
            }
        }

        $this->fail("Column [{$column}] not found on table [{$table}].");
    }
}
