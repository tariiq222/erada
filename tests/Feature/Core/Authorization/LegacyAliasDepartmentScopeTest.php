<?php

namespace Tests\Feature\Core\Authorization;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Authorization\CapabilityAlias;
use App\Modules\Core\Authorization\Models\AuthorizationResource;
use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Authorization\Models\AuthorizationRoleAssignment;
use App\Modules\Core\Authorization\Models\AuthorizationRolePermission;
use App\Modules\Core\Authorization\Support\CapabilityToAuthorizationRolePermission;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CanonicalAuthorizationFixtures;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * LegacyAliasDepartmentScopeTest — CSD-CA23078-CORE-001 regression.
 *
 * Locks in the post-cutover behavior of the legacy
 * `edit_department_projects` / `edit_department_tasks` aliases on the
 * `admin` role:
 *
 *   1. The CapabilityAlias map drops the alias resolution path entirely
 *      (toCapability() returns null for the legacy strings).
 *   2. The pivot the 000010 backfill materialized from the alias is
 *      narrowed to `reach = {"projects":"department"}` (or
 *      `{"tasks":"department"}`) by the safety-net migration
 *      `2026_07_12_000016_narrow_legacy_department_aliases` so the
 *      engine consults the post-cutover reach map instead of treating
 *      the pivot as unrestricted.
 *   3. After the migration runs, a user holding only the historical
 *      `edit_department_projects` flat permission MUST NOT be able to
 *      edit a project in a peer department — the pivot's reach
 *      narrows the grant to the user's own department.
 *
 * The test reproduces the historical data shape by creating an
 * `admin` role carrying the legacy alias pivot directly (the legacy
 * flat vocabulary is no longer seeded; the test is the source of
 * truth for what the alias used to mean).
 */
class LegacyAliasDepartmentScopeTest extends TestCase
{
    use CanonicalAuthorizationFixtures;
    use GrantsEngineCapability;
    use RefreshDatabase;

    public function test_legacy_alias_resolves_to_null_and_admin_pivot_is_narrowed_to_department_reach(): void
    {
        // ── Setup ────────────────────────────────────────────────────
        $organization = Organization::factory()->create(['name' => 'org-admin']);
        $ownDepartment = Department::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'own-department',
        ]);
        $peerDepartment = Department::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'peer-department',
        ]);

        $admin = User::factory()->create([
            'organization_id' => $organization->id,
            'department_id' => $ownDepartment->id,
            'is_active' => true,
        ]);

        // Step A — create the historical `admin` authorization role with
        // the legacy `edit_department_projects` pivot that the 000010
        // backfill used to materialize from the flat-string alias. This
        // mirrors the historical data shape; the pivot starts with
        // reach=null (un-restricted) which is the unsafe state the
        // CSD-CA23078 fix corrects.
        $adminRole = $this->makeAdminRoleWithLegacyAliasPivots();

        AuthorizationRoleAssignment::query()->create([
            'authorization_role_id' => $adminRole->id,
            'user_id' => $admin->id,
            'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
            'scope_id' => $organization->id,
            'organization_id' => $organization->id,
            'inherit_to_children' => false,
            'expires_at' => null,
            'source' => 'manual',
            'granted_by' => null,
        ]);

        AccessDecision::flushCache();

        // Sanity precondition: the pivot starts at reach=null (the
        // pre-fix unsafe state). Without the safety-net migration, the
        // engine treats reach=null as "no cap" and the admin would be
        // able to edit peer-department projects via the un-restricted
        // PROJECTS_EDIT capability.
        $editPivot = $this->legacyAliasPivotFor($adminRole->id, Capability::PROJECTS_EDIT);
        $this->assertNotNull($editPivot, 'precondition: legacy alias pivot for PROJECTS_EDIT exists');
        $preconditionReach = is_string($editPivot->reach)
            ? json_decode($editPivot->reach, true)
            : $editPivot->reach;
        $this->assertNull(
            $preconditionReach,
            'precondition: pre-fix pivot has reach=null (un-restricted)'
        );

        // Pre-fix behavior (with reach=null on the pivot): the engine
        // grants PROJECTS_EDIT on the peer-department project because
        // the pivot has no reach cap. This assertion captures the
        // unsafe state the migration is correcting.
        $peerProject = Project::factory()->create([
            'organization_id' => $organization->id,
            'department_id' => $peerDepartment->id,
        ]);
        $this->assertTrue(
            AccessDecision::can($admin->fresh(), Capability::PROJECTS_EDIT, $peerProject->fresh()),
            'precondition: pre-fix, admin can edit peer-department project via un-restricted alias pivot',
        );

        // ── Run the safety-net migration ─────────────────────────────
        $migration = $this->migration();
        $migration->up();

        // ── Post-migration assertions ────────────────────────────────
        // (1) Alias resolution path is dropped.
        $this->assertNull(
            CapabilityAlias::toCapability('edit_department_projects'),
            'post-fix: edit_department_projects must resolve to null'
        );
        $this->assertNull(
            CapabilityAlias::toCapability('edit_department_tasks'),
            'post-fix: edit_department_tasks must resolve to null'
        );

        // (2) The pivot is still in place but narrowed to department reach.
        // The 000010 audit marker carries the (role_id, resource_id,
        // action) composite so the safety-net migration can identify the
        // pivot by walking the audit table; here we walk the audit row
        // we seeded below.
        $narrowedEditPivot = $this->legacyAliasPivotFor($adminRole->id, Capability::PROJECTS_EDIT);
        $this->assertNotNull($narrowedEditPivot, 'post-fix: pivot for PROJECTS_EDIT still exists');
        $this->assertSame(
            ['projects' => 'department'],
            is_string($narrowedEditPivot->reach)
                ? json_decode($narrowedEditPivot->reach, true)
                : $narrowedEditPivot->reach,
            'post-fix: pivot for PROJECTS_EDIT narrowed to reach={"projects":"department"}'
        );

        // (3) Admin can NO LONGER edit a peer-department project.
        $adminAfter = $admin->fresh();
        AccessDecision::flushCache();
        $this->assertFalse(
            AccessDecision::can($adminAfter, Capability::PROJECTS_EDIT, $peerProject->fresh()),
            'post-fix: admin MUST NOT be able to edit peer-department project after reach narrowing'
        );

        // (4) Admin can still edit a project in their own department —
        //     the legacy flat-string ladder semantically allowed
        //     department-scoped edit, not blanket denial.
        $ownProject = Project::factory()->create([
            'organization_id' => $organization->id,
            'department_id' => $ownDepartment->id,
        ]);
        AccessDecision::flushCache();
        $this->assertTrue(
            AccessDecision::can($adminAfter, Capability::PROJECTS_EDIT, $ownProject->fresh()),
            'post-fix: admin retains ability to edit own-department project'
        );

        // (5) Audit marker is written by the safety-net migration.
        $this->assertDatabaseHas('authorization_assignment_audits', [
            'event' => 'legacy_department_alias_narrowed_000016',
        ]);
    }

    public function test_legacy_alias_pivot_audit_lookup_narrows_only_pivots_created_by_000010_backfill(): void
    {
        // The migration must NOT touch pivots whose audit marker is
        // absent (pre-existing rows or pivots created by a different
        // backfill). This test sets up a pivot with the legacy
        // `edit_department_projects` semantic but NO matching 000010
        // audit marker, then runs the migration and asserts the pivot
        // is left at reach=null.
        $organization = Organization::factory()->create();
        $adminRole = AuthorizationRole::query()->updateOrCreate(
            ['name' => 'admin-no-marker'],
            [
                'label' => 'Admin (no marker)',
                'label_ar' => 'Admin (no marker)',
                'label_en' => 'Admin (no marker)',
                'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
                'is_admin_role' => false,
                'is_system' => false,
                'is_active' => true,
            ],
        );

        // Create the projects resource pivot directly, without a
        // 000010 audit marker. This simulates a pivot that was hand-
        // rolled (not created via the legacy backfill) and must stay
        // untouched by the safety-net migration.
        $projectsResource = AuthorizationResource::query()->firstOrCreate(
            ['key' => CapabilityToAuthorizationRolePermission::map(Capability::PROJECTS_EDIT)['resource']],
            ['label' => 'Project'],
        );
        $pivot = AuthorizationRolePermission::query()->updateOrCreate(
            [
                'authorization_role_id' => $adminRole->id,
                'authorization_resource_id' => $projectsResource->id,
                'action' => 'edit',
            ],
            ['reach' => null],
        );

        $this->migration()->up();

        $pivotAfter = $this->legacyAliasPivotFor($adminRole->id, Capability::PROJECTS_EDIT);
        $this->assertNotNull($pivotAfter, 'pivot still exists');
        $reachAfter = is_string($pivotAfter->reach)
            ? json_decode($pivotAfter->reach, true)
            : $pivotAfter->reach;
        $this->assertNull(
            $reachAfter,
            'pivot with no 000010 audit marker is NOT narrowed by the safety-net migration'
        );
    }

    public function test_migration_is_idempotent_on_re_run(): void
    {
        // Setting up the same fixture as the main test, running the
        // migration twice, and asserting no duplicate audit rows are
        // written and the pivot reach is unchanged.
        $organization = Organization::factory()->create();
        $department = Department::factory()->create(['organization_id' => $organization->id]);

        $adminRole = $this->makeAdminRoleWithLegacyAliasPivots();

        AuthorizationRoleAssignment::query()->create([
            'authorization_role_id' => $adminRole->id,
            'user_id' => User::factory()->create([
                'organization_id' => $organization->id,
                'department_id' => $department->id,
                'is_active' => true,
            ])->id,
            'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
            'scope_id' => $organization->id,
            'organization_id' => $organization->id,
            'inherit_to_children' => false,
            'expires_at' => null,
            'source' => 'manual',
            'granted_by' => null,
        ]);

        $migration = $this->migration();
        $migration->up();
        $auditCountAfterFirst = DB::table('authorization_assignment_audits')
            ->where('event', 'legacy_department_alias_narrowed_000016')
            ->count();

        $migration->up();
        $auditCountAfterSecond = DB::table('authorization_assignment_audits')
            ->where('event', 'legacy_department_alias_narrowed_000016')
            ->count();

        $this->assertSame(
            $auditCountAfterFirst,
            $auditCountAfterSecond,
            're-running the migration must not produce additional audit rows'
        );
    }

    /**
     * Build an `admin` authorization role whose projects.edit and
     * tasks.edit pivots carry reach=null AND seed a `legacy_backfill_000010`
     * audit marker on each pivot so the safety-net migration's
     * pivot-identification query (which joins on the marker) finds them.
     *
     * The marker shape mirrors what the 000010 backfill actually writes
     * (see `2026_07_03_000010_backfill_authorization_role_permissions`).
     */
    private function makeAdminRoleWithLegacyAliasPivots(): AuthorizationRole
    {
        $role = AuthorizationRole::query()->updateOrCreate(
            ['name' => 'admin'],
            [
                'label' => 'Admin',
                'label_ar' => 'Admin',
                'label_en' => 'Admin',
                'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
                'is_admin_role' => false,
                'is_system' => false,
                'is_active' => true,
            ],
        );

        $now = now();

        foreach ([
            Capability::PROJECTS_EDIT => 'edit_department_projects',
            Capability::TASKS_EDIT => 'edit_department_tasks',
        ] as $capability => $legacyName) {
            $mapping = CapabilityToAuthorizationRolePermission::map($capability);
            $resource = AuthorizationResource::query()->firstOrCreate(
                ['key' => $mapping['resource']],
                ['label' => class_basename($mapping['resource'])],
            );
            $pivot = AuthorizationRolePermission::query()->updateOrCreate(
                [
                    'authorization_role_id' => $role->id,
                    'authorization_resource_id' => $resource->id,
                    'action' => $mapping['action'],
                ],
                ['reach' => null],
            );

            // Seed a 000010 audit marker so the safety-net migration
            // picks this pivot up as a legacy alias materialization.
            DB::table('authorization_assignment_audits')->insert([
                'event' => 'legacy_backfill_000010',
                'actor_id' => null,
                'target_user_id' => null,
                'scope_type' => null,
                'scope_id' => null,
                'role' => 'admin',
                'old_value' => null,
                'new_value' => json_encode([
                    'migration' => '2026_07_03_000010_backfill_authorization_role_permissions',
                    'authorization_role_id' => $role->id,
                    'authorization_resource_id' => $resource->id,
                    'action' => $mapping['action'],
                    'legacy_role_id' => 1,
                    'legacy_permission_id' => 1,
                    'legacy_permission_name' => $legacyName,
                    'capability' => $capability,
                ]),
                'reason' => 'Phase 2.1.1 authorization role permission backfill',
                'ip_address' => null,
                'user_agent' => 'test',
                'created_at' => $now,
            ]);
        }

        return $role;
    }

    private function legacyAliasPivotFor(int $roleId, string $capability): ?object
    {
        $mapping = CapabilityToAuthorizationRolePermission::map($capability);
        $resource = AuthorizationResource::query()->where('key', $mapping['resource'])->first();
        if ($resource === null) {
            return null;
        }

        return DB::table('authorization_role_permissions')
            ->where('authorization_role_id', $roleId)
            ->where('authorization_resource_id', $resource->id)
            ->where('action', $mapping['action'])
            ->first();
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_07_12_000016_narrow_legacy_department_aliases.php');
    }
}
