<?php

namespace Tests\Feature\HR\Isolation;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\EmployeeProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * EmployeeUpdateIsolationTest - Phase 2: org-A user cannot update an org-B employee.
 *
 * PUT /api/hr/employees/{employee} is gated by UpdateEmployeeProfileRequest
 * (Phase 2 hardening: HR_MANAGE + same-org + null-org gate inside authorize()).
 * On cross-org access the FormRequest returns false → 403.
 */
class EmployeeUpdateIsolationTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    public function test_org_a_user_cannot_update_org_b_employee(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);
        $deptB = Department::factory()->create(['organization_id' => $orgB->id]);

        $actor = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
        ]);
        $this->grantEngineCapability($actor, [Capability::HR_VIEW, Capability::HR_MANAGE]);

        $orgBEmployee = User::factory()->create([
            'organization_id' => $orgB->id,
            'department_id' => $deptB->id,
        ]);

        $response = $this->actingAs($actor, 'sanctum')
            ->putJson("/api/hr/employees/{$orgBEmployee->id}", [
                'employment_type' => 'part_time',
                'employment_status' => 'active',
            ]);

        $response->assertStatus(403);
    }

    public function test_org_a_user_can_update_org_a_employee(): void
    {
        $orgA = Organization::factory()->create();
        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);

        $actor = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
        ]);
        $this->grantEngineCapability($actor, [Capability::HR_VIEW, Capability::HR_MANAGE]);

        $orgAEmployee = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
        ]);
        EmployeeProfile::create([
            'user_id' => $orgAEmployee->id,
            'employee_no' => 'EMP-200',
            'hire_date' => '2024-01-01',
            'employment_type' => 'full_time',
            'employment_status' => 'active',
        ]);

        $response = $this->actingAs($actor, 'sanctum')
            ->putJson("/api/hr/employees/{$orgAEmployee->id}", [
                'employment_type' => 'part_time',
                'employment_status' => 'suspended',
            ]);

        $response->assertStatus(200);
        $this->assertSame('part_time', $response->json('employment_type'));
    }

    public function test_null_org_user_cannot_update(): void
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $employee = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
        ]);

        $actor = User::factory()->create([
            'organization_id' => null,
            'department_id' => $dept->id,
        ]);
        $this->grantEngineCapability($actor, [Capability::HR_VIEW, Capability::HR_MANAGE]);

        $response = $this->actingAs($actor, 'sanctum')
            ->putJson("/api/hr/employees/{$employee->id}", [
                'employment_type' => 'part_time',
                'employment_status' => 'active',
            ]);

        $response->assertStatus(403);
    }

    public function test_super_admin_can_update_any_employee(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);
        $deptB = Department::factory()->create(['organization_id' => $orgB->id]);

        $superAdmin = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
        ]);
        $this->grantCanonicalSuperAdmin($superAdmin);

        $orgBEmployee = User::factory()->create([
            'organization_id' => $orgB->id,
            'department_id' => $deptB->id,
        ]);

        $response = $this->actingAs($superAdmin, 'sanctum')
            ->putJson("/api/hr/employees/{$orgBEmployee->id}", [
                'employment_type' => 'part_time',
                'employment_status' => 'suspended',
            ]);

        $response->assertStatus(200);
    }
}
