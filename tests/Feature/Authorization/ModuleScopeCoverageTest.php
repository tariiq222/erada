<?php

namespace Tests\Feature\Authorization;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\DepartmentCapacityRole;
use App\Modules\HR\Services\ScopedDepartmentRoleSyncService;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Meetings\Models\Recommendation;
use App\Modules\Performance\Models\Kpi;
use App\Modules\Surveys\Models\Survey;
use Database\Seeders\AdditionalScopeTypesSeeder;
use Database\Seeders\ScopedDepartmentRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ModuleScopeCoverageTest — verifies that the operational models brought into the
 * scope system in Phase 5 (Kpi, Meeting, Recommendation, Survey) are governed
 * by the unified AuthZ engine: a department manager can view a record scoped
 * to their department (vertically, via the ascending scope chain).
 *
 * Direction B (commit f98adef5): the standalone Decision model is gone, so
 * the meeting-ruling case is exercised by a kind=ruling Recommendation row
 * instead of a Decision+Recommendation chain.
 */
class ModuleScopeCoverageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Build a department whose manager holds the seeded dept_manager scoped role.
     *
     * @return array{0: Organization, 1: Department, 2: User}
     */
    private function departmentWithManager(): array
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        DepartmentCapacityRole::create([
            'department_id' => $dept->id,
            'capacity' => 'manager',
            'role_key' => 'dept_manager',
        ]);

        $mgr = User::factory()->create(['organization_id' => $org->id]);
        $dept->update(['manager_id' => $mgr->id]);
        app(ScopedDepartmentRoleSyncService::class)->syncDepartment($dept->fresh());

        return [$org, $dept, $mgr->fresh()];
    }

    public function test_department_manager_can_view_department_kpi(): void
    {
        $this->seed(ScopedDepartmentRolesSeeder::class);
        $this->seed(AdditionalScopeTypesSeeder::class);

        [$org, $dept, $mgr] = $this->departmentWithManager();

        $kpi = Kpi::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
        ]);

        $this->assertTrue(AccessDecision::can($mgr, Capability::KPIS_VIEW, $kpi));
    }

    public function test_department_manager_can_view_department_meeting(): void
    {
        $this->seed(ScopedDepartmentRolesSeeder::class);
        $this->seed(AdditionalScopeTypesSeeder::class);

        [$org, $dept, $mgr] = $this->departmentWithManager();

        $meeting = Meeting::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
        ]);

        $this->assertTrue(AccessDecision::can($mgr, Capability::MEETINGS_VIEW, $meeting));
    }

    public function test_ruling_recommendation_rolls_up_to_meeting_scope(): void
    {
        $this->seed(ScopedDepartmentRolesSeeder::class);
        $this->seed(AdditionalScopeTypesSeeder::class);

        [$org, $dept, $mgr] = $this->departmentWithManager();

        $meeting = Meeting::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
        ]);
        $rec = Recommendation::factory()->ruling()->create([
            'meeting_id' => $meeting->id,
            'organization_id' => $org->id,
        ]);

        $this->assertTrue(AccessDecision::can($mgr, Capability::MEETINGS_VIEW, $meeting));
        $this->assertTrue(AccessDecision::can($mgr, Capability::MEETINGS_VIEW, $rec));
    }

    public function test_department_manager_can_view_department_survey(): void
    {
        $this->seed(ScopedDepartmentRolesSeeder::class);
        $this->seed(AdditionalScopeTypesSeeder::class);

        [$org, $dept, $mgr] = $this->departmentWithManager();

        $survey = Survey::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
        ]);

        $this->assertTrue(AccessDecision::can($mgr, Capability::SURVEYS_VIEW, $survey));
    }
}
