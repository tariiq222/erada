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
 * EmployeeStorePayloadTamperingTest - Phase 2: منع payload tampering عند إنشاء Profile.
 *
 * POST /api/hr/employees flows through StoreEmployeeProfileRequest which
 * checks (a) target user_id belongs to actor's org, (b) dept_id belongs
 * to actor's org. Cross-org payload tampering returns 403.
 */
class EmployeeStorePayloadTamperingTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    public function test_user_id_from_other_org_rejected(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);

        $actor = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
        ]);
        $this->grantEngineCapability($actor, [Capability::HR_VIEW, Capability::HR_MANAGE]);

        $orgBUser = User::factory()->create(['organization_id' => $orgB->id]);

        $response = $this->actingAs($actor, 'sanctum')
            ->postJson('/api/hr/employees', [
                'user_id' => $orgBUser->id,
                'employee_no' => 'EMP-TAMPER-1',
                'hire_date' => '2024-01-01',
                'employment_type' => 'full_time',
                'employment_status' => 'active',
                'personal_info' => [
                    'full_name_english' => 'Tampered',
                    'full_name_arabic' => 'تزوير',
                    'nationality' => 'SA',
                    'address' => 'X',
                    'emergency_contact' => 'X',
                    'emergency_phone' => '0500000000',
                    'emergency_contact_relation' => 'brother',
                ],
            ]);

        $response->assertStatus(403);
    }

    public function test_dept_id_from_other_org_rejected(): void
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

        $orgAUser = User::factory()->create(['organization_id' => $orgA->id]);

        $response = $this->actingAs($actor, 'sanctum')
            ->postJson('/api/hr/employees', [
                'user_id' => $orgAUser->id,
                'employee_no' => 'EMP-TAMPER-2',
                'hire_date' => '2024-01-01',
                'employment_type' => 'full_time',
                'employment_status' => 'active',
                'dept_id' => $deptB->id,
                'personal_info' => [
                    'full_name_english' => 'X',
                    'full_name_arabic' => 'س',
                    'nationality' => 'SA',
                    'address' => 'X',
                    'emergency_contact' => 'X',
                    'emergency_phone' => '0500000000',
                    'emergency_contact_relation' => 'brother',
                ],
            ]);

        // Either 403 (FormRequest blocks cross-org dept) or 422 (validation
        // catches the dept_id not existing in actor's org). Both are safe.
        $this->assertContains($response->status(), [403, 422]);
    }

    public function test_null_org_actor_cannot_store(): void
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $user = User::factory()->create(['organization_id' => $org->id]);

        $actor = User::factory()->create([
            'organization_id' => null,
            'department_id' => $dept->id,
        ]);
        $this->grantEngineCapability($actor, [Capability::HR_VIEW, Capability::HR_MANAGE]);

        $response = $this->actingAs($actor, 'sanctum')
            ->postJson('/api/hr/employees', [
                'user_id' => $user->id,
                'employee_no' => 'EMP-NULL-ACTOR',
                'hire_date' => '2024-01-01',
                'employment_type' => 'full_time',
                'employment_status' => 'active',
                'personal_info' => [
                    'full_name_english' => 'X',
                    'full_name_arabic' => 'س',
                    'nationality' => 'SA',
                    'address' => 'X',
                    'emergency_contact' => 'X',
                    'emergency_phone' => '0500000000',
                    'emergency_contact_relation' => 'brother',
                ],
            ]);

        $response->assertStatus(403);
    }

    public function test_super_admin_can_store_profile_for_any_org(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);

        $superAdmin = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
        ]);
        $superAdmin->assignRole('super_admin');

        $orgBUser = User::factory()->create(['organization_id' => $orgB->id]);

        $response = $this->actingAs($superAdmin, 'sanctum')
            ->postJson('/api/hr/employees', [
                'user_id' => $orgBUser->id,
                'employee_no' => 'EMP-SUPERADMIN',
                'hire_date' => '2024-01-01',
                'employment_type' => 'full_time',
                'employment_status' => 'active',
                'personal_info' => [
                    'full_name_english' => 'Super Admin OK',
                    'full_name_arabic' => 'سوبر أدمن',
                    'nationality' => 'SA',
                    'address' => 'X',
                    'emergency_contact' => 'X',
                    'emergency_phone' => '0500000000',
                    'emergency_contact_relation' => 'brother',
                    'national_id' => '1111111111',
                    'national_id_issue_date' => '2020-01-01',
                    'national_id_issue_place' => 'Riyadh',
                    'national_id_expiry_date' => '2030-01-01',
                ],
            ]);

        $response->assertStatus(201);
    }
}
