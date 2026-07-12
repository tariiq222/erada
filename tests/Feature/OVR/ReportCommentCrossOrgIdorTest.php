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
use App\Modules\OVR\Models\ReportComment;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * ReportCommentCrossOrgIdorTest — اختبارات IDOR عبر المؤسسات في تعليقات OVR
 *
 * تتحقق من:
 * 1. عزل المؤسسة: مستخدم مؤسسة A لا يحذف تعليق مؤسسة B (403)
 * 2. صاحب التعليق في نفس المؤسسة يستطيع حذف تعليقه (200)
 * 3. عدم تطابق report_id ↔ comment → 404
 *
 * يستخدم النموذج السياقي (engine-only): أدوار org-scoped + flat perms لـ scopeVisibleTo.
 */
class ReportCommentCrossOrgIdorTest extends TestCase
{
    use GrantsEngineCapability;
    use RefreshDatabase;

    private Organization $organizationA;

    private Organization $organizationB;

    private Department $departmentA;

    private Department $departmentB;

    private IncidentType $incidentType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        Cache::flush();

        $this->organizationA = Organization::factory()->create();
        $this->organizationB = Organization::factory()->create();

        // الأقسام مرتبطة بمؤسساتها الصحيحة لضمان سلسلة الأنواع السياقية
        $this->departmentA = Department::factory()->create(['organization_id' => $this->organizationA->id]);
        $this->departmentB = Department::factory()->create(['organization_id' => $this->organizationB->id]);

        $this->incidentType = IncidentType::create([
            'name' => 'Medical Error',
            'name_ar' => 'خطأ طبي',
            'is_active' => true,
        ]);
    }

    /**
     * أنشئ مستخدماً مع دور سياقي على مستوى المؤسسة.
     *
     * @param  string|null  $scopedRole  'admin'|'viewer'|null
     */
    private function makeUser(
        Organization $organization,
        Department $department,
        ?string $scopedRole = null
    ): User {
        $user = User::factory()->create([
            'organization_id' => $organization->id,
            'department_id' => $department->id,
            'is_active' => true,
        ]);

        if ($scopedRole !== null) {
            $this->assignCanonicalRole($user, $scopedRole, scopeId: $organization->id);
        }

        return $user;
    }

    private function makeReport(User $reporter, Department $department, Organization $organization): IncidentReport
    {
        return IncidentReport::create([
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
        ]);
    }

    private function makeComment(IncidentReport $report, User $author): ReportComment
    {
        return ReportComment::create([
            'report_id' => $report->id,
            'user_id' => $author->id,
            'author_name' => $author->name,
            'text' => 'A test comment',
            'is_internal' => false,
        ]);
    }

    private function deleteUrl(IncidentReport $report, ReportComment $comment): string
    {
        return "/api/ovr/incidents/{$report->report_number}/comments/{$comment->id}";
    }

    /**
     * مستخدم مؤسسة A (حتى مع admin org role) لا يستطيع حذف تعليق مؤسسة B.
     * الحماية: عزل المؤسسة في AccessDecision::sameOrganization() داخل view() Policy.
     */
    public function test_user_with_delete_all_in_other_org_cannot_delete_comment_returns_403(): void
    {
        // مستخدم مؤسسة A مع admin org role + منح OVR_DELETE_ALL
        $orgAUser = $this->makeUser(
            $this->organizationA,
            $this->departmentA,
            'admin'
        );
        $this->grantEngineCapability($orgAUser, Capability::OVR_DELETE_ALL, 'organization', $this->organizationA->id, 'ovr_idor_admin');

        $orgBReporter = $this->makeUser($this->organizationB, $this->departmentB);
        $orgBCommenter = $this->makeUser($this->organizationB, $this->departmentB);

        $orgBReport = $this->makeReport($orgBReporter, $this->departmentB, $this->organizationB);
        $orgBComment = $this->makeComment($orgBReport, $orgBCommenter);

        // يجب أن يُرفض (403) لأن التقرير في مؤسسة B ومستخدم A لا يشاركها
        $response = $this->actingAs($orgAUser, 'sanctum')
            ->deleteJson($this->deleteUrl($orgBReport, $orgBComment));

        $response->assertStatus(403);
        $response->assertJsonStructure(['message']);
        $this->assertDatabaseHas('ovr_report_comments', [
            'id' => $orgBComment->id,
        ]);
    }

    /**
     * صاحب التعليق في نفس المؤسسة يستطيع حذف تعليقه.
     * يمر بـ: view Policy (OVR_VIEW + same org) → comment owner check → 200.
     */
    public function test_comment_owner_in_same_org_can_still_delete_own_comment(): void
    {
        $orgBReporter = $this->makeUser($this->organizationB, $this->departmentB);

        // viewer org role → OVR_VIEW عبر engine؛ ownership يمر عبر reporter/author relation.
        $orgBCommenter = $this->makeUser(
            $this->organizationB,
            $this->departmentB,
            'viewer'
        );

        $orgBReport = $this->makeReport($orgBReporter, $this->departmentB, $this->organizationB);
        $orgBComment = $this->makeComment($orgBReport, $orgBCommenter);

        $this->assertSame($orgBReport->id, $orgBComment->report_id);

        // الحذف بواسطة صاحب التعليق يُقبل
        $response = $this->actingAs($orgBCommenter, 'sanctum')
            ->deleteJson($this->deleteUrl($orgBReport, $orgBComment));

        $response->assertStatus(200);
        $response->assertJsonStructure(['message']);
        $this->assertDatabaseMissing('ovr_report_comments', [
            'id' => $orgBComment->id,
        ]);
    }

    /**
     * عدم تطابق report_id في URL مع report_id الفعلي للتعليق → 404.
     * يضمن أن التعليق ينتمي للتقرير المطلوب (IDOR prevention).
     */
    public function test_cannot_delete_comment_with_mismatched_report_id_returns_404(): void
    {
        $orgBReporter = $this->makeUser($this->organizationB, $this->departmentB);

        // مستخدم مع منح OVR_VIEW + OVR_DELETE_ALL معاً (نفس الـ scoped_role_definition
        // — single-role-per-scope semantics). grantEngineCapability يفرض مصفوفة
        // القدرات على نفس التعريف، لا استدعاء منفصل (الذي يُلغي الأول).
        $orgBUser = $this->makeUser($this->organizationB, $this->departmentB);
        $this->grantEngineCapability(
            $orgBUser,
            [Capability::OVR_VIEW, Capability::OVR_DELETE_ALL],
            'organization',
            $this->organizationB->id,
            'ovr_idor_admin_b'
        );

        $reportX = $this->makeReport($orgBReporter, $this->departmentB, $this->organizationB);
        $reportY = $this->makeReport($orgBReporter, $this->departmentB, $this->organizationB);

        $commentX = $this->makeComment($reportX, $orgBReporter);

        $this->assertSame($reportX->id, $commentX->report_id);
        $this->assertNotSame($reportX->id, $reportY->id);

        // محاولة حذف commentX عبر URL of reportY → 404
        $response = $this->actingAs($orgBUser, 'sanctum')
            ->deleteJson($this->deleteUrl($reportY, $commentX));

        $response->assertStatus(404);
        $this->assertDatabaseHas('ovr_report_comments', [
            'id' => $commentX->id,
        ]);
    }

    /**
     * Comment ownership must not bypass the parent report binding check.
     */
    public function test_comment_owner_cannot_delete_comment_through_sibling_report(): void
    {
        $reporter = $this->makeUser($this->organizationB, $this->departmentB);
        $commentOwner = $this->makeUser($this->organizationB, $this->departmentB, 'viewer');
        $reportX = $this->makeReport($reporter, $this->departmentB, $this->organizationB);
        $reportY = $this->makeReport($reporter, $this->departmentB, $this->organizationB);
        $commentX = $this->makeComment($reportX, $commentOwner);

        $response = $this->actingAs($commentOwner, 'sanctum')
            ->deleteJson($this->deleteUrl($reportY, $commentX));

        $response->assertStatus(404);
        $this->assertDatabaseHas('ovr_report_comments', [
            'id' => $commentX->id,
            'report_id' => $reportX->id,
            'user_id' => $commentOwner->id,
        ]);
    }
}
