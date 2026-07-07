<?php

namespace Tests\Unit\Policies;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\ScopedRole;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use App\Modules\RiskManagement\Models\Risk;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * يضمن أن تجاوز super_admin للـ Policies يتم مركزياً عبر
 * App\Providers\AppServiceProvider::boot() (Gate::before) — وليس عبر
 * before() محلي في كل Policy. هذا يحصر منطق التجاوز في مكان واحد
 * ويقلل سطح الخطأ عند إضافة سياسات جديدة.
 *
 * ملاحظة: الاستدعاء المباشر (new Policy)->method() يتجاوز Gate::before،
 * لذلك تُستخدم Gate::forUser($user)->allows/denies هنا لاختبار المسار
 * الذي يمر عبر البوابة (وهو المسار الذي يستدعيه $this->authorize() في
 * الكنترولرز).
 */
class SuperAdminBypassTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /**
     * مستخدمو super_admin يتجاوزون كل فحوصات البوابة بصرف النظر عن:
     * - المؤسسة (null org, cross-org)
     * - الصلاحيات المباشرة (لا يتطلب منح permissions إضافية)
     * - نوع السياسة (Project / Risk / Strategy / OVR ...)
     */
    public function test_super_admin_bypasses_all_policy_checks(): void
    {
        $superAdmin = User::factory()->create([
            'is_active' => true,
            'organization_id' => null,
            'department_id' => null,
        ]);
        $superAdmin->assignRole('super_admin');

        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $project = Project::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
        ]);
        $risk = Risk::factory()->forOrganization($org)->create();

        $this->assertTrue(Gate::forUser($superAdmin)->allows('view', $project));
        $this->assertTrue(Gate::forUser($superAdmin)->allows('update', $project));
        $this->assertTrue(Gate::forUser($superAdmin)->allows('delete', $project));
        $this->assertTrue(Gate::forUser($superAdmin)->allows('view', $risk));
        $this->assertTrue(Gate::forUser($superAdmin)->allows('update', $risk));
        $this->assertTrue(Gate::forUser($superAdmin)->allows('delete', $risk));
    }

    /**
     * مستخدم غير super_admin يمتلك الصلاحية المطلوبة يمر عبر البوابة.
     * اختبار رجعي للتأكد من أن إزالة before() المحلي لم تكسر السلوك
     * للمستخدمين العاديين.
     */
    public function test_non_super_admin_with_permission_passes(): void
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $member = User::factory()->create([
            'is_active' => true,
            'organization_id' => $org->id,
            'department_id' => $dept->id,
        ]);
        $member->assignRole('member');

        $project = Project::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
        ]);
        $project->members()->attach($member->id, [
            'role' => 'member',
            'scope_type' => ScopedRole::SCOPE_PROJECT,
        ]);
        $risk = Risk::factory()->forOrganization($org)->create();

        $this->assertTrue(
            Gate::forUser($member)->allows('view', $project),
            'member يجب أن يمر Gate::view على Project (يملك view_own_projects)'
        );
        $this->assertTrue(
            Gate::forUser($member)->allows('view', $risk),
            'member يجب أن يمر Gate::view على Risk (يملك view_risks)'
        );
    }

    /**
     * مستخدم غير super_admin بلا صلاحية يُرفض عبر البوابة.
     * اختبار رجعي للتأكد من أن السلوك الرافض ما زال يعمل بعد إزالة
     * before() المحلي.
     */
    public function test_non_super_admin_without_permission_fails(): void
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $stranger = User::factory()->create([
            'is_active' => true,
            'organization_id' => $org->id,
            'department_id' => $dept->id,
        ]);
        // لا منح لأي دور — لا صلاحيات.

        $project = Project::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
        ]);
        $risk = Risk::factory()->forOrganization($org)->create();

        $this->assertTrue(
            Gate::forUser($stranger)->denies('view', $project),
            'stranger بلا view_projects/view_own_projects يجب أن يُرفض'
        );
        $this->assertTrue(
            Gate::forUser($stranger)->denies('view', $risk),
            'stranger بلا view_risks يجب أن يُرفض'
        );
        $this->assertTrue(
            Gate::forUser($stranger)->denies('update', $project),
            'stranger بلا edit_projects يجب أن يُرفض من التحديث'
        );
    }
}
