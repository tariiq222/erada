<?php

namespace Tests\Feature\Api;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Surveys\Enums\ConflictPolicy;
use App\Modules\Surveys\Enums\ImportStatus;
use App\Modules\Surveys\Models\DataImportRequest;
use App\Modules\Surveys\Models\DataMappingTemplate;
use App\Modules\Surveys\Models\Survey;
use App\Modules\Surveys\Models\SurveyField;
use App\Modules\Surveys\Models\SurveyResponse;
use App\Modules\Surveys\Notifications\DataImportFailedNotification;
use App\Modules\Surveys\Notifications\DataImportPendingNotification;
use App\Modules\Surveys\Services\DataMappingService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * إشعارات استيراد البيانات — P0-12 / R3 (SC3). لا يتغيّر أي كود إشعارات في هذه المرحلة؛
 * هذا الملف يضيف التغطية + حارس الانحدار لمؤسسة مختلفة.
 *
 * - DataImportPending: يُطلق عند حلّ الحالة الأولية إلى Pending (قالب RequireReview)؛
 *   المستلمون = منشئ الاستبيان + super_admin/admin في نفس المؤسسة؛ ولا يُطلق
 *   لمدير مؤسسة أخرى، ولا للحالات Approved/Applied الأولية.
 * - DataImportFailed: يُطلق عند رمي applyImportRequest استثناءً؛ المستلمون =
 *   المراجع (reviewed_by) + منشئ الاستبيان.
 *
 * QUEUE متزامن في الاختبارات؛ Notification::fake يعترض بصرف النظر — لا Bus::fake.
 */
class DataImportNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $org;

    protected Organization $otherOrg;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->org = Organization::factory()->create();
        $this->otherOrg = Organization::factory()->create();
    }

    private function makeAdmin(Organization $org): User
    {
        $user = User::factory()->create([
            'organization_id' => $org->id,
            'is_active' => true,
        ]);
        $user->assignRole('admin');

        return $user;
    }

    /**
     * يبني استبيان مرتبط بمؤسسة وله منشئ + قالب بسياسة محددة، ثم يرفق إجابة
     * تحقّق الحقل المطلوب name (حتى يمرّ validateAnswers في الخدمة).
     */
    private function buildResponseWithTemplate(User $creator, ConflictPolicy $policy): SurveyResponse
    {
        $survey = Survey::factory()->create([
            'organization_id' => $this->org->id,
            'created_by' => $creator->id,
        ]);

        DataMappingTemplate::factory()->create([
            'survey_id' => $survey->id,
            'is_active' => true,
            'conflict_policy' => $policy,
            'mappings' => [
                'name' => ['column' => 'name', 'required' => true],
            ],
        ]);

        $field = SurveyField::factory()->create([
            'survey_id' => $survey->id,
            'field_key' => 'name',
        ]);

        $response = SurveyResponse::factory()->create(['survey_id' => $survey->id]);
        $response->answers()->create([
            'field_id' => $field->id,
            'field_key' => 'name',
            'answer_value' => 'القسم التجريبي',
        ]);

        return $response->fresh(['survey', 'answers']);
    }

    // ===========================================================
    // SC3 — Pending fires to creator + same-org admins, NOT other org
    // ===========================================================

    public function test_pending_notification_fires_to_creator_and_same_org_admins(): void
    {
        Notification::fake();

        $creator = $this->makeAdmin($this->org);
        $sameOrgAdmin = $this->makeAdmin($this->org);
        $otherOrgAdmin = $this->makeAdmin($this->otherOrg);

        $response = $this->buildResponseWithTemplate($creator, ConflictPolicy::RequireReview);

        $requests = app(DataMappingService::class)->createImportRequestsFromResponse($response);

        $this->assertNotEmpty($requests, 'يجب إنشاء طلب استيراد واحد على الأقل');
        $this->assertSame(ImportStatus::Pending, $requests[0]->status, 'يجب أن تكون الحالة الأولية Pending');

        Notification::assertSentTo($creator, DataImportPendingNotification::class);
        Notification::assertSentTo($sameOrgAdmin, DataImportPendingNotification::class);
        Notification::assertNotSentTo($otherOrgAdmin, DataImportPendingNotification::class);
    }

    public function test_pending_notification_not_sent_for_non_pending_initial_status(): void
    {
        Notification::fake();

        $creator = $this->makeAdmin($this->org);
        $sameOrgAdmin = $this->makeAdmin($this->org);

        // Overwrite => الحالة الأولية Approved (لا إشعار Pending)
        $response = $this->buildResponseWithTemplate($creator, ConflictPolicy::Overwrite);

        $requests = app(DataMappingService::class)->createImportRequestsFromResponse($response);

        $this->assertNotEmpty($requests);
        $this->assertSame(ImportStatus::Approved, $requests[0]->status, 'Overwrite يجب أن يحلّ إلى Approved');

        Notification::assertNotSentTo($creator, DataImportPendingNotification::class);
        Notification::assertNotSentTo($sameOrgAdmin, DataImportPendingNotification::class);
    }

    // ===========================================================
    // SC3 — Failed fires to reviewer + creator on apply exception
    // ===========================================================

    public function test_failed_notification_fires_to_reviewer_and_creator_on_apply_exception(): void
    {
        Notification::fake();

        $creator = $this->makeAdmin($this->org);
        $reviewer = $this->makeAdmin($this->org);

        $survey = Survey::factory()->create([
            'organization_id' => $this->org->id,
            'created_by' => $creator->id,
        ]);
        $template = DataMappingTemplate::factory()->create([
            'survey_id' => $survey->id,
            'conflict_policy' => ConflictPolicy::RequireReview,
        ]);
        $response = SurveyResponse::factory()->create(['survey_id' => $survey->id]);

        // payload بقيمة name = null ينتهك قيد NOT NULL على departments.name => يرمي داخل المعاملة
        $request = DataImportRequest::factory()->approved()->create([
            'response_id' => $response->id,
            'template_id' => $template->id,
            'target_table' => 'departments',
            'operation' => 'create',
            'payload' => ['name' => null],
            'reviewed_by' => $reviewer->id,
        ]);

        $success = app(DataMappingService::class)->applyImportRequest($request);

        $this->assertFalse($success, 'يجب أن يفشل التطبيق بسبب انتهاك القيد');
        $this->assertSame(ImportStatus::Failed, $request->fresh()->status, 'يجب أن تصبح الحالة Failed');

        Notification::assertSentTo($reviewer, DataImportFailedNotification::class);
        Notification::assertSentTo($creator, DataImportFailedNotification::class);
    }
}
