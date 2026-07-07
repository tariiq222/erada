<?php

namespace Tests\Unit\HR\Scopes;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\EmployeeCertificate;
use App\Modules\HR\Models\EmployeePersonalInfo;
use App\Modules\HR\Models\EmployeeProfile;
use App\Modules\HR\Scopes\UserEmployeeScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UserEmployeeScopeTest - Phase 2: عزل قوائم الموظفين على مستوى المؤسسة.
 *
 * يختبر جميع المتغيرات الأربعة (Users / Profiles / Certificates / PersonalInfo)
 * على الحالات الأربع:
 *   - super_admin: لا فلتر.
 *   - user من org A: يرى org A فقط.
 *   - user من org B: لا يرى org A.
 *   - user بلا organization_id: fail-closed (whereRaw('false')).
 */
class UserEmployeeScopeTest extends TestCase
{
    use RefreshDatabase;

    private UserEmployeeScope $scope;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scope = new UserEmployeeScope;
    }

    // ===== applyToUsers =====

    public function test_apply_to_users_super_admin_sees_all(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);
        Department::factory()->create(['organization_id' => $orgB->id]);

        $superAdmin = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
        ]);
        $superAdmin->assignRole('super_admin');

        User::factory()->count(2)->create(['organization_id' => $orgA->id]);
        User::factory()->count(3)->create(['organization_id' => $orgB->id]);

        $query = User::query();
        $this->scope->applyToUsers($query, $superAdmin);
        $this->assertSame(6, $query->count()); // 1 super_admin + 2 + 3
    }

    public function test_apply_to_users_filters_to_actor_org(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);
        Department::factory()->create(['organization_id' => $orgB->id]);

        $userA = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
        ]);

        User::factory()->count(2)->create(['organization_id' => $orgA->id]);
        User::factory()->count(3)->create(['organization_id' => $orgB->id]);

        $query = User::query();
        $this->scope->applyToUsers($query, $userA);
        $this->assertSame(3, $query->count()); // userA + 2 in orgA
    }

    public function test_apply_to_users_null_org_returns_empty(): void
    {
        $dept = Department::factory()->create();
        $user = User::factory()->create([
            'organization_id' => null,
            'department_id' => $dept->id,
        ]);

        User::factory()->count(3)->create(['organization_id' => Organization::factory()->create()->id]);

        $query = User::query();
        $this->scope->applyToUsers($query, $user);
        $this->assertSame(0, $query->count());
    }

    // ===== applyToProfiles =====

    public function test_apply_to_profiles_super_admin_sees_all(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);
        $deptB = Department::factory()->create(['organization_id' => $orgB->id]);

        $superAdmin = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
        ]);
        $superAdmin->assignRole('super_admin');

        $userA = User::factory()->create(['organization_id' => $orgA->id]);
        $userB = User::factory()->create(['organization_id' => $orgB->id]);
        EmployeeProfile::factory()->create(['user_id' => $userA->id]);
        EmployeeProfile::factory()->create(['user_id' => $userB->id]);

        $query = EmployeeProfile::query();
        $this->scope->applyToProfiles($query, $superAdmin);
        $this->assertSame(2, $query->count());
    }

    public function test_apply_to_profiles_filters_via_user_relation(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);

        $userA = User::factory()->create(['organization_id' => $orgA->id]);
        $userB = User::factory()->create(['organization_id' => $orgB->id]);
        $actor = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
        ]);

        EmployeeProfile::factory()->create(['user_id' => $userA->id]);
        EmployeeProfile::factory()->create(['user_id' => $userB->id]);

        $query = EmployeeProfile::query();
        $this->scope->applyToProfiles($query, $actor);
        $this->assertSame(1, $query->count());
        $this->assertSame($userA->id, $query->first()->user_id);
    }

    public function test_apply_to_profiles_null_org_returns_empty(): void
    {
        $dept = Department::factory()->create();
        $actor = User::factory()->create([
            'organization_id' => null,
            'department_id' => $dept->id,
        ]);

        $user = User::factory()->create(['organization_id' => Organization::factory()->create()->id]);
        EmployeeProfile::factory()->create(['user_id' => $user->id]);

        $query = EmployeeProfile::query();
        $this->scope->applyToProfiles($query, $actor);
        $this->assertSame(0, $query->count());
    }

    // ===== applyToCertificates =====

    public function test_apply_to_certificates_filters_via_employee_profile_user(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);

        $userA = User::factory()->create(['organization_id' => $orgA->id]);
        $userB = User::factory()->create(['organization_id' => $orgB->id]);
        $profileA = EmployeeProfile::factory()->create(['user_id' => $userA->id]);
        $profileB = EmployeeProfile::factory()->create(['user_id' => $userB->id]);

        EmployeeCertificate::factory()->create(['employee_profile_id' => $profileA->id]);
        EmployeeCertificate::factory()->create(['employee_profile_id' => $profileB->id]);

        $actor = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
        ]);

        $query = EmployeeCertificate::query();
        $this->scope->applyToCertificates($query, $actor);
        $this->assertSame(1, $query->count());
    }

    public function test_apply_to_certificates_super_admin_sees_all(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);

        $superAdmin = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
        ]);
        $superAdmin->assignRole('super_admin');

        $userA = User::factory()->create(['organization_id' => $orgA->id]);
        $userB = User::factory()->create(['organization_id' => $orgB->id]);
        $profileA = EmployeeProfile::factory()->create(['user_id' => $userA->id]);
        $profileB = EmployeeProfile::factory()->create(['user_id' => $userB->id]);

        EmployeeCertificate::factory()->create(['employee_profile_id' => $profileA->id]);
        EmployeeCertificate::factory()->create(['employee_profile_id' => $profileB->id]);

        $query = EmployeeCertificate::query();
        $this->scope->applyToCertificates($query, $superAdmin);
        $this->assertSame(2, $query->count());
    }

    // ===== applyToPersonalInfo =====

    public function test_apply_to_personal_info_filters_via_employee_profile_user(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);

        $userA = User::factory()->create(['organization_id' => $orgA->id]);
        $userB = User::factory()->create(['organization_id' => $orgB->id]);
        $profileA = EmployeeProfile::factory()->create(['user_id' => $userA->id]);
        $profileB = EmployeeProfile::factory()->create(['user_id' => $userB->id]);

        EmployeePersonalInfo::factory()->create(['employee_profile_id' => $profileA->id]);
        EmployeePersonalInfo::factory()->create(['employee_profile_id' => $profileB->id]);

        $actor = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
        ]);

        $query = EmployeePersonalInfo::query();
        $this->scope->applyToPersonalInfo($query, $actor);
        $this->assertSame(1, $query->count());
    }

    public function test_apply_to_personal_info_null_org_returns_empty(): void
    {
        $dept = Department::factory()->create();
        $actor = User::factory()->create([
            'organization_id' => null,
            'department_id' => $dept->id,
        ]);

        $user = User::factory()->create(['organization_id' => Organization::factory()->create()->id]);
        $profile = EmployeeProfile::factory()->create(['user_id' => $user->id]);
        EmployeePersonalInfo::factory()->create(['employee_profile_id' => $profile->id]);

        $query = EmployeePersonalInfo::query();
        $this->scope->applyToPersonalInfo($query, $actor);
        $this->assertSame(0, $query->count());
    }
}
