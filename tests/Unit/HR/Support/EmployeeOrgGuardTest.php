<?php

namespace Tests\Unit\HR\Support;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\EmployeeCertificate;
use App\Modules\HR\Models\EmployeePersonalInfo;
use App\Modules\HR\Models\EmployeeProfile;
use App\Modules\HR\Support\EmployeeOrgGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\TestCase;

/**
 * EmployeeOrgGuardTest - Phase 2: اشتقاق organization_id من كل كيان HR
 * وفحص Same-Organization بنفس القواعد الموحّدة.
 *
 * يختبر:
 *   - employeeOrgId / profileOrgId / certificateOrgId / personalInfoOrgId.
 *   - sameOrganization: super_admin allowed، null-org denied، cross-org denied.
 *   - abortUnlessSameOrganization: throws AccessDeniedHttpException عند الرفض.
 */
class EmployeeOrgGuardTest extends TestCase
{
    use RefreshDatabase;

    private EmployeeOrgGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guard = new EmployeeOrgGuard;
    }

    // ===== Org id extraction =====

    public function test_employee_org_id_returns_user_org(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);

        $this->assertSame($org->id, $this->guard->employeeOrgId($user));
    }

    public function test_employee_org_id_null_when_user_null(): void
    {
        $this->assertNull($this->guard->employeeOrgId(null));
    }

    public function test_employee_org_id_null_when_user_org_null(): void
    {
        $user = User::factory()->create(['organization_id' => null]);
        $this->assertNull($this->guard->employeeOrgId($user));
    }

    public function test_profile_org_id_returns_user_org(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $profile = EmployeeProfile::factory()->create(['user_id' => $user->id]);

        $this->assertSame($org->id, $this->guard->profileOrgId($profile));
    }

    public function test_profile_org_id_null_when_user_null(): void
    {
        $profile = new EmployeeProfile;
        $this->assertNull($this->guard->profileOrgId($profile));
    }

    public function test_certificate_org_id_returns_employee_org(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $profile = EmployeeProfile::factory()->create(['user_id' => $user->id]);
        $certificate = EmployeeCertificate::factory()->create(['employee_profile_id' => $profile->id]);

        $this->assertSame($org->id, $this->guard->certificateOrgId($certificate));
    }

    public function test_certificate_org_id_null_when_profile_null(): void
    {
        $certificate = new EmployeeCertificate;
        $this->assertNull($this->guard->certificateOrgId($certificate));
    }

    public function test_personal_info_org_id_returns_employee_org(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $profile = EmployeeProfile::factory()->create(['user_id' => $user->id]);
        $info = EmployeePersonalInfo::factory()->create(['employee_profile_id' => $profile->id]);

        $this->assertSame($org->id, $this->guard->personalInfoOrgId($info));
    }

    public function test_personal_info_org_id_null_when_profile_null(): void
    {
        $info = new EmployeePersonalInfo;
        $this->assertNull($this->guard->personalInfoOrgId($info));
    }

    // ===== sameOrganization =====

    public function test_same_organization_super_admin_always_allowed(): void
    {
        $superAdmin = User::factory()->create(['organization_id' => null]);
        $this->grantCanonicalSuperAdmin($superAdmin);

        $org = Organization::factory()->create();
        $this->assertTrue($this->guard->sameOrganization($superAdmin, $org->id));
        $this->assertTrue($this->guard->sameOrganization($superAdmin, null));
        $this->assertTrue($this->guard->sameOrganization($superAdmin, 99999));
    }

    public function test_same_organization_same_org_allowed(): void
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $actor = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
        ]);

        $this->assertTrue($this->guard->sameOrganization($actor, $org->id));
    }

    public function test_same_organization_cross_org_denied(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);
        $actor = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
        ]);

        $this->assertFalse($this->guard->sameOrganization($actor, $orgB->id));
    }

    public function test_same_organization_null_actor_org_denied(): void
    {
        $dept = Department::factory()->create();
        $actor = User::factory()->create([
            'organization_id' => null,
            'department_id' => $dept->id,
        ]);
        $org = Organization::factory()->create();

        $this->assertFalse($this->guard->sameOrganization($actor, $org->id));
    }

    public function test_same_organization_null_target_org_denied(): void
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $actor = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
        ]);

        $this->assertFalse($this->guard->sameOrganization($actor, null));
    }

    // ===== abortUnlessSameOrganization =====

    public function test_abort_unless_same_organization_throws_on_cross_org(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);
        $actor = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
        ]);

        $this->expectException(AccessDeniedHttpException::class);
        $this->guard->abortUnlessSameOrganization($actor, $orgB->id);
    }

    public function test_abort_unless_same_organization_passes_on_match(): void
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $actor = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
        ]);

        $this->guard->abortUnlessSameOrganization($actor, $org->id);
        $this->assertTrue(true);
    }

    public function test_abort_unless_same_organization_passes_for_super_admin(): void
    {
        $superAdmin = User::factory()->create(['organization_id' => null]);
        $this->grantCanonicalSuperAdmin($superAdmin);

        $this->guard->abortUnlessSameOrganization($superAdmin, 99999);
        $this->assertTrue(true);
    }
}
