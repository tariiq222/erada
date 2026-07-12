<?php

namespace Tests\Feature\HR\Isolation;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * EmployeeShowIsolationTest - Phase 2: org-A user cannot show an org-B employee.
 *
 * GET /api/hr/employees/{employee} is gated by ViewEmployeeRequest which
 * throws AccessDeniedHttpException on cross-org.
 */
class EmployeeShowIsolationTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    public function test_org_a_user_cannot_show_org_b_employee(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);
        $deptB = Department::factory()->create(['organization_id' => $orgB->id]);

        $actor = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
        ]);
        $this->grantEngineCapability($actor, Capability::HR_VIEW);

        $orgBEmployee = User::factory()->create([
            'organization_id' => $orgB->id,
            'department_id' => $deptB->id,
        ]);

        $response = $this->actingAs($actor, 'sanctum')
            ->getJson("/api/hr/employees/{$orgBEmployee->id}");

        $response->assertStatus(403);
    }

    public function test_org_a_user_can_show_org_a_employee(): void
    {
        $orgA = Organization::factory()->create();
        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);

        $actor = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
        ]);
        $this->grantEngineCapability($actor, Capability::HR_VIEW);

        $orgAEmployee = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
        ]);

        $response = $this->actingAs($actor, 'sanctum')
            ->getJson("/api/hr/employees/{$orgAEmployee->id}");

        $response->assertStatus(200);
        $this->assertSame($orgAEmployee->id, $response->json('id'));
    }

    public function test_super_admin_can_show_any_employee(): void
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
            ->getJson("/api/hr/employees/{$orgBEmployee->id}");

        $response->assertStatus(200);
        $this->assertSame($orgBEmployee->id, $response->json('id'));
    }

    public function test_show_nonexistent_employee_returns_404(): void
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $actor = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
        ]);
        $this->grantEngineCapability($actor, Capability::HR_VIEW);

        $response = $this->actingAs($actor, 'sanctum')
            ->getJson('/api/hr/employees/999999');

        $response->assertStatus(404);
    }
}
