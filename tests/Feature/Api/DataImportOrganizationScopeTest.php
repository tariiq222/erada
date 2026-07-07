<?php

namespace Tests\Feature\Api;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Surveys\Models\DataImportRequest;
use App\Modules\Surveys\Models\DataMappingTemplate;
use App\Modules\Surveys\Models\Survey;
use App\Modules\Surveys\Models\SurveyResponse;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * عزل المؤسسات + صلاحية المراجعة على تدفّق استيراد البيانات — P0-12 / R3.
 *
 * يثبت:
 * - SC1 (D-01/D-02/D-03): admin في مؤسسة A لا يصل لطلب استيراد مؤسسة B (403)؛
 *   قائمة admin لا تحتوي معرّفات مؤسسة B (تسريب صامت)؛ bulk بمعرّفات B => عدد 0؛
 *   مستخدم بلا مؤسسة (غير super) => قائمة فارغة + 403 على العمليات؛
 *   super_admin بلا مؤسسة => لا يُحجب (200).
 * - SC2 (D-04): مستخدم بلا review_data_imports (member/no-role) => 403 على
 *   approve/reject/apply/retry/bulk-approve/bulk-reject؛ admin (يملكها) => نجاح؛
 *   index/show تبقى متاحة بدونها لمستخدم محصور بمؤسسته.
 *
 * ملاحظة: الفاعل عبر المؤسسات هو `admin` (يملك review_data_imports لكنه ليس super_admin)
 * حتى يصل الطلب إلى الحارس بدل أن يحجبه permission middleware (landmine #2).
 */
class DataImportOrganizationScopeTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $orgA;

    protected Organization $orgB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->orgA = Organization::factory()->create();
        $this->orgB = Organization::factory()->create();
    }

    /**
     * @param  Organization|null  $org  مرّر null لحالة null-org
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

    /**
     * يبني سلسلة المؤسسة (استبيان->إجابة->قالب->طلب) لأن الـ factory المتداخل
     * يضبط organization_id إلى null افتراضياً.
     */
    private function importRequestForOrg(Organization $org, string $status = 'pending'): DataImportRequest
    {
        $survey = Survey::factory()->create(['organization_id' => $org->id]);
        $response = SurveyResponse::factory()->create(['survey_id' => $survey->id]);
        $template = DataMappingTemplate::factory()->create(['survey_id' => $survey->id]);

        return DataImportRequest::factory()->{$status}()->create([
            'response_id' => $response->id,
            'template_id' => $template->id,
        ]);
    }

    // ===========================================================
    // SC1 — Cross-org isolation (org-A admin on org-B request)
    // ===========================================================

    public function test_cross_org_admin_cannot_show_other_org_request(): void
    {
        $adminA = $this->makeUser($this->orgA, 'admin');
        $requestB = $this->importRequestForOrg($this->orgB);

        $this->actingAs($adminA, 'sanctum')
            ->getJson("/api/data-imports/{$requestB->id}")
            ->assertStatus(403);
    }

    public function test_cross_org_admin_cannot_approve_other_org_request(): void
    {
        $adminA = $this->makeUser($this->orgA, 'admin');
        $requestB = $this->importRequestForOrg($this->orgB);

        $this->actingAs($adminA, 'sanctum')
            ->postJson("/api/data-imports/{$requestB->id}/approve")
            ->assertStatus(403);
    }

    public function test_cross_org_admin_cannot_reject_other_org_request(): void
    {
        $adminA = $this->makeUser($this->orgA, 'admin');
        $requestB = $this->importRequestForOrg($this->orgB);

        $this->actingAs($adminA, 'sanctum')
            ->postJson("/api/data-imports/{$requestB->id}/reject", ['reason' => 'سبب'])
            ->assertStatus(403);
    }

    public function test_cross_org_admin_cannot_apply_other_org_request(): void
    {
        $adminA = $this->makeUser($this->orgA, 'admin');
        $requestB = $this->importRequestForOrg($this->orgB, 'approved');

        $this->actingAs($adminA, 'sanctum')
            ->postJson("/api/data-imports/{$requestB->id}/apply")
            ->assertStatus(403);
    }

    public function test_cross_org_admin_cannot_retry_other_org_request(): void
    {
        $adminA = $this->makeUser($this->orgA, 'admin');
        $requestB = $this->importRequestForOrg($this->orgB, 'failed');

        $this->actingAs($adminA, 'sanctum')
            ->postJson("/api/data-imports/{$requestB->id}/retry")
            ->assertStatus(403);
    }

    public function test_index_does_not_leak_other_org_request_ids(): void
    {
        $adminA = $this->makeUser($this->orgA, 'admin');
        $requestA = $this->importRequestForOrg($this->orgA);
        $requestB = $this->importRequestForOrg($this->orgB);

        $response = $this->actingAs($adminA, 'sanctum')
            ->getJson('/api/data-imports')
            ->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($requestA->id, $ids, 'يجب أن تحتوي القائمة على طلب مؤسسة الفاعل');
        $this->assertNotContains($requestB->id, $ids, 'يجب ألا تتسرّب معرّفات طلبات مؤسسة أخرى (D-02)');
    }

    public function test_bulk_approve_with_other_org_ids_approves_zero(): void
    {
        $adminA = $this->makeUser($this->orgA, 'admin');
        $requestB = $this->importRequestForOrg($this->orgB);

        $this->actingAs($adminA, 'sanctum')
            ->postJson('/api/data-imports/bulk-approve', ['ids' => [$requestB->id]])
            ->assertStatus(200)
            ->assertJson(['approved' => 0]);

        $this->assertSame('pending', $requestB->fresh()->status->value);
    }

    public function test_bulk_reject_with_other_org_ids_rejects_zero(): void
    {
        $adminA = $this->makeUser($this->orgA, 'admin');
        $requestB = $this->importRequestForOrg($this->orgB);

        $this->actingAs($adminA, 'sanctum')
            ->postJson('/api/data-imports/bulk-reject', [
                'ids' => [$requestB->id],
                'reason' => 'سبب',
            ])
            ->assertStatus(200)
            ->assertJson(['rejected' => 0]);

        $this->assertSame('pending', $requestB->fresh()->status->value);
    }

    // ===========================================================
    // SC1 — Null-org non-super (the carried D-02 bug)
    // ===========================================================

    public function test_null_org_non_super_gets_empty_index(): void
    {
        $nullOrgAdmin = $this->makeUser(null, 'admin');
        $this->importRequestForOrg($this->orgA);
        $this->importRequestForOrg($this->orgB);

        $response = $this->actingAs($nullOrgAdmin, 'sanctum')
            ->getJson('/api/data-imports')
            ->assertStatus(200);

        $this->assertCount(0, $response->json('data'), 'مستخدم بلا مؤسسة (غير super) يجب أن يرى قائمة فارغة');
    }

    public function test_null_org_non_super_is_denied_on_single_action(): void
    {
        $nullOrgAdmin = $this->makeUser(null, 'admin');
        $requestA = $this->importRequestForOrg($this->orgA);

        $this->actingAs($nullOrgAdmin, 'sanctum')
            ->postJson("/api/data-imports/{$requestA->id}/approve")
            ->assertStatus(403);
    }

    public function test_null_org_super_admin_is_not_blocked(): void
    {
        // يحرس ضد الإصلاح الساذج — super_admin بلا مؤسسة يجب أن يبقى مسموحاً
        $nullOrgSuperAdmin = $this->makeUser(null, 'super_admin');
        $requestA = $this->importRequestForOrg($this->orgA);

        $this->actingAs($nullOrgSuperAdmin, 'sanctum')
            ->getJson("/api/data-imports/{$requestA->id}")
            ->assertStatus(200);

        $this->actingAs($nullOrgSuperAdmin, 'sanctum')
            ->postJson("/api/data-imports/{$requestA->id}/approve")
            ->assertStatus(200);
    }

    // ===========================================================
    // SC1 — DataMapping cross-org (trait reuse, D-03)
    // ===========================================================

    public function test_cross_org_member_cannot_access_other_org_mapping_templates(): void
    {
        $memberA = $this->makeUser($this->orgA, 'member');
        $surveyB = Survey::factory()->create(['organization_id' => $this->orgB->id]);

        $this->actingAs($memberA, 'sanctum')
            ->getJson("/api/surveys/{$surveyB->id}/mappings")
            ->assertStatus(403);
    }

    // ===========================================================
    // SC2 — Reviewer permission (D-04): member/no-role denied
    // ===========================================================

    public function test_member_without_review_permission_denied_on_approve(): void
    {
        $memberA = $this->makeUser($this->orgA, 'member');
        $requestA = $this->importRequestForOrg($this->orgA);

        $this->actingAs($memberA, 'sanctum')
            ->postJson("/api/data-imports/{$requestA->id}/approve")
            ->assertStatus(403);
    }

    public function test_member_without_review_permission_denied_on_reject(): void
    {
        $memberA = $this->makeUser($this->orgA, 'member');
        $requestA = $this->importRequestForOrg($this->orgA);

        $this->actingAs($memberA, 'sanctum')
            ->postJson("/api/data-imports/{$requestA->id}/reject", ['reason' => 'سبب'])
            ->assertStatus(403);
    }

    public function test_member_without_review_permission_denied_on_apply(): void
    {
        $memberA = $this->makeUser($this->orgA, 'member');
        $requestA = $this->importRequestForOrg($this->orgA, 'approved');

        $this->actingAs($memberA, 'sanctum')
            ->postJson("/api/data-imports/{$requestA->id}/apply")
            ->assertStatus(403);
    }

    public function test_member_without_review_permission_denied_on_retry(): void
    {
        $memberA = $this->makeUser($this->orgA, 'member');
        $requestA = $this->importRequestForOrg($this->orgA, 'failed');

        $this->actingAs($memberA, 'sanctum')
            ->postJson("/api/data-imports/{$requestA->id}/retry")
            ->assertStatus(403);
    }

    public function test_member_without_review_permission_denied_on_bulk_approve(): void
    {
        $memberA = $this->makeUser($this->orgA, 'member');
        $requestA = $this->importRequestForOrg($this->orgA);

        $this->actingAs($memberA, 'sanctum')
            ->postJson('/api/data-imports/bulk-approve', ['ids' => [$requestA->id]])
            ->assertStatus(403);
    }

    public function test_member_without_review_permission_denied_on_bulk_reject(): void
    {
        $memberA = $this->makeUser($this->orgA, 'member');
        $requestA = $this->importRequestForOrg($this->orgA);

        $this->actingAs($memberA, 'sanctum')
            ->postJson('/api/data-imports/bulk-reject', [
                'ids' => [$requestA->id],
                'reason' => 'سبب',
            ])
            ->assertStatus(403);
    }

    // ===========================================================
    // SC2 — admin (has permission, same org) succeeds
    // ===========================================================

    public function test_same_org_admin_can_approve_request(): void
    {
        $adminA = $this->makeUser($this->orgA, 'admin');
        $requestA = $this->importRequestForOrg($this->orgA);

        $this->actingAs($adminA, 'sanctum')
            ->postJson("/api/data-imports/{$requestA->id}/approve")
            ->assertStatus(200);

        $this->assertSame('approved', $requestA->fresh()->status->value);
    }

    // ===========================================================
    // SC2 — index/show accessible WITHOUT review_data_imports
    // ===========================================================

    public function test_member_without_review_permission_can_index_own_org(): void
    {
        $memberA = $this->makeUser($this->orgA, 'member');
        $requestA = $this->importRequestForOrg($this->orgA);

        $response = $this->actingAs($memberA, 'sanctum')
            ->getJson('/api/data-imports')
            ->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($requestA->id, $ids, 'index متاح بدون review_data_imports لمستخدم محصور بمؤسسته');
    }

    public function test_member_without_review_permission_can_show_own_org_request(): void
    {
        $memberA = $this->makeUser($this->orgA, 'member');
        $requestA = $this->importRequestForOrg($this->orgA);

        $this->actingAs($memberA, 'sanctum')
            ->getJson("/api/data-imports/{$requestA->id}")
            ->assertStatus(200);
    }
}
