<?php

namespace Tests\Unit\HR\Policies;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\EmployeePersonalInfo;
use App\Modules\HR\Models\EmployeeProfile;
use App\Modules\HR\Policies\EmployeePersonalInfoPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * EmployeePersonalInfoPolicyTest - Phase 2: per-record org-isolation for personal info.
 *
 * نفس أنماط EmployeeProfilePolicyTest لكن لـ PersonalInfo.
 * الـ org يُشتق عبر employeeProfile.user.organization_id.
 */
class EmployeePersonalInfoPolicyTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    private EmployeePersonalInfoPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new EmployeePersonalInfoPolicy;
    }

    public function test_super_admin_bypasses_all(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);
        $userB = User::factory()->create(['organization_id' => $orgB->id]);
        $profileB = EmployeeProfile::factory()->create(['user_id' => $userB->id]);
        $infoB = EmployeePersonalInfo::factory()->create(['employee_profile_id' => $profileB->id]);

        $superAdmin = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
        ]);
        $superAdmin->assignRole('super_admin');

        $this->assertTrue($this->policy->view($superAdmin, $infoB));
        $this->assertTrue($this->policy->update($superAdmin, $infoB));
        $this->assertTrue($this->policy->delete($superAdmin, $infoB));
    }

    public function test_null_org_actor_denied(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $profile = EmployeeProfile::factory()->create(['user_id' => $user->id]);
        $info = EmployeePersonalInfo::factory()->create(['employee_profile_id' => $profile->id]);

        $actor = User::factory()->create([
            'organization_id' => null,
            'department_id' => Department::factory()->create(['organization_id' => $org->id])->id,
        ]);
        $this->grantEngineCapability($actor, Capability::HR_MANAGE);

        $this->assertFalse($this->policy->view($actor, $info));
        $this->assertFalse($this->policy->update($actor, $info));
        $this->assertFalse($this->policy->delete($actor, $info));
    }

    public function test_cross_org_denied(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);
        $userB = User::factory()->create(['organization_id' => $orgB->id]);
        $profileB = EmployeeProfile::factory()->create(['user_id' => $userB->id]);
        $infoB = EmployeePersonalInfo::factory()->create(['employee_profile_id' => $profileB->id]);

        $actor = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
        ]);
        $this->grantEngineCapability($actor, Capability::HR_MANAGE);

        $this->assertFalse($this->policy->view($actor, $infoB));
        $this->assertFalse($this->policy->update($actor, $infoB));
        $this->assertFalse($this->policy->delete($actor, $infoB));
    }

    public function test_same_org_view_requires_hr_view(): void
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $profile = EmployeeProfile::factory()->create(['user_id' => $user->id]);
        $info = EmployeePersonalInfo::factory()->create(['employee_profile_id' => $profile->id]);

        $actor = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
        ]);
        // No grant.

        $this->assertFalse($this->policy->view($actor, $info));
    }

    public function test_same_org_view_allowed_with_hr_view(): void
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $profile = EmployeeProfile::factory()->create(['user_id' => $user->id]);
        $info = EmployeePersonalInfo::factory()->create(['employee_profile_id' => $profile->id]);

        $actor = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
        ]);
        $this->grantEngineCapability($actor, Capability::HR_VIEW);

        $this->assertTrue($this->policy->view($actor, $info));
    }

    public function test_same_org_update_allowed_with_hr_manage(): void
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $profile = EmployeeProfile::factory()->create(['user_id' => $user->id]);
        $info = EmployeePersonalInfo::factory()->create(['employee_profile_id' => $profile->id]);

        $actor = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
        ]);
        $this->grantEngineCapability($actor, Capability::HR_MANAGE);

        $this->assertTrue($this->policy->update($actor, $info));
        $this->assertTrue($this->policy->delete($actor, $info));
    }

    public function test_orphan_personal_info_denied(): void
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $actor = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
        ]);
        $this->grantEngineCapability($actor, Capability::HR_MANAGE);

        $orphan = new EmployeePersonalInfo;
        $orphan->employee_profile_id = null;

        $this->assertFalse($this->policy->view($actor, $orphan));
        $this->assertFalse($this->policy->update($actor, $orphan));
    }
}
