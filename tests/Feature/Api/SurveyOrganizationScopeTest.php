<?php

namespace Tests\Feature\Api;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Surveys\Models\Survey;
use App\Modules\Surveys\Models\SurveyField;
use App\Modules\Surveys\Models\SurveyResponse;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * عزل المؤسسات على الـ controllers المتداخلة في موديول Surveys — P0-11 / R1.
 *
 * يثبت أن:
 * - SC1: مستخدم (admin) في مؤسسة A لا يصل لأقسام/حقول/إجابات/دعوات استبيان خاص لمؤسسة B (403/404).
 * - SC2: تمرير كيان ابن يخص استبياناً مختلفاً عن {survey} في المسار → 404 (لا تسريب).
 * - SC3: مستخدم بدون organization_id (غير super_admin) → 403 صريح على استبيان خاص (إصلاح D-02).
 * - SC4: الاستبيان العام يبقى متاحاً عبر مساره العام، و super_admin (حتى بدون org) لا ينكسر وصوله.
 *
 * ملاحظة: الدور المستخدم للفاعل عبر المؤسسات هو `admin` (يملك صلاحيات الاستبيانات لكنه ليس super_admin)
 * حتى يصل الطلب إلى authorizeSurvey بدل أن يحجبه permission:* middleware (landmine #2).
 */
class SurveyOrganizationScopeTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $orgA;

    protected Organization $orgB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeaders(['X-Skip-Csrf' => '1']);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->orgA = Organization::factory()->create();
        $this->orgB = Organization::factory()->create();
    }

    /**
     * @param  Organization|null  $org  مرّر null لحالة null-org (SC3)
     */
    private function makeUser(?Organization $org, ?string $role = null): User
    {
        $user = User::factory()->create([
            'organization_id' => $org?->id,
            'is_active' => true,
        ]);
        if ($role) {
            $user->assignRole($role);
        }

        return $user;
    }

    // ===========================================================
    // SC1 — Cross-org private survey (org-A actor on org-B survey)
    // ===========================================================

    public function test_cross_org_user_cannot_list_sections_of_other_org_survey(): void
    {
        $adminA = $this->makeUser($this->orgA, 'admin');
        $surveyB = Survey::factory()->create(['organization_id' => $this->orgB->id]);

        $response = $this->actingAs($adminA, 'sanctum')
            ->getJson("/api/surveys/{$surveyB->id}/sections");

        $this->assertContains($response->status(), [403, 404], 'يجب منع سرد أقسام استبيان مؤسسة أخرى');
    }

    public function test_cross_org_user_cannot_create_section_in_other_org_survey(): void
    {
        $adminA = $this->makeUser($this->orgA, 'admin');
        $surveyB = Survey::factory()->create(['organization_id' => $this->orgB->id]);

        $response = $this->actingAs($adminA, 'sanctum')
            ->postJson("/api/surveys/{$surveyB->id}/sections", [
                'title' => 'قسم اختباري',
            ]);

        $this->assertContains($response->status(), [403, 404], 'يجب منع إنشاء قسم في استبيان مؤسسة أخرى');
    }

    public function test_cross_org_user_cannot_update_field_in_other_org_survey(): void
    {
        $adminA = $this->makeUser($this->orgA, 'admin');
        $surveyB = Survey::factory()->create(['organization_id' => $this->orgB->id]);
        $fieldB = SurveyField::factory()->create(['survey_id' => $surveyB->id]);

        $response = $this->actingAs($adminA, 'sanctum')
            ->putJson("/api/surveys/{$surveyB->id}/fields/{$fieldB->id}", [
                'label' => 'محدّث',
            ]);

        $this->assertContains($response->status(), [403, 404], 'يجب منع تعديل حقل في استبيان مؤسسة أخرى');
    }

    public function test_cross_org_user_cannot_view_responses_of_other_org_survey(): void
    {
        $adminA = $this->makeUser($this->orgA, 'admin');
        $surveyB = Survey::factory()->create(['organization_id' => $this->orgB->id]);

        $response = $this->actingAs($adminA, 'sanctum')
            ->getJson("/api/surveys/{$surveyB->id}/responses");

        $this->assertContains($response->status(), [403, 404], 'يجب منع عرض إجابات استبيان مؤسسة أخرى');
    }

    public function test_cross_org_user_cannot_create_invitation_in_other_org_survey(): void
    {
        $adminA = $this->makeUser($this->orgA, 'admin');
        $surveyB = Survey::factory()->create(['organization_id' => $this->orgB->id]);

        $response = $this->actingAs($adminA, 'sanctum')
            ->postJson("/api/surveys/{$surveyB->id}/invitations", [
                'email' => 'invitee@example.com',
            ]);

        $this->assertContains($response->status(), [403, 404], 'يجب منع إنشاء دعوة في استبيان مؤسسة أخرى');
    }

    // ===========================================================
    // SC2 — Child-mismatch / IDOR (org held constant on the actor)
    // ===========================================================

    public function test_section_from_other_survey_cannot_be_updated_via_mismatched_survey(): void
    {
        $adminA = $this->makeUser($this->orgA, 'admin');
        // كلا الاستبيانين في مؤسسة الفاعل حتى يُختبر فحص الابن لا فحص المؤسسة
        $surveyOne = Survey::factory()->create(['organization_id' => $this->orgA->id]);
        $surveyTwo = Survey::factory()->create(['organization_id' => $this->orgA->id]);
        $sectionOfTwo = $surveyTwo->sections()->create(['title' => 'قسم آخر', 'order' => 1]);

        $response = $this->actingAs($adminA, 'sanctum')
            ->putJson("/api/surveys/{$surveyOne->id}/sections/{$sectionOfTwo->id}", [
                'title' => 'محاولة عبر استبيان خاطئ',
            ]);

        // 403/404 (engine denies / not found) OR 422 (UpdateSurveySectionRequest
        // withValidator catches the section-belongs-to-other-survey mismatch via
        // domain rule — not an authz decision). Any of these statuses satisfies
        // the security goal: the section cannot be updated via the wrong survey.
        $this->assertContains(
            $response->status(),
            [403, 404, 422],
            'يجب منع تعديل قسم يخص استبياناً آخر عبر مسار مختلف'
        );
    }

    public function test_field_from_other_survey_cannot_be_deleted_via_mismatched_survey(): void
    {
        $adminA = $this->makeUser($this->orgA, 'admin');
        $surveyOne = Survey::factory()->create(['organization_id' => $this->orgA->id]);
        $surveyTwo = Survey::factory()->create(['organization_id' => $this->orgA->id]);
        $fieldOfTwo = SurveyField::factory()->create(['survey_id' => $surveyTwo->id]);

        $response = $this->actingAs($adminA, 'sanctum')
            ->deleteJson("/api/surveys/{$surveyOne->id}/fields/{$fieldOfTwo->id}");

        $this->assertContains($response->status(), [403, 404], 'يجب منع حذف حقل يخص استبياناً آخر عبر مسار مختلف');
    }

    public function test_response_from_other_survey_cannot_be_shown_via_mismatched_survey(): void
    {
        $adminA = $this->makeUser($this->orgA, 'admin');
        $surveyOne = Survey::factory()->create(['organization_id' => $this->orgA->id]);
        $surveyTwo = Survey::factory()->create(['organization_id' => $this->orgA->id]);
        $responseOfTwo = SurveyResponse::factory()->create(['survey_id' => $surveyTwo->id]);

        $response = $this->actingAs($adminA, 'sanctum')
            ->getJson("/api/surveys/{$surveyOne->id}/responses/{$responseOfTwo->id}");

        $this->assertContains($response->status(), [403, 404], 'يجب منع عرض إجابة تخص استبياناً آخر عبر مسار مختلف');
    }

    public function test_invitation_from_other_survey_cannot_be_revoked_via_mismatched_survey(): void
    {
        $adminA = $this->makeUser($this->orgA, 'admin');
        $surveyOne = Survey::factory()->create(['organization_id' => $this->orgA->id]);
        $surveyTwo = Survey::factory()->create(['organization_id' => $this->orgA->id]);
        $invitationOfTwo = $surveyTwo->invitations()->create([
            'email' => 'mismatch@example.com',
            'status' => 'active',
            'max_uses' => 1,
            'used_count' => 0,
            'reminder_count' => 0,
            'created_by' => $adminA->id,
        ]);

        $response = $this->actingAs($adminA, 'sanctum')
            ->postJson("/api/surveys/{$surveyOne->id}/invitations/{$invitationOfTwo->id}/revoke");

        $this->assertContains($response->status(), [403, 404], 'يجب منع إلغاء دعوة تخص استبياناً آخر عبر مسار مختلف');
    }

    // ===========================================================
    // SC3 — Null-org non-super (the D-02 regression, strict 403)
    // ===========================================================

    public function test_null_org_non_super_user_cannot_access_private_survey_sections(): void
    {
        // مستخدم بدون مؤسسة لكن يملك دور admin عالمياً (حتى يمر permission middleware ويصل authorizeSurvey)
        $nullOrgUser = $this->makeUser(null, 'admin');
        $surveyB = Survey::factory()->create(['organization_id' => $this->orgB->id]);

        $this->actingAs($nullOrgUser, 'sanctum')
            ->getJson("/api/surveys/{$surveyB->id}/sections")
            ->assertStatus(403);
    }

    public function test_null_org_non_super_user_cannot_read_private_survey_responses(): void
    {
        $nullOrgUser = $this->makeUser(null, 'admin');
        $surveyB = Survey::factory()->create(['organization_id' => $this->orgB->id]);

        $this->actingAs($nullOrgUser, 'sanctum')
            ->getJson("/api/surveys/{$surveyB->id}/responses")
            ->assertStatus(403);
    }

    // ===========================================================
    // SC4 — Public reachable + super_admin not broken (strict 200)
    // ===========================================================

    public function test_public_survey_remains_reachable_via_public_route(): void
    {
        $publicSurvey = Survey::factory()->public()->published()->create([
            'organization_id' => $this->orgA->id,
        ]);

        $this->getJson("/api/surveys/public/{$publicSurvey->code}")
            ->assertStatus(200);
    }

    public function test_super_admin_can_access_nested_routes_of_any_org_survey(): void
    {
        $superAdmin = $this->makeUser($this->orgA, 'super_admin');
        $surveyB = Survey::factory()->create(['organization_id' => $this->orgB->id]);

        $this->actingAs($superAdmin, 'sanctum')
            ->getJson("/api/surveys/{$surveyB->id}/sections")
            ->assertStatus(200);
    }

    public function test_null_org_super_admin_is_not_blocked_by_org_check(): void
    {
        // super_admin بدون organization_id يجب أن يبقى مسموحاً (يحرس ضد الإصلاح الساذج — landmine #1)
        $nullOrgSuperAdmin = $this->makeUser(null, 'super_admin');
        $surveyB = Survey::factory()->create(['organization_id' => $this->orgB->id]);

        $this->actingAs($nullOrgSuperAdmin, 'sanctum')
            ->getJson("/api/surveys/{$surveyB->id}/sections")
            ->assertStatus(200);
    }
}
