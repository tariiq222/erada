<?php

namespace Tests\Feature\HR;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\EmployeeProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

class EmployeeControllerTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    private Organization $organization;

    private Organization $otherOrganization;

    private Department $department;

    private Department $otherDepartment;

    private User $hrManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->otherOrganization = Organization::factory()->create();

        $this->department = Department::factory()->create([
            'organization_id' => $this->organization->id,
            'level' => 4,
            'manager_id' => null,
        ]);
        $this->otherDepartment = Department::factory()->create([
            'organization_id' => $this->otherOrganization->id,
            'level' => 4,
        ]);

        $this->hrManager = User::factory()->create([
            'organization_id' => $this->organization->id,
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        // HR route group is now gated by engine_capability:Capability::HR_VIEW
        // (replaces the legacy `permission:view_hr` Spatie middleware). The
        // engine capability HR_VIEW / HR_MANAGE also satisfies the migrated
        // controller helper and FormRequest authorize() gate (formerly the
        // Spatie `manage_hr` permission).
        //
        // Single combined role covers both HR reads and writes — assigning the
        // capabilities as separate calls would silently revoke HR_VIEW because
        // HasScopedRoles::assignScopedRole() deletes any prior role on the same
        // (scope_type, scope_id) before inserting.
        $this->grantEngineCapability(
            $this->hrManager,
            [Capability::HR_VIEW, Capability::HR_MANAGE]
        );
    }

    public function test_index_returns_paginated_employees_scoped_to_actor_organization_with_profile_structure(): void
    {
        $employee = User::factory()->create([
            'name' => 'Aisha HR Employee',
            'email' => 'aisha.hr@example.test',
            'organization_id' => $this->organization->id,
            'department_id' => $this->department->id,
        ]);
        EmployeeProfile::create([
            'user_id' => $employee->id,
            'employee_no' => 'EMP-100',
            'employment_type' => 'full_time',
            'employment_status' => 'active',
        ]);

        $outsideEmployee = User::factory()->create([
            'name' => 'Outside HR Employee',
            'email' => 'outside.hr@example.test',
            'organization_id' => $this->otherOrganization->id,
            'department_id' => $this->otherDepartment->id,
        ]);
        EmployeeProfile::create([
            'user_id' => $outsideEmployee->id,
            'employee_no' => 'EMP-OUT',
            'employment_type' => 'contract',
            'employment_status' => 'active',
        ]);

        $response = $this->actingAs($this->hrManager, 'sanctum')
            ->getJson('/api/hr/employees?search=EMP-100&per_page=5');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'email',
                        'department' => ['id', 'name'],
                        'manager',
                        'employee_profile' => [
                            'id',
                            'employee_no',
                            'employment_type',
                            'employment_status',
                        ],
                    ],
                ],
                'current_page',
                'first_page_url',
                'from',
                'last_page',
                'links',
                'per_page',
                'to',
                'total',
            ]);

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($employee->id));
        $this->assertFalse($ids->contains($outsideEmployee->id));
        $this->assertSame('EMP-100', $response->json('data.0.employee_profile.employee_no'));
    }

    public function test_index_filters_by_status_and_department(): void
    {
        $activeEmployee = User::factory()->create([
            'organization_id' => $this->organization->id,
            'department_id' => $this->department->id,
        ]);
        EmployeeProfile::create([
            'user_id' => $activeEmployee->id,
            'employee_no' => 'EMP-ACTIVE',
            'employment_type' => 'full_time',
            'employment_status' => 'active',
        ]);

        $suspendedEmployee = User::factory()->create([
            'organization_id' => $this->organization->id,
            'department_id' => $this->department->id,
        ]);
        EmployeeProfile::create([
            'user_id' => $suspendedEmployee->id,
            'employee_no' => 'EMP-SUSPENDED',
            'employment_type' => 'part_time',
            'employment_status' => 'suspended',
        ]);

        $response = $this->actingAs($this->hrManager, 'sanctum')
            ->getJson("/api/hr/employees?status=suspended&department_id={$this->department->id}");

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($suspendedEmployee->id));
        $this->assertFalse($ids->contains($activeEmployee->id));
    }

    public function test_show_denies_employee_from_another_organization(): void
    {
        $outsideEmployee = User::factory()->create([
            'organization_id' => $this->otherOrganization->id,
            'department_id' => $this->otherDepartment->id,
        ]);

        $this->actingAs($this->hrManager, 'sanctum')
            ->getJson("/api/hr/employees/{$outsideEmployee->id}")
            ->assertForbidden();
    }

    public function test_show_returns_employee_with_manager_derived_from_department(): void
    {
        $managedDepartment = Department::factory()->create([
            'organization_id' => $this->organization->id,
            'level' => 4,
            'manager_id' => $this->hrManager->id,
        ]);

        $employee = User::factory()->create([
            'organization_id' => $this->organization->id,
            'department_id' => $managedDepartment->id,
        ]);
        EmployeeProfile::create([
            'user_id' => $employee->id,
            'employee_no' => 'EMP-SHOW',
            'employment_type' => 'full_time',
            'employment_status' => 'active',
        ]);

        $response = $this->actingAs($this->hrManager, 'sanctum')
            ->getJson("/api/hr/employees/{$employee->id}");

        $response->assertOk()
            ->assertJsonStructure([
                'id',
                'name',
                'department' => ['id', 'name'],
                'manager' => ['id', 'name'],
                'employee_profile' => [
                    'id',
                    'employee_no',
                    'employment_type',
                    'employment_status',
                ],
            ])
            ->assertJsonPath('manager.id', $managedDepartment->manager_id)
            ->assertJsonPath('department.id', $managedDepartment->id);
    }

    public function test_show_manager_reflects_current_department_manager_not_stored_profile_value(): void
    {
        $originalManager = User::factory()->create([
            'organization_id' => $this->organization->id,
            'department_id' => $this->department->id,
        ]);
        $managedDepartment = Department::factory()->create([
            'organization_id' => $this->organization->id,
            'level' => 4,
            'manager_id' => $originalManager->id,
        ]);

        $employee = User::factory()->create([
            'organization_id' => $this->organization->id,
            'department_id' => $managedDepartment->id,
        ]);

        $newManager = User::factory()->create([
            'organization_id' => $this->organization->id,
            'department_id' => $this->department->id,
        ]);

        $response = $this->actingAs($this->hrManager, 'sanctum')
            ->putJson("/api/hr/employees/{$employee->id}", [
                'employee_no' => 'EMP-CHANGE',
                'hire_date' => '2026-01-15',
                'employment_type' => 'full_time',
                'employment_status' => 'active',
            ]);

        $response->assertOk()
            ->assertJsonPath('manager.id', $originalManager->id);

        $managedDepartment->update(['manager_id' => $newManager->id]);

        $response = $this->actingAs($this->hrManager, 'sanctum')
            ->getJson("/api/hr/employees/{$employee->id}");

        $response->assertOk()
            ->assertJsonPath('manager.id', $newManager->id);
    }

    public function test_update_creates_profile_and_returns_manager_structure(): void
    {
        $managedDepartment = Department::factory()->create([
            'organization_id' => $this->organization->id,
            'level' => 4,
            'manager_id' => $this->hrManager->id,
        ]);

        $employee = User::factory()->create([
            'organization_id' => $this->organization->id,
            'department_id' => $managedDepartment->id,
        ]);

        $response = $this->actingAs($this->hrManager, 'sanctum')
            ->putJson("/api/hr/employees/{$employee->id}", [
                'employee_no' => 'EMP-NEW',
                'hire_date' => '2026-01-15',
                'employment_type' => 'contract',
                'employment_status' => 'active',
                'notes' => 'Created by HR test',
            ]);

        $response->assertOk()
            ->assertJsonStructure([
                'id',
                'user_id',
                'employee_no',
                'hire_date',
                'employment_type',
                'employment_status',
                'manager' => ['id', 'name'],
            ])
            ->assertJsonPath('employee_no', 'EMP-NEW')
            ->assertJsonPath('employment_type', 'contract')
            ->assertJsonPath('manager.id', $managedDepartment->manager_id);

        $this->assertDatabaseHas('employee_profiles', [
            'user_id' => $employee->id,
            'employee_no' => 'EMP-NEW',
            'employment_status' => 'active',
        ]);
        $this->assertDatabaseHas('users', [
            'id' => $employee->id,
            'department_id' => $managedDepartment->id,
        ]);
    }

    public function test_update_reflects_department_manager_change_immediately(): void
    {
        $firstManager = User::factory()->create([
            'organization_id' => $this->organization->id,
            'department_id' => $this->department->id,
        ]);
        $managedDepartment = Department::factory()->create([
            'organization_id' => $this->organization->id,
            'level' => 4,
            'manager_id' => $firstManager->id,
        ]);

        $secondManager = User::factory()->create([
            'organization_id' => $this->organization->id,
            'department_id' => $this->department->id,
        ]);

        $employee = User::factory()->create([
            'organization_id' => $this->organization->id,
            'department_id' => $managedDepartment->id,
        ]);

        $firstResponse = $this->actingAs($this->hrManager, 'sanctum')
            ->getJson("/api/hr/employees/{$employee->id}");

        $firstResponse->assertOk()
            ->assertJsonPath('manager.id', $firstManager->id);

        $managedDepartment->update(['manager_id' => $secondManager->id]);

        $secondResponse = $this->actingAs($this->hrManager, 'sanctum')
            ->getJson("/api/hr/employees/{$employee->id}");

        $secondResponse->assertOk()
            ->assertJsonPath('manager.id', $secondManager->id);
    }

    public function test_update_rejects_invalid_profile_values(): void
    {
        $employee = User::factory()->create([
            'organization_id' => $this->organization->id,
            'department_id' => $this->department->id,
        ]);

        $this->actingAs($this->hrManager, 'sanctum')
            ->putJson("/api/hr/employees/{$employee->id}", [
                'employment_type' => 'temporary',
                'employment_status' => 'retired',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['employment_type', 'employment_status']);
    }

    public function test_update_requires_manage_hr_capability(): void
    {
        $viewer = User::factory()->create([
            'organization_id' => $this->organization->id,
            'department_id' => $this->department->id,
        ]);
        // Engine route middleware is satisfied by HR_VIEW (no Spatie grant needed);
        // the engine grant covers the route gate. HR_MANAGE is intentionally NOT
        // granted so the FormRequest denies writes via AccessDecision.
        $this->grantEngineCapability($viewer, Capability::HR_VIEW);

        $employee = User::factory()->create([
            'organization_id' => $this->organization->id,
            'department_id' => $this->department->id,
        ]);

        $this->actingAs($viewer, 'sanctum')
            ->putJson("/api/hr/employees/{$employee->id}", [
                'employment_type' => 'full_time',
                'employment_status' => 'active',
            ])
            ->assertForbidden();
    }

    public function test_statistics_are_scoped_to_actor_organization(): void
    {
        $activeEmployee = User::factory()->create(['organization_id' => $this->organization->id]);
        EmployeeProfile::create([
            'user_id' => $activeEmployee->id,
            'employee_no' => 'EMP-STATS-1',
            'employment_type' => 'full_time',
            'employment_status' => 'active',
        ]);

        $suspendedEmployee = User::factory()->create(['organization_id' => $this->organization->id]);
        EmployeeProfile::create([
            'user_id' => $suspendedEmployee->id,
            'employee_no' => 'EMP-STATS-2',
            'employment_type' => 'contract',
            'employment_status' => 'suspended',
        ]);

        $outsideEmployee = User::factory()->create(['organization_id' => $this->otherOrganization->id]);
        EmployeeProfile::create([
            'user_id' => $outsideEmployee->id,
            'employee_no' => 'EMP-STATS-OUT',
            'employment_type' => 'part_time',
            'employment_status' => 'terminated',
        ]);

        $response = $this->actingAs($this->hrManager, 'sanctum')
            ->getJson('/api/hr/employees/stats');

        $response->assertOk()
            ->assertJsonStructure(['total', 'by_status', 'by_type'])
            ->assertJsonPath('total', 2)
            ->assertJsonPath('by_status.active', 1)
            ->assertJsonPath('by_status.suspended', 1)
            ->assertJsonMissingPath('by_status.terminated')
            ->assertJsonPath('by_type.full_time', 1)
            ->assertJsonPath('by_type.contract', 1);
    }

    public function test_view_hr_requires_capability_and_organization_membership(): void
    {
        $withoutCapability = User::factory()->create([
            'organization_id' => $this->organization->id,
            'department_id' => $this->department->id,
        ]);

        $this->actingAs($withoutCapability, 'sanctum')
            ->getJson('/api/hr/employees')
            ->assertForbidden();

        $orphanViewer = User::factory()->create([
            'organization_id' => null,
            'department_id' => null,
        ]);
        // Engine route middleware needs the HR_VIEW capability (granted), but the
        // user has no organization, so the engine's same-organization gate denies
        // before the controller helper even runs.
        $this->grantEngineCapability($orphanViewer, Capability::HR_VIEW);

        $this->actingAs($orphanViewer, 'sanctum')
            ->getJson('/api/hr/employees')
            ->assertForbidden();
    }

    /**
     * A4 — Cross-org HR manager must not be able to PUT /update a foreign
     * employee. The controller's `assertSameOrganization` rejects with 403
     * AFTER the FormRequest authorize() and route gate pass (because the
     * actor holds HR_MANAGE legitimately). No row in `employee_profiles`
     * is created/updated and the target user's department_id stays put.
     */
    public function test_cross_org_actor_cannot_update_foreign_employee(): void
    {
        $foreignEmployee = User::factory()->create([
            'organization_id' => $this->otherOrganization->id,
            'department_id' => $this->otherDepartment->id,
        ]);
        $originalDeptId = $foreignEmployee->department_id;

        $response = $this->actingAs($this->hrManager, 'sanctum')
            ->putJson("/api/hr/employees/{$foreignEmployee->id}", [
                'employee_no' => 'EMP-FOREIGN',
                'hire_date' => '2026-01-15',
                'employment_type' => 'contract',
                'employment_status' => 'suspended',
            ]);

        $response->assertForbidden();

        // No HR profile must be created for the foreign employee.
        $this->assertDatabaseMissing('employee_profiles', [
            'user_id' => $foreignEmployee->id,
        ]);

        // The foreign user's department_id must NOT have been changed.
        $foreignEmployee->refresh();
        $this->assertSame($originalDeptId, $foreignEmployee->department_id);
    }
}
