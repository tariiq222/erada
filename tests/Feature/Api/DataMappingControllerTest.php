<?php

namespace Tests\Feature\Api;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Surveys\Models\DataMappingTemplate;
use App\Modules\Surveys\Models\Survey;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Authorization tests for DataMappingController.
 *
 * Authorization is enforced in two layers:
 *   1. Route middleware `permission:edit_surveys` (store/update/destroy)
 *   2. AuthorizesSurveyAccess trait (org isolation — aborts 403 when orgs differ or null)
 *
 * The index route requires `permission:view_surveys` (via prefix middleware).
 *
 * Cross-org: user from org B → 403 (authorizeSurvey aborts)
 * No permission: viewer (no view_surveys/edit_surveys) → 403 from middleware
 * Same-org admin: → 2xx
 * super_admin: → 2xx (authorizeSurvey has explicit super_admin bypass)
 */
class DataMappingControllerTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Department $dept;

    private Survey $survey;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeaders(['X-Skip-Csrf' => '1']);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->org = Organization::factory()->create();
        $this->dept = Department::factory()->create(['organization_id' => $this->org->id]);
        $this->survey = Survey::factory()->create([
            'organization_id' => $this->org->id,
        ]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeUser(string $role, ?int $orgId = null): User
    {
        $user = User::factory()->create([
            'organization_id' => $orgId ?? $this->org->id,
            'department_id' => $this->dept->id,
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function validStorePayload(): array
    {
        return [
            'name' => 'Test Mapping Template',
            'description' => 'A test mapping',
            'target_model' => 'departments',
            'mappings' => [
                ['column' => 'name', 'required' => true],
            ],
            'insert_policy' => 'upsert',
            'conflict_policy' => 'skip',
            'is_active' => true,
        ];
    }

    // -------------------------------------------------------------------------
    // Unauthenticated → 401
    // -------------------------------------------------------------------------

    public function test_unauthenticated_cannot_list_mappings(): void
    {
        $response = $this->getJson("/api/surveys/{$this->survey->id}/mappings");

        $response->assertUnauthorized();
    }

    public function test_unauthenticated_cannot_store_mapping(): void
    {
        $response = $this->postJson("/api/surveys/{$this->survey->id}/mappings", $this->validStorePayload());

        $response->assertUnauthorized();
    }

    // -------------------------------------------------------------------------
    // Viewer (no view_surveys / edit_surveys permission) → 403 from middleware
    // -------------------------------------------------------------------------

    public function test_viewer_without_survey_permission_gets_403_on_index(): void
    {
        $viewer = $this->makeUser('viewer');

        $response = $this->actingAs($viewer, 'sanctum')
            ->getJson("/api/surveys/{$this->survey->id}/mappings");

        // Route prefix requires permission:view_surveys which viewer does not have
        $response->assertForbidden();
    }

    public function test_viewer_without_survey_permission_gets_403_on_store(): void
    {
        $viewer = $this->makeUser('viewer');

        $response = $this->actingAs($viewer, 'sanctum')
            ->postJson("/api/surveys/{$this->survey->id}/mappings", $this->validStorePayload());

        $response->assertForbidden();
    }

    // -------------------------------------------------------------------------
    // Cross-org isolation: user from org B → 403 (authorizeSurvey)
    // -------------------------------------------------------------------------

    public function test_cross_org_admin_gets_403_on_index(): void
    {
        $orgB = Organization::factory()->create();
        $outsider = $this->makeUser('admin', $orgB->id);

        $response = $this->actingAs($outsider, 'sanctum')
            ->getJson("/api/surveys/{$this->survey->id}/mappings");

        $response->assertForbidden();
    }

    public function test_cross_org_admin_gets_403_on_store(): void
    {
        $orgB = Organization::factory()->create();
        $outsider = $this->makeUser('admin', $orgB->id);

        $response = $this->actingAs($outsider, 'sanctum')
            ->postJson("/api/surveys/{$this->survey->id}/mappings", $this->validStorePayload());

        $response->assertForbidden();
    }

    public function test_cross_org_admin_gets_403_on_update(): void
    {
        $template = DataMappingTemplate::factory()->create([
            'survey_id' => $this->survey->id,
        ]);

        $orgB = Organization::factory()->create();
        $outsider = $this->makeUser('admin', $orgB->id);

        $response = $this->actingAs($outsider, 'sanctum')
            ->putJson("/api/surveys/{$this->survey->id}/mappings/{$template->id}", ['name' => 'Changed']);

        $response->assertForbidden();
    }

    public function test_cross_org_admin_gets_403_on_destroy(): void
    {
        $template = DataMappingTemplate::factory()->create([
            'survey_id' => $this->survey->id,
        ]);

        $orgB = Organization::factory()->create();
        $outsider = $this->makeUser('admin', $orgB->id);

        $response = $this->actingAs($outsider, 'sanctum')
            ->deleteJson("/api/surveys/{$this->survey->id}/mappings/{$template->id}");

        $response->assertForbidden();
    }

    // -------------------------------------------------------------------------
    // Template belongs to a different survey → 404 from controller guard
    // -------------------------------------------------------------------------

    public function test_update_with_mismatched_survey_returns_404(): void
    {
        $otherSurvey = Survey::factory()->create(['organization_id' => $this->org->id]);
        $template = DataMappingTemplate::factory()->create(['survey_id' => $otherSurvey->id]);

        $admin = $this->makeUser('admin');

        $response = $this->actingAs($admin, 'sanctum')
            ->putJson("/api/surveys/{$this->survey->id}/mappings/{$template->id}", ['name' => 'Changed']);

        $response->assertNotFound();
    }

    public function test_destroy_with_mismatched_survey_returns_404(): void
    {
        $otherSurvey = Survey::factory()->create(['organization_id' => $this->org->id]);
        $template = DataMappingTemplate::factory()->create(['survey_id' => $otherSurvey->id]);

        $admin = $this->makeUser('admin');

        $response = $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/surveys/{$this->survey->id}/mappings/{$template->id}");

        $response->assertNotFound();
    }

    // -------------------------------------------------------------------------
    // Same-org admin (has view_surveys + edit_surveys) → success
    // -------------------------------------------------------------------------

    public function test_same_org_admin_can_list_mappings(): void
    {
        $this->withoutExceptionHandling();
        DataMappingTemplate::factory()->count(2)->create(['survey_id' => $this->survey->id]);
        $admin = $this->makeUser('admin');

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/surveys/{$this->survey->id}/mappings");

        $response->assertOk()
            ->assertJsonStructure(['data']);
    }

    public function test_same_org_admin_can_store_mapping(): void
    {
        $admin = $this->makeUser('admin');

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/surveys/{$this->survey->id}/mappings", $this->validStorePayload());

        $response->assertCreated();
        $this->assertDatabaseHas('data_mapping_templates', [
            'survey_id' => $this->survey->id,
            'name' => 'Test Mapping Template',
        ]);
    }

    public function test_same_org_admin_can_update_mapping(): void
    {
        $template = DataMappingTemplate::factory()->create(['survey_id' => $this->survey->id]);
        $admin = $this->makeUser('admin');

        $response = $this->actingAs($admin, 'sanctum')
            ->putJson("/api/surveys/{$this->survey->id}/mappings/{$template->id}", [
                'name' => 'Updated Name',
            ]);

        $response->assertOk();
        $this->assertDatabaseHas('data_mapping_templates', [
            'id' => $template->id,
            'name' => 'Updated Name',
        ]);
    }

    public function test_same_org_admin_can_destroy_mapping(): void
    {
        $template = DataMappingTemplate::factory()->create(['survey_id' => $this->survey->id]);
        $admin = $this->makeUser('admin');

        $response = $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/surveys/{$this->survey->id}/mappings/{$template->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('data_mapping_templates', ['id' => $template->id]);
    }

    // -------------------------------------------------------------------------
    // super_admin bypasses org isolation
    // -------------------------------------------------------------------------

    public function test_super_admin_can_access_any_org_survey_mappings(): void
    {
        $orgB = Organization::factory()->create();
        $sa = User::factory()->create([
            'organization_id' => $orgB->id,
            'is_active' => true,
        ]);
        $sa->assignRole('super_admin');

        // super_admin from org B accessing org A survey
        $response = $this->actingAs($sa, 'sanctum')
            ->getJson("/api/surveys/{$this->survey->id}/mappings");

        $response->assertOk();
    }

    public function test_super_admin_can_store_mapping_for_any_org_survey(): void
    {
        $orgB = Organization::factory()->create();
        $sa = User::factory()->create([
            'organization_id' => $orgB->id,
            'is_active' => true,
        ]);
        $sa->assignRole('super_admin');

        $response = $this->actingAs($sa, 'sanctum')
            ->postJson("/api/surveys/{$this->survey->id}/mappings", $this->validStorePayload());

        $response->assertCreated();
    }

    // -------------------------------------------------------------------------
    // GET /api/surveys/mapping-targets — schema disclosure (A11)
    //
    // The endpoint exposes the list of available target models (departments,
    // users) and their column metadata, so the engine_capability:SURVEYS_VIEW
    // prefix middleware is the only authz gate. We assert the four critical
    // cases: unauthenticated → 401, viewer without engine SURVEYS_VIEW → 403,
    // happy path returns the schema, and cross-org actor still passes (the
    // endpoint is intentionally global; the per-survey isolation is on
    // /surveys/{survey}/mappings/*).
    // -------------------------------------------------------------------------

    public function test_unauthenticated_cannot_list_mapping_targets(): void
    {
        $response = $this->getJson('/api/surveys/mapping-targets');

        $response->assertUnauthorized();
    }

    public function test_viewer_without_survey_engine_capability_gets_403_on_mapping_targets(): void
    {
        // A user with NO roles and NO engine capabilities: the engine_capability
        // middleware on the /surveys prefix must reject with 403.
        $user = User::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/surveys/mapping-targets');

        $response->assertForbidden();
    }

    public function test_same_org_admin_can_list_mapping_targets(): void
    {
        $admin = $this->makeUser('admin');

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/surveys/mapping-targets');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'departments' => ['label', 'model', 'columns'],
                    'users' => ['label', 'model', 'columns'],
                ],
            ]);
    }

    public function test_super_admin_can_list_mapping_targets(): void
    {
        $sa = User::factory()->create([
            'organization_id' => $this->org->id,
            'is_active' => true,
        ]);
        $sa->assignRole('super_admin');

        $response = $this->actingAs($sa, 'sanctum')
            ->getJson('/api/surveys/mapping-targets');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => ['departments', 'users'],
            ]);
    }

    public function test_mapping_targets_does_not_leak_sensitive_columns(): void
    {
        // Schema-disclosure regression guard: the public schema must not expose
        // encrypted or hashed columns (e.g. password, remember_token, two-factor
        // recovery codes) on the user model. Assert those column names are absent.
        $admin = $this->makeUser('admin');

        $body = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/surveys/mapping-targets')
            ->assertOk()
            ->json();

        $usersColumns = array_keys($body['data']['users']['columns'] ?? []);
        $this->assertNotContains('password', $usersColumns, 'password must not be exposed via mapping-targets');
        $this->assertNotContains('remember_token', $usersColumns, 'remember_token must not be exposed via mapping-targets');
        $this->assertNotContains('two_factor_secret', $usersColumns, '2FA secret must not be exposed via mapping-targets');
        $this->assertNotContains('two_factor_recovery_codes', $usersColumns, '2FA recovery codes must not be exposed via mapping-targets');
    }
}
