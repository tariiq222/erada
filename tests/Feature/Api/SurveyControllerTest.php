<?php

namespace Tests\Feature\Api;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Surveys\Models\Survey;
use App\Modules\Surveys\Models\SurveyField;
use App\Modules\Surveys\Models\SurveyResponse;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SurveyControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Department $department;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeaders(['X-Skip-Csrf' => '1']);

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->department = Department::factory()->create();

        $this->user = User::factory()->create([
            'department_id' => $this->department->id,
            'organization_id' => $this->department->organization_id,
            'is_active' => true,
        ]);
        $this->user->assignRole('super_admin');
    }

    // ========================================
    // اختبارات القراءة (GET)
    // ========================================

    public function test_can_list_surveys(): void
    {
        Survey::factory()->count(5)->create([
            'created_by' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/surveys');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data',
                'links',
                'meta',
            ]);
    }

    public function test_can_filter_surveys_by_status(): void
    {
        Survey::factory()->count(2)->draft()->create(['created_by' => $this->user->id]);
        Survey::factory()->count(3)->published()->create(['created_by' => $this->user->id]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/surveys?status=draft');

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data'));
    }

    public function test_can_search_surveys(): void
    {
        Survey::factory()->create([
            'title' => 'استبيان رضا العملاء',
            'created_by' => $this->user->id,
        ]);
        Survey::factory()->count(3)->create(['created_by' => $this->user->id]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/surveys?search=رضا');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    public function test_can_view_single_survey(): void
    {
        $survey = Survey::factory()->create(['created_by' => $this->user->id]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/surveys/{$survey->id}");

        $response->assertStatus(200)
            ->assertJsonFragment(['id' => $survey->id]);
    }

    public function test_can_get_survey_stats(): void
    {
        Survey::factory()->count(2)->draft()->create(['created_by' => $this->user->id]);
        Survey::factory()->count(3)->published()->create(['created_by' => $this->user->id]);
        Survey::factory()->count(1)->closed()->create(['created_by' => $this->user->id]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/surveys/stats');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'total',
                'draft',
                'published',
                'closed',
            ]);
    }

    // ========================================
    // اختبارات الإنشاء (POST)
    // ========================================

    public function test_can_create_survey(): void
    {
        $surveyData = [
            'title' => 'استبيان جديد',
            'description' => 'وصف الاستبيان',
            'type' => 'initial',
            'is_public' => false,
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/surveys', $surveyData);

        $response->assertStatus(201)
            ->assertJsonFragment(['title' => 'استبيان جديد']);

        $this->assertDatabaseHas('surveys', [
            'title' => 'استبيان جديد',
            'created_by' => $this->user->id,
        ]);
    }

    public function test_create_survey_generates_unique_code(): void
    {
        $response1 = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/surveys', ['title' => 'استبيان 1', 'type' => 'initial']);

        $response2 = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/surveys', ['title' => 'استبيان 2', 'type' => 'initial']);

        $response1->assertStatus(201);
        $response2->assertStatus(201);

        $code1 = $response1->json('data.code');
        $code2 = $response2->json('data.code');

        $this->assertNotEquals($code1, $code2);
    }

    public function test_create_survey_validation(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/surveys', [
                'title' => '', // مطلوب
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['title']);
    }

    // ========================================
    // اختبارات التحديث (PUT)
    // ========================================

    public function test_can_update_draft_survey(): void
    {
        $survey = Survey::factory()->draft()->create(['created_by' => $this->user->id]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson("/api/surveys/{$survey->id}", [
                'title' => 'عنوان محدث',
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('surveys', [
            'id' => $survey->id,
            'title' => 'عنوان محدث',
        ]);
    }

    public function test_cannot_update_published_survey(): void
    {
        $survey = Survey::factory()->published()->create([
            'created_by' => $this->user->id,
            'locked_at' => now(),
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson("/api/surveys/{$survey->id}", [
                'title' => 'عنوان محدث',
            ]);

        // Engine authz: super_admin bypasses. The 422 is a domain rule
        // (UpdateSurveyRequest::withValidator rejects editing a published/locked survey)
        // — see UpdateSurveyRequest::withValidator in app/Modules/Surveys/Http/Requests.
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    // ========================================
    // اختبارات النشر والإغلاق
    // ========================================

    public function test_can_publish_survey_with_fields(): void
    {
        $survey = Survey::factory()->draft()->create(['created_by' => $this->user->id]);
        SurveyField::factory()->count(3)->create(['survey_id' => $survey->id]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/surveys/{$survey->id}/publish");

        $response->assertStatus(200)
            ->assertJsonFragment(['status' => 'published']);

        $this->assertDatabaseHas('surveys', [
            'id' => $survey->id,
            'status' => 'published',
        ]);
    }

    public function test_cannot_publish_survey_without_fields(): void
    {
        $survey = Survey::factory()->draft()->create(['created_by' => $this->user->id]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/surveys/{$survey->id}/publish");

        $response->assertStatus(422);
    }

    public function test_cannot_publish_already_published_survey(): void
    {
        $survey = Survey::factory()->published()->create(['created_by' => $this->user->id]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/surveys/{$survey->id}/publish");

        $response->assertStatus(403);
    }

    public function test_can_close_published_survey(): void
    {
        $survey = Survey::factory()->published()->create(['created_by' => $this->user->id]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/surveys/{$survey->id}/close", [
                'reason' => 'انتهاء فترة الاستبيان',
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('surveys', [
            'id' => $survey->id,
            'status' => 'closed',
        ]);
    }

    // ========================================
    // اختبارات الحذف (DELETE)
    // ========================================

    public function test_can_delete_survey_without_responses(): void
    {
        $survey = Survey::factory()->draft()->create(['created_by' => $this->user->id]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/surveys/{$survey->id}");

        $response->assertStatus(200);
        $this->assertSoftDeleted('surveys', ['id' => $survey->id]);
    }

    public function test_cannot_delete_survey_with_responses(): void
    {
        $survey = Survey::factory()->published()->create(['created_by' => $this->user->id]);
        SurveyResponse::factory()->create(['survey_id' => $survey->id]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/surveys/{$survey->id}");

        // Engine authz: super_admin bypasses SURVEYS_DELETE. The 422 is a domain
        // rule (DestroySurveyRequest::withValidator rejects deleting a survey
        // that already has responses) — see DestroySurveyRequest::withValidator.
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['survey']);
        $this->assertDatabaseHas('surveys', ['id' => $survey->id]);
    }

    // ========================================
    // اختبارات النسخ (Revisions)
    // ========================================

    public function test_can_create_new_revision(): void
    {
        $survey = Survey::factory()->published()->create([
            'created_by' => $this->user->id,
            'organization_id' => $this->user->organization_id,
            'title' => 'استبيان أصلي',
        ]);
        SurveyField::factory()->text()->create([
            'survey_id' => $survey->id,
            'label' => 'حقل منسوخ',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/surveys/{$survey->id}/new-revision");

        $response->assertStatus(201)
            ->assertJsonPath('data.revision', 2)
            ->assertJsonPath('data.canonical_id', $survey->id)
            ->assertJsonPath('data.status', 'draft');

        $newRevisionId = $response->json('data.id');

        $this->assertDatabaseHas('surveys', [
            'id' => $newRevisionId,
            'canonical_id' => $survey->id,
            'revision' => 2,
            'status' => 'draft',
            'code' => $survey->code,
        ]);
        $this->assertDatabaseHas('survey_fields', [
            'survey_id' => $newRevisionId,
            'label' => 'حقل منسوخ',
        ]);
    }

    public function test_can_list_survey_revisions(): void
    {
        $survey = Survey::factory()->published()->create([
            'created_by' => $this->user->id,
            'organization_id' => $this->user->organization_id,
        ]);
        $revision = $survey->createNewRevision();

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/surveys/{$survey->id}/revisions");

        $response->assertStatus(200)
            ->assertJsonCount(2)
            ->assertJsonPath('0.id', $survey->id)
            ->assertJsonPath('0.revision', 1)
            ->assertJsonPath('1.id', $revision->id)
            ->assertJsonPath('1.revision', 2);
    }

    // ========================================
    // اختبارات التحليلات
    // ========================================

    public function test_can_get_survey_analytics(): void
    {
        $survey = Survey::factory()->published()->create(['created_by' => $this->user->id]);
        SurveyField::factory()->count(5)->create(['survey_id' => $survey->id]);
        SurveyResponse::factory()->count(10)->create([
            'survey_id' => $survey->id,
            'status' => 'submitted',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/surveys/{$survey->id}/analytics");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'total_responses',
                'submitted',
                'fields_count',
            ]);
    }

    // ========================================
    // اختبارات الأمان
    // ========================================

    public function test_unauthenticated_cannot_access_surveys(): void
    {
        $response = $this->getJson('/api/surveys');
        $response->assertStatus(401);
    }

    public function test_unauthenticated_cannot_create_survey(): void
    {
        $response = $this->postJson('/api/surveys', [
            'title' => 'اختبار',
        ]);
        $response->assertStatus(401);
    }

    public function test_cannot_access_other_organization_survey(): void
    {
        // إنشاء منظمة أخرى
        $otherOrganization = Organization::factory()->create();

        // إنشاء مستخدم من منظمة مختلفة
        $otherUser = User::factory()->create([
            'organization_id' => $otherOrganization->id,
            'is_active' => true,
        ]);

        $survey = Survey::factory()->create([
            'organization_id' => $this->user->organization_id ?? 1,
            'created_by' => $this->user->id,
        ]);

        $response = $this->actingAs($otherUser, 'sanctum')
            ->getJson("/api/surveys/{$survey->id}");

        $response->assertStatus(403);
    }
}
