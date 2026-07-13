<?php

namespace Tests\Feature\Api\Strategy;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Surveys\Enums\ResponseStatus;
use App\Modules\Surveys\Models\Survey;
use App\Modules\Surveys\Models\SurveyResponse;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * P0-12 — فرض صلاحية review_survey_responses على SurveyResponseController::flag و ::review.
 *
 * سابقاً كان أي مستخدم في نفس المؤسسة يستطيع flag/review.
 * بعد هذا التعديل، يجب أن يمتلك المستخدم صلاحية `review_survey_responses`
 * (بالإضافة إلى انتماء للمؤسسة — دفاع متعمق).
 *
 * المسار يفرض middleware (view_surveys + view_survey_responses) قبل السياسة،
 * لذا لإخضاع السياسة للاختبار نمنح المستخدم view_survey_responses فقط (بدون review_survey_responses).
 */
class SurveyResponseReviewPermissionTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    protected Organization $org;

    protected Survey $survey;

    protected SurveyResponse $response;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->org = Organization::factory()->create();
        $this->survey = Survey::factory()->create([
            'organization_id' => $this->org->id,
        ]);
        $this->response = SurveyResponse::factory()->create([
            'survey_id' => $this->survey->id,
            'status' => ResponseStatus::Submitted,
        ]);
    }

    private function userWith(string $role, ?Organization $org = null): User
    {
        $user = User::factory()->create([
            'organization_id' => ($org ?? $this->org)->id,
            'is_active' => true,
        ]);
        $this->assignCanonicalRole($user, $role);

        return $user;
    }

    /**
     * مستخدم بـ view_survey_responses فقط (بدون review_survey_responses)
     * يتجاوز middleware المسار لكن تُسقطه السياسة.
     */
    private function userWithViewOnly(?Organization $org = null): User
    {
        $user = User::factory()->create([
            'organization_id' => ($org ?? $this->org)->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, Capability::SURVEYS_REVIEW_RESPONSES);

        return $user;
    }

    /**
     * actingAs + withHeaders(['X-Skip-Csrf' => '1']) لضمان تجاوز CSRF في بيئة الاختبار.
     * (متطلب EnsureCsrfForStateChangingApi على طلبات POST — تم تعطيل الـ APP_ENV=testing
     * لأنه يضيع في تحميل bootstrap/app.php، فنضبط container binding مباشرة).
     */
    private function authed(User $user)
    {
        $this->app->instance('env', 'testing');

        return $this->actingAs($user, 'sanctum')->withHeaders(['X-Skip-Csrf' => '1']);
    }

    /** viewer (بلا view_survey_responses) يُسقطه middleware المسار → 403 */
    public function test_member_cannot_flag_response(): void
    {
        $member = $this->userWith('viewer');

        $this->authed($member)
            ->postJson("/api/surveys/{$this->survey->id}/responses/{$this->response->id}/flag", [
                'notes' => 'محاولة flag غير مصرح بها',
            ])
            ->assertStatus(403);
    }

    /** viewer (بلا view_survey_responses) يُسقطه middleware المسار → 403 */
    public function test_member_cannot_review_response(): void
    {
        $member = $this->userWith('viewer');

        $this->authed($member)
            ->postJson("/api/surveys/{$this->survey->id}/responses/{$this->response->id}/review", [
                'status' => 'flagged',
                'notes' => 'محاولة review غير مصرح بها',
            ])
            ->assertStatus(403);
    }

    /** مستخدم بـ view_survey_responses فقط (بلا review_survey_responses) → 403 من السياسة */
    public function test_user_with_view_only_cannot_flag_response(): void
    {
        $user = $this->userWithViewOnly();

        $this->authed($user)
            ->postJson("/api/surveys/{$this->survey->id}/responses/{$this->response->id}/flag", [
                'notes' => 'محاولة flag بصلاحية قراءة فقط',
            ])
            ->assertStatus(403);

        $this->assertDatabaseHas('survey_responses', [
            'id' => $this->response->id,
            'status' => ResponseStatus::Submitted->value,
        ]);
    }

    /** مستخدم بـ view_survey_responses فقط → 403 من السياسة على review أيضاً */
    public function test_user_with_view_only_cannot_review_response(): void
    {
        $user = $this->userWithViewOnly();

        $this->authed($user)
            ->postJson("/api/surveys/{$this->survey->id}/responses/{$this->response->id}/review", [
                'status' => 'invalid',
            ])
            ->assertStatus(403);
    }

    /** admin (يملك review_survey_responses) → 200 على flag */
    public function test_admin_can_flag_response(): void
    {
        $admin = $this->userWith('admin');

        $this->authed($admin)
            ->postJson("/api/surveys/{$this->survey->id}/responses/{$this->response->id}/flag", [
                'notes' => 'إجابة مريبة',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.status', ResponseStatus::Flagged->value);

        $this->assertDatabaseHas('survey_responses', [
            'id' => $this->response->id,
            'status' => ResponseStatus::Flagged->value,
        ]);
    }

    /** admin (يملك review_survey_responses) → 200 على review */
    public function test_admin_can_review_response(): void
    {
        $admin = $this->userWith('admin');

        $this->authed($admin)
            ->postJson("/api/surveys/{$this->survey->id}/responses/{$this->response->id}/review", [
                'status' => 'invalid',
                'notes' => 'إجابة غير صالحة',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.status', ResponseStatus::Invalid->value);
    }

    /** user من مؤسسة أخرى (مع صلاحية admin) → 403 (عزل المؤسسة) */
    public function test_user_from_other_organization_cannot_flag(): void
    {
        $otherOrg = Organization::factory()->create();
        $otherAdmin = $this->userWith('admin', $otherOrg);

        $this->authed($otherAdmin)
            ->postJson("/api/surveys/{$this->survey->id}/responses/{$this->response->id}/flag", [
                'notes' => 'محاولة cross-org',
            ])
            ->assertStatus(403);
    }

    /** user من مؤسسة أخرى → 403 على review */
    public function test_user_from_other_organization_cannot_review(): void
    {
        $otherOrg = Organization::factory()->create();
        $otherAdmin = $this->userWith('admin', $otherOrg);

        $this->authed($otherAdmin)
            ->postJson("/api/surveys/{$this->survey->id}/responses/{$this->response->id}/review", [
                'status' => 'flagged',
            ])
            ->assertStatus(403);
    }

    /** viewer (بلا view_survey_responses) → 403 */
    public function test_viewer_cannot_flag_response(): void
    {
        $viewer = $this->userWith('viewer');

        $this->authed($viewer)
            ->postJson("/api/surveys/{$this->survey->id}/responses/{$this->response->id}/flag", [
                'notes' => 'محاولة',
            ])
            ->assertStatus(403);
    }
}
