<?php

namespace Tests\Feature\Security;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\ScopedRole;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\EmployeeProfile;
use App\Modules\Projects\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * H-05: raw Eloquent serialization of User/Employee must not leak security
 * columns (2FA hashes, login telemetry) or biometric/social-insurance PII.
 */
class RawModelExposureTest extends TestCase
{
    use GrantsEngineCapability;
    use RefreshDatabase;

    public function test_project_members_endpoint_does_not_leak_security_columns(): void
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id, 'is_active' => true]);

        $admin = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
            'is_active' => true,
        ]);
        $admin->assignRole('super_admin');

        $project = Project::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
            'created_by' => $admin->id,
        ]);

        $member = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
            'is_active' => true,
            'last_login_ip' => '203.0.113.9',
            'failed_login_attempts' => 3,
        ]);
        $member->assignProjectRole($project, ScopedRole::PROJECT_MEMBER, $admin->id);
        Cache::flush();

        $res = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/projects/{$project->id}/members")
            ->assertOk();

        $res->assertJsonMissing(['last_login_ip' => '203.0.113.9']);
        $res->assertJsonMissing(['failed_login_attempts' => 3]);
        $body = $res->json();
        $this->assertNotEmpty($body);
        foreach (['two_factor_secret', 'two_factor_recovery_code_hashes', 'last_login_ip', 'failed_login_attempts', 'locked_until', 'password'] as $leaked) {
            $this->assertArrayNotHasKey($leaked, $body[0], "members payload leaked {$leaked}");
        }
    }

    public function test_hr_employee_index_hides_biometric_and_social_insurance_from_viewer(): void
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id, 'is_active' => true]);

        $viewer = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
            'is_active' => true,
        ]);
        // HR_VIEW only — NOT HR_MANAGE.
        $this->grantEngineCapability($viewer, Capability::HR_VIEW);

        $employee = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
            'is_active' => true,
        ]);
        EmployeeProfile::create([
            'user_id' => $employee->id,
            'employee_no' => 'EMP-555',
            'employment_type' => 'full_time',
            'employment_status' => 'active',
            'social_insurance_number' => '1234567890',
            'fingerprint_number' => 'FP-9',
        ]);
        Cache::flush();

        $res = $this->actingAs($viewer, 'sanctum')->getJson('/api/hr/employees')->assertOk();

        $res->assertJsonMissing(['social_insurance_number' => '1234567890']);
        $res->assertJsonMissing(['fingerprint_number' => 'FP-9']);
        // Non-sensitive fields still present.
        $res->assertJsonFragment(['employee_no' => 'EMP-555']);
    }
}
