<?php

namespace Tests\Feature\OVR;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\OVR\Enums\ReportStatus;
use App\Modules\OVR\Enums\SeverityLevel;
use App\Modules\OVR\Models\IncidentReport;
use App\Modules\OVR\Models\IncidentType;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * OVRConfidentialVisibilityTest — اختبارات رؤية التقارير السرّية
 *
 * تعتمد على نموذج AuthZ الموحّد (engine-only):
 *  - OVR_CONFIDENTIAL على تعريف الدور السياقي يمنح رؤية التقارير السرّية
 *  - المُبلِّغ/المُعيَّن يريان دائماً تقريرهم السرّي
 *  - super_admin يتجاوز كل شيء عبر before()
 *
 * ملاحظة: scopeVisibleTo في النموذج تعتمد على AccessDecision (المحرّك)،
 * لذا تُمنح الصلاحيات عبر grantEngineCapability من الرسم canonical مباشرةً.
 */
class OVRConfidentialVisibilityTest extends TestCase
{
    use GrantsEngineCapability;
    use RefreshDatabase;

    private Organization $organizationA;

    private Organization $organizationB;

    private Department $departmentA1;

    private Department $departmentA2;

    private Department $departmentB1;

    private IncidentType $incidentType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        Cache::flush();

        $this->organizationA = Organization::factory()->create();
        $this->organizationB = Organization::factory()->create();

        // الأقسام مرتبطة بمؤسساتها الصحيحة لضمان سلسلة الأنواع السياقية
        $this->departmentA1 = Department::factory()->create(['organization_id' => $this->organizationA->id]);
        $this->departmentA2 = Department::factory()->create(['organization_id' => $this->organizationA->id]);
        $this->departmentB1 = Department::factory()->create(['organization_id' => $this->organizationB->id]);

        $this->incidentType = IncidentType::create([
            'name' => 'Medical Error',
            'name_ar' => 'خطأ طبي',
            'is_active' => true,
        ]);
    }

    /**
     * أنشئ مستخدماً مع منح المحرّك (engine grants) ودور admin/org-functional
     * حتى يراه scopeVisibleTo كعضو على مستوى المؤسسة.
     *
     * @param  string|array<int, string>|null  $capabilities  Capability::OVR_* المراد منحها (null = لا منح)
     * @param  bool  $canViewConfidential  هل تُنشئ تعريف دور مؤقت مع can_view_confidential=true؟
     * @param  string|null  $canonicalRole  اسم الدور canonical ('admin' | 'viewer' | null = لا شيء)
     */
    private function makeUser(
        Organization $organization,
        Department $department,
        string|array|null $capabilities = null,
        bool $canViewConfidential = false,
        ?string $canonicalRole = null
    ): User {
        $user = User::factory()->create([
            'organization_id' => $organization->id,
            'department_id' => $department->id,
            'is_active' => true,
        ]);

        if ($canonicalRole !== null) {
            $canonicalRole === 'super_admin'
                ? $this->grantCanonicalSuperAdmin($user)
                : $this->assignCanonicalRole($user, $canonicalRole);
        }

        if ($capabilities !== null) {
            $caps = is_array($capabilities) ? $capabilities : [$capabilities];

            // مرّر can_view_confidential=true كـ flag للتعريف (define-side gate)
            // حتى تُفعّل طبقة السرّية في IncidentReportPolicy::checkConfidentialAccess.
            $flags = $canViewConfidential ? ['can_view_confidential' => true] : [];

            if ($canViewConfidential) {
                $caps = array_values(array_unique(array_merge($caps, [Capability::OVR_CONFIDENTIAL])));
            }

            // إسناد دور canonical على مستوى المؤسسة لمنح المحرّك مباشرةً
            $this->grantEngineCapability(
                $user,
                $caps,
                'organization',
                $organization->id,
                definitionFlags: $flags
            );
        }

        return $user;
    }

    private function makeReport(User $reporter, Department $department, Organization $organization, array $override = []): IncidentReport
    {
        return IncidentReport::create(array_merge([
            'organization_id' => $organization->id,
            'reporter_id' => $reporter->id,
            'reporter_name' => $reporter->name,
            'reporter_email' => $reporter->email,
            'reporter_department_id' => $department->id,
            'incident_datetime' => now(),
            'is_patient_related' => false,
            'informed_authority' => false,
            'incident_type_id' => $this->incidentType->id,
            'incident_description' => 'desc',
            'immediate_action_required' => false,
            'severity_level' => SeverityLevel::High,
            'status' => ReportStatus::New,
            'is_confidential' => false,
        ], $override));
    }

    /**
     * مستخدم يملك OVR_VIEW (عبر الدور السياقي) لكن بدون OVR_CONFIDENTIAL:
     * - لا يرى التقرير السرّي في القائمة (scopeVisibleTo يحجبه)
     * - يحصل على 403 عند محاولة عرضه مباشرة (checkConfidentialAccess تمنع)
     */
    public function test_view_all_without_view_confidential_cannot_see_confidential(): void
    {
        // مستخدم مع OVR_VIEW عبر منح المحرّك على دور غير-إداري — بلا OVR_CONFIDENTIAL.
        // ملاحظة: لا نستخدم دور admin Spatie لأن is_admin_role=true يمنح
        // جميع القدرات (بما فيها OVR_CONFIDENTIAL) ويرفع طبقة السرّية.
        $viewer = $this->makeUser(
            $this->organizationA,
            $this->departmentA1,
            Capability::OVR_VIEW
        );
        $reporter = $this->makeUser($this->organizationA, $this->departmentA2);
        $report = $this->makeReport($reporter, $this->departmentA2, $this->organizationA, [
            'is_confidential' => true,
        ]);

        // القائمة: 200 لكن التقرير السرّي غائب (scopeVisibleTo يحجبه بدون OVR_CONFIDENTIAL)
        $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/ovr/incidents')
            ->assertStatus(200)
            ->assertJsonMissing(['report_number' => $report->report_number]);

        // العرض المفرد: 403 (checkConfidentialAccess تمنع الوصول)
        $this->actingAs($viewer, 'sanctum')
            ->getJson("/api/ovr/incidents/{$report->report_number}")
            ->assertStatus(403);
    }

    /**
     * المُبلِّغ يرى دائماً تقريره السرّي (checkConfidentialAccess: reporter_id === user.id)
     */
    public function test_reporter_sees_own_confidential_report(): void
    {
        // المُبلِّغ نفسه يحتاج OVR_VIEW فقط — صلاحية رؤية التقرير الخاص به
        // (owner_floor + reporter relation في scopeVisibleTo) تكفي.
        $reporter = $this->makeUser(
            $this->organizationA,
            $this->departmentA1,
            Capability::OVR_VIEW
        );
        $report = $this->makeReport($reporter, $this->departmentA1, $this->organizationA, [
            'is_confidential' => true,
        ]);

        $this->actingAs($reporter, 'sanctum')
            ->getJson('/api/ovr/incidents')
            ->assertStatus(200)
            ->assertJsonFragment(['report_number' => $report->report_number]);

        $this->actingAs($reporter, 'sanctum')
            ->getJson("/api/ovr/incidents/{$report->report_number}")
            ->assertStatus(200);
    }

    /**
     * المُعيَّن يرى التقرير السرّي المُسند إليه (checkConfidentialAccess: assigned_to === user.id)
     */
    public function test_assignee_sees_assigned_confidential_report(): void
    {
        $assignee = $this->makeUser(
            $this->organizationA,
            $this->departmentA1,
            Capability::OVR_VIEW
        );
        $reporter = $this->makeUser($this->organizationA, $this->departmentA2);
        $report = $this->makeReport($reporter, $this->departmentA2, $this->organizationA, [
            'is_confidential' => true,
            'assigned_to' => $assignee->id,
        ]);

        $this->actingAs($assignee, 'sanctum')
            ->getJson('/api/ovr/incidents')
            ->assertStatus(200)
            ->assertJsonFragment(['report_number' => $report->report_number]);

        $this->actingAs($assignee, 'sanctum')
            ->getJson("/api/ovr/incidents/{$report->report_number}")
            ->assertStatus(200);
    }

    /**
     * مستخدم بدور سياقي يحمل can_view_confidential=true يرى التقارير السرّية
     */
    public function test_user_with_view_confidential_sees_confidential_reports(): void
    {
        // دور مؤقت confidential_viewer مع can_view_confidential=true
        // + منح OVR_CONFIDENTIAL في مصفوفة الصلاحيات
        // + reporter من قسم مختلف ليُفعّل التقرير عبر cross-dept visibility
        //   (يحتاج المُشاهد رؤية التقارير خارج قسمه — لذا نستخدم admin
        //    Spatie role على مستوى المؤسسة لتجاوز فلتر القسم؛ is_admin_role=true
        //    يمدد الرؤية لعموم المؤسسة مع can_view_confidential=true على دور
        //    سرّي مخصّص يعلو فوق admin).
        $viewer = $this->makeUser(
            $this->organizationA,
            $this->departmentA1,
            Capability::OVR_VIEW,
            canViewConfidential: true,
            canonicalRole: 'admin'
        );
        $reporter = $this->makeUser($this->organizationA, $this->departmentA2);
        $report = $this->makeReport($reporter, $this->departmentA2, $this->organizationA, [
            'is_confidential' => true,
        ]);

        $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/ovr/incidents')
            ->assertStatus(200)
            ->assertJsonFragment(['report_number' => $report->report_number]);

        $this->actingAs($viewer, 'sanctum')
            ->getJson("/api/ovr/incidents/{$report->report_number}")
            ->assertStatus(200);
    }

    /**
     * التقارير غير السرّية لا تتأثر بأي قيود
     *
     * admin Spatie role يُفعّل grantsAtOrganization ليظهر التقرير غير السرّي
     * عبر حدود الإدارات (المُبلِّغ في قسم آخر).
     */
    public function test_non_confidential_reports_unaffected(): void
    {
        $viewer = $this->makeUser(
            $this->organizationA,
            $this->departmentA1,
            Capability::OVR_VIEW,
            canonicalRole: 'admin'
        );
        $reporter = $this->makeUser($this->organizationA, $this->departmentA2);
        $report = $this->makeReport($reporter, $this->departmentA2, $this->organizationA);

        $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/ovr/incidents')
            ->assertStatus(200)
            ->assertJsonFragment(['report_number' => $report->report_number]);

        $this->actingAs($viewer, 'sanctum')
            ->getJson("/api/ovr/incidents/{$report->report_number}")
            ->assertStatus(200);
    }

    /**
     * super_admin يرى التقارير السرّية عبر before() في الـ Policy
     *
     * ملاحظة: super_admin Spatie role لا يحمل scoped_role_definition ضمن
     * الموجز المهاجر (الـ migration لا يبذر سوى admin/viewer). نعتمد على
     * AccessDecision::isSuperAdmin() البايباس داخل view() لتجاوز طبقة السرّية،
     * ونُفعّل grantsAtOrganization عبر منح OVR_VIEW على مستوى المؤسسة يدوياً
     * (لدور org-functional scoped) ليظهر التقرير في القائمة (scopeVisibleTo).
     */
    public function test_super_admin_sees_confidential_reports(): void
    {
        $superAdmin = $this->makeUser(
            $this->organizationA,
            $this->departmentA1,
            Capability::OVR_VIEW,
            canonicalRole: 'super_admin'
        );

        $reporter = $this->makeUser($this->organizationA, $this->departmentA2);
        $report = $this->makeReport($reporter, $this->departmentA2, $this->organizationA, [
            'is_confidential' => true,
        ]);

        $this->actingAs($superAdmin, 'sanctum')
            ->getJson('/api/ovr/incidents')
            ->assertStatus(200)
            ->assertJsonFragment(['report_number' => $report->report_number]);

        $this->actingAs($superAdmin, 'sanctum')
            ->getJson("/api/ovr/incidents/{$report->report_number}")
            ->assertStatus(200);
    }
}
