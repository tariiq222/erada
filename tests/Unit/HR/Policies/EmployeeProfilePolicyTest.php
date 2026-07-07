<?php

namespace Tests\Unit\HR\Policies;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\EmployeeProfile;
use App\Modules\HR\Policies\EmployeeProfilePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * EmployeeProfilePolicyTest - Phase 2: per-record org-isolation for EmployeeProfile.
 *
 * يختبر:
 *   - super_admin bypassed (always true).
 *   - null-org actor denied.
 *   - same-org + HR_VIEW/HR_MANAGE allowed.
 *   - cross-org denied.
 *   - orphan profile (user null) denied.
 */
class EmployeeProfilePolicyTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    private EmployeeProfilePolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new EmployeeProfilePolicy;
    }

    public function test_super_admin_bypasses_all(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);
        $userB = User::factory()->create(['organization_id' => $orgB->id]);
        $profileB = EmployeeProfile::factory()->create(['user_id' => $userB->id]);

        $superAdmin = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
        ]);
        $superAdmin->assignRole('super_admin');

        $this->assertTrue($this->policy->view($superAdmin, $profileB));
        $this->assertTrue($this->policy->update($superAdmin, $profileB));
        $this->assertTrue($this->policy->delete($superAdmin, $profileB));
    }

    public function test_null_org_actor_denied(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $profile = EmployeeProfile::factory()->create(['user_id' => $user->id]);

        $actor = User::factory()->create([
            'organization_id' => null,
            'department_id' => Department::factory()->create(['organization_id' => $org->id])->id,
        ]);
        $this->grantEngineCapability($actor, Capability::HR_MANAGE);

        $this->assertFalse($this->policy->view($actor, $profile));
        $this->assertFalse($this->policy->update($actor, $profile));
        $this->assertFalse($this->policy->delete($actor, $profile));
    }

    public function test_cross_org_denied(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);
        $userB = User::factory()->create(['organization_id' => $orgB->id]);
        $profileB = EmployeeProfile::factory()->create(['user_id' => $userB->id]);

        $actor = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
        ]);
        $this->grantEngineCapability($actor, Capability::HR_MANAGE);

        $this->assertFalse($this->policy->view($actor, $profileB));
        $this->assertFalse($this->policy->update($actor, $profileB));
        $this->assertFalse($this->policy->delete($actor, $profileB));
    }

    public function test_same_org_view_requires_hr_view(): void
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $profile = EmployeeProfile::factory()->create(['user_id' => $user->id]);

        $actor = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
        ]);
        // No HR_VIEW/HR_MANAGE grant.

        $this->assertFalse($this->policy->view($actor, $profile));
    }

    public function test_same_org_view_allowed_with_hr_view(): void
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $profile = EmployeeProfile::factory()->create(['user_id' => $user->id]);

        $actor = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
        ]);
        $this->grantEngineCapability($actor, Capability::HR_VIEW);

        $this->assertTrue($this->policy->view($actor, $profile));
    }

    public function test_same_org_update_requires_hr_manage(): void
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $profile = EmployeeProfile::factory()->create(['user_id' => $user->id]);

        $actor = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
        ]);
        $this->grantEngineCapability($actor, Capability::HR_VIEW); // VIEW only, not MANAGE.

        $this->assertFalse($this->policy->update($actor, $profile));
        $this->assertFalse($this->policy->delete($actor, $profile));
    }

    public function test_same_org_update_allowed_with_hr_manage(): void
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $profile = EmployeeProfile::factory()->create(['user_id' => $user->id]);

        $actor = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
        ]);
        $this->grantEngineCapability($actor, Capability::HR_MANAGE);

        $this->assertTrue($this->policy->update($actor, $profile));
        $this->assertTrue($this->policy->delete($actor, $profile));
    }

    public function test_orphan_profile_denied(): void
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $actor = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
        ]);
        $this->grantEngineCapability($actor, Capability::HR_MANAGE);

        $orphan = new EmployeeProfile;
        $orphan->user_id = null;

        $this->assertFalse($this->policy->view($actor, $orphan));
        $this->assertFalse($this->policy->update($actor, $orphan));
    }

    public function test_create_requires_hr_manage(): void
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);

        $actor = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
        ]);
        $this->grantEngineCapability($actor, Capability::HR_VIEW); // VIEW only.

        $this->assertFalse($this->policy->create($actor));
    }

    public function test_create_allowed_with_hr_manage(): void
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);

        $actor = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
        ]);
        $this->grantEngineCapability($actor, Capability::HR_MANAGE);

        $this->assertTrue($this->policy->create($actor));
    }
}
