<?php

namespace Tests\Feature\Authorization;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\ScopedRole;
use App\Modules\Core\Models\ScopedRoleDefinition;
use App\Modules\Core\Models\ScopeType;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Services\ProjectQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * OrgIsolationInvariantTest — Phase 0, Task 4.
 *
 * Proves the organization-isolation invariant survives the new subtree-expansion
 * and owner-floor mechanisms: a user in Org A gets can=false AND an empty list for
 * an Org B record, even with a stray cross-org scoped-role row that (pathologically)
 * references an Org B department and would otherwise grant projects.view.
 *
 * The org gate is the OUTERMOST gate: in can() it returns false at sameOrganization
 * before the owner floor or any role check runs; in the list it prefilters
 * where organization_id = A before subtree expansion, so a stray cross-org scope_id
 * is inert.
 */
class OrgIsolationInvariantTest extends TestCase
{
    use RefreshDatabase;

    public function test_cross_org_record_is_invisible_even_with_stray_role_row(): void
    {
        $a = Organization::factory()->create();
        $b = Organization::factory()->create();

        $userA = User::factory()->create(['organization_id' => $a->id]);
        $deptB = Department::factory()->create(['organization_id' => $b->id]);
        $projectB = Project::factory()->create([
            'organization_id' => $b->id,
            'department_id' => $deptB->id,
        ]);

        // A role definition that WOULD grant projects.view if it were ever honored.
        $roleDefinition = $this->createDeptViewRoleDefinition('stray_dept_manager_view');

        // Pathological: a department manager role row for userA pointing at an Org B
        // department, with inherit_to_children so subtree expansion could (wrongly)
        // pick up Org B departments if the org gate did not contain it.
        $userA->scopedRoles()->create([
            'role' => $roleDefinition->role_key,
            'scope_type' => ScopedRole::SCOPE_DEPARTMENT,
            'scope_id' => $deptB->id,
            'inherit_to_children' => true,
        ]);

        Cache::flush();

        // Element: org gate denies before owner floor / role checks.
        $this->assertFalse(
            AccessDecision::can($userA->fresh(), Capability::PROJECTS_VIEW, $projectB),
            'cross-org view must be denied at the organization gate'
        );

        // List: the org prefilter (where organization_id = A) excludes the Org B project
        // before subtree expansion runs.
        $visible = app(ProjectQueryService::class)
            ->applyPermissionFilter(Project::query(), $userA->fresh())
            ->pluck('id');

        $this->assertFalse(
            $visible->contains($projectB->id),
            'cross-org project must not appear in the Org A user list'
        );
    }

    public function test_cross_org_record_invisible_even_when_user_is_recorded_owner(): void
    {
        // Combine the owner floor with org isolation: even if a cross-org record
        // pathologically records the user as its creator, the org gate (which runs
        // before the owner floor) keeps it invisible.
        $a = Organization::factory()->create();
        $b = Organization::factory()->create();

        $userA = User::factory()->create(['organization_id' => $a->id]);
        $deptB = Department::factory()->create(['organization_id' => $b->id]);
        $projectB = Project::factory()->create([
            'organization_id' => $b->id,
            'department_id' => $deptB->id,
            'created_by' => $userA->id,
            'status' => 'planning',
        ]);

        $this->assertFalse(
            AccessDecision::can($userA->fresh(), Capability::PROJECTS_VIEW, $projectB),
            'owner floor must never override the organization gate'
        );

        $visible = app(ProjectQueryService::class)
            ->applyPermissionFilter(Project::query(), $userA->fresh())
            ->pluck('id');

        $this->assertFalse(
            $visible->contains($projectB->id),
            'cross-org owned project must not appear in the Org A user list'
        );
    }

    // =========================================================
    // Helper: a department-scoped role definition granting projects.view
    // =========================================================

    private function createDeptViewRoleDefinition(string $roleKey): ScopedRoleDefinition
    {
        $scopeType = ScopeType::firstOrCreate(
            ['key' => ScopedRole::SCOPE_DEPARTMENT],
            [
                'label_ar' => 'القسم',
                'label_en' => 'Department',
                'model_class' => Department::class,
                'supports_hierarchy' => true,
                'is_active' => true,
                'sort_order' => 0,
            ]
        );

        $existingId = DB::table('scoped_role_definitions')
            ->where('scope_type_id', $scopeType->id)
            ->where('role_key', $roleKey)
            ->value('id');

        if (! $existingId) {
            $existingId = DB::table('scoped_role_definitions')->insertGetId([
                'scope_type_id' => $scopeType->id,
                'role_key' => $roleKey,
                'name' => $roleKey,
                'display_name' => $roleKey,
                'scope_type' => ScopedRole::SCOPE_DEPARTMENT,
                'label_ar' => $roleKey,
                'label_en' => $roleKey,
                'is_admin_role' => false,
                'permissions' => json_encode($this->expandFlags([Capability::PROJECTS_VIEW], [
                    'can_edit' => false,
                    'can_delete' => false,
                    'can_view_all' => true,
                    'can_manage_members' => false,
                ])),
                'is_active' => true,
                'sort_order' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Cache::flush();

        return ScopedRoleDefinition::find($existingId);
    }

    private function expandFlags(array $permissions, array $flags): array
    {
        $byAction = fn (array $actions): array => array_values(array_filter(
            Capability::all(),
            function (string $c) use ($actions) {
                $a = str_contains($c, '.') ? substr($c, strrpos($c, '.') + 1) : $c;

                return in_array($a, $actions, true);
            }
        ));
        if (! empty($flags['can_edit'])) {
            $permissions = array_merge($permissions, $byAction(['edit', 'update']));
        }
        if (! empty($flags['can_delete'])) {
            $permissions = array_merge($permissions, $byAction(['delete', 'remove']));
        }
        if (! empty($flags['can_view_all'])) {
            $permissions = array_merge($permissions, $byAction(['view', 'view_all', 'view_reports']));
        }
        if (! empty($flags['can_manage_members'])) {
            $permissions = array_merge($permissions, $byAction(['manage_members', 'assign_roles']));
        }
        if (! empty($flags['can_view_confidential'])) {
            $permissions[] = Capability::OVR_VIEW_CONFIDENTIAL;
        }

        return array_values(array_unique($permissions));
    }
}
