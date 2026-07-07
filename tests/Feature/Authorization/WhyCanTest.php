<?php

namespace Tests\Feature\Authorization;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\ScopedRole;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\DepartmentCapacityRole;
use App\Modules\Projects\Models\Project;
use Database\Seeders\ScopedDepartmentRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * WhyCanTest — Phase 6, Task 3 (decision trace).
 *
 * whyCan() mirrors can() exactly but records WHICH layer granted (or denied) and,
 * for a positional grant, the matching role/scope. can() and whyCan() share one
 * scope-chain walk, so the trace can never drift from the boolean decision.
 */
class WhyCanTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_layer(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        $why = AccessDecision::whyCan($admin->fresh(), Capability::PROJECTS_EDIT);

        $this->assertTrue($why['granted']);
        $this->assertSame('super_admin', $why['layer']);
    }

    public function test_org_isolation_denied_layer(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $orgB->id]);
        $project = Project::factory()->create(['organization_id' => $orgB->id, 'department_id' => $dept->id]);

        $outsider = User::factory()->create(['organization_id' => $orgA->id]);

        $why = AccessDecision::whyCan($outsider->fresh(), Capability::PROJECTS_VIEW, $project);

        $this->assertFalse($why['granted']);
        $this->assertSame('org_isolation_denied', $why['layer']);
    }

    public function test_owner_floor_layer(): void
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $owner = User::factory()->create(['organization_id' => $org->id]);
        $project = Project::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
            'created_by' => $owner->id,
        ]);

        $why = AccessDecision::whyCan($owner->fresh(), Capability::PROJECTS_VIEW, $project);

        $this->assertTrue($why['granted']);
        $this->assertSame('owner_floor', $why['layer']);
    }

    public function test_org_functional_role_layer(): void
    {
        $this->seed(ScopedDepartmentRolesSeeder::class);
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $project = Project::factory()->create(['organization_id' => $org->id, 'department_id' => $dept->id]);

        $pmo = User::factory()->create(['organization_id' => $org->id]);
        $pmo->assignScopedRole('pmo_manager', ScopedRole::SCOPE_ORGANIZATION, $org->id, $pmo->id);
        // org-functional bridge reads Spatie role names; grant the matching Spatie role.
        $pmo->assignRole('admin');

        $why = AccessDecision::whyCan($pmo->fresh(), Capability::PROJECTS_EDIT, $project);

        $this->assertTrue($why['granted']);
        $this->assertSame('org_functional_role', $why['layer']);
    }

    public function test_scope_chain_layer_reports_role_and_scope(): void
    {
        $this->seed(ScopedDepartmentRolesSeeder::class);
        $org = Organization::factory()->create();
        $sector = Department::factory()->create(['organization_id' => $org->id]);
        $child = Department::factory()->create(['organization_id' => $org->id, 'parent_id' => $sector->id]);
        DepartmentCapacityRole::create(['department_id' => $sector->id, 'capacity' => 'manager', 'role_key' => 'dept_manager']);
        $mgr = User::factory()->create(['organization_id' => $org->id]);
        $sector->update(['manager_id' => $mgr->id]);
        $project = Project::factory()->create(['organization_id' => $org->id, 'department_id' => $child->id]);

        $why = AccessDecision::whyCan($mgr->fresh(), Capability::PROJECTS_EDIT, $project);

        $this->assertTrue($why['granted']);
        $this->assertSame('scope_chain', $why['layer']);
        $this->assertSame('dept_manager', $why['role']);
        $this->assertSame('department', $why['scope_type']);
        $this->assertSame($sector->id, $why['scope_id']);
    }

    public function test_none_layer_when_no_role_grants(): void
    {
        $org = Organization::factory()->create();
        $sector = Department::factory()->create(['organization_id' => $org->id]);
        $child = Department::factory()->create(['organization_id' => $org->id, 'parent_id' => $sector->id]);
        $project = Project::factory()->create(['organization_id' => $org->id, 'department_id' => $child->id]);

        $nobody = User::factory()->create(['organization_id' => $org->id]);

        $why = AccessDecision::whyCan($nobody->fresh(), Capability::PROJECTS_EDIT, $project);

        $this->assertFalse($why['granted']);
        $this->assertSame('none', $why['layer']);
        $this->assertNull($why['role']);
    }

    public function test_why_can_matches_can_across_layers(): void
    {
        $this->seed(ScopedDepartmentRolesSeeder::class);
        $org = Organization::factory()->create();
        $sector = Department::factory()->create(['organization_id' => $org->id]);
        $child = Department::factory()->create(['organization_id' => $org->id, 'parent_id' => $sector->id]);
        DepartmentCapacityRole::create(['department_id' => $sector->id, 'capacity' => 'manager', 'role_key' => 'dept_manager']);
        $mgr = User::factory()->create(['organization_id' => $org->id]);
        $sector->update(['manager_id' => $mgr->id]);
        $project = Project::factory()->create(['organization_id' => $org->id, 'department_id' => $child->id]);

        foreach ([Capability::PROJECTS_VIEW, Capability::PROJECTS_EDIT, Capability::PROJECTS_DELETE] as $cap) {
            $this->assertSame(
                AccessDecision::can($mgr->fresh(), $cap, $project),
                AccessDecision::whyCan($mgr->fresh(), $cap, $project)['granted'],
                "can() and whyCan() must agree for {$cap}"
            );
        }
    }
}
