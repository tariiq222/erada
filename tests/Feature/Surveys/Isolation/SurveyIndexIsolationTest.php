<?php

namespace Tests\Feature\Surveys\Isolation;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Surveys\Models\Survey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * SurveyIndexIsolationTest - Phase 6: org-A user cannot see org-B surveys in the index.
 *
 * GET /api/surveys is gated by engine_capability:Capability::SURVEYS_VIEW. The
 * SurveyController::index applies a forOrganization($actor->organization_id)
 * floor for non-super users (and a 403 fail-closed for null-org actors).
 *
 * Mirrors MeetingIndexIsolationTest exactly.
 */
class SurveyIndexIsolationTest extends TestCase
{
    use GrantsEngineCapability;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function makeSurveyIn(Organization $org, string $title): Survey
    {
        return Survey::factory()->create([
            'organization_id' => $org->id,
            'title' => $title,
            'created_by' => User::factory()->create(['organization_id' => $org->id])->id,
        ]);
    }

    public function test_org_a_user_only_sees_org_a_surveys(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);

        $actor = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
        ]);
        $this->grantEngineCapability($actor, Capability::SURVEYS_VIEW);

        $this->makeSurveyIn($orgA, 'Org A Survey 1');
        $this->makeSurveyIn($orgA, 'Org A Survey 2');
        // Org B surveys must not appear.
        $b1 = $this->makeSurveyIn($orgB, 'Org B Survey EXCLUSIVE 1');
        $b2 = $this->makeSurveyIn($orgB, 'Org B Survey EXCLUSIVE 2');
        $b3 = $this->makeSurveyIn($orgB, 'Org B Survey EXCLUSIVE 3');

        $response = $this->actingAs($actor, 'sanctum')
            ->getJson('/api/surveys');

        $response->assertStatus(200);
        $body = $response->json();
        $total = $body['total'] ?? ($body['meta']['total'] ?? null);
        $data = $body['data'] ?? [];
        $this->assertSame(2, $total, 'expected exactly 2 org-A surveys in the response');
        foreach ($data as $row) {
            // SurveyResource does NOT expose organization_id (deliberately — the
            // public/private surveys surface excludes it). We verify isolation by
            // reaching into the DB and asserting each row's parent survey has
            // org A, AND that none of the row titles contain "EXCLUSIVE" (the
            // sentinel that only appears in org B rows).
            $surveyOrg = Survey::find($row['id'])?->organization_id;
            $this->assertSame($orgA->id, $surveyOrg);
            $this->assertStringNotContainsString('EXCLUSIVE', $row['title']);
        }
        // Sanity check: the org B rows exist in the DB so the floor is doing
        // the isolation (not a missing-data accident).
        $this->assertDatabaseHas('surveys', ['id' => $b1->id]);
        $this->assertDatabaseHas('surveys', ['id' => $b3->id]);
    }

    public function test_super_admin_sees_all_surveys(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);

        $superAdmin = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
        ]);
        $superAdmin->assignRole('super_admin');

        $this->makeSurveyIn($orgA, 'A-1');
        $this->makeSurveyIn($orgA, 'A-2');
        $this->makeSurveyIn($orgB, 'B-1');
        $this->makeSurveyIn($orgB, 'B-2');
        $this->makeSurveyIn($orgB, 'B-3');

        $response = $this->actingAs($superAdmin, 'sanctum')
            ->getJson('/api/surveys');

        $response->assertStatus(200);
        $body = $response->json();
        $total = $body['total'] ?? ($body['meta']['total'] ?? null);
        $this->assertSame(5, $total);
    }

    public function test_null_org_user_is_denied_at_form_request(): void
    {
        $orgA = Organization::factory()->create();
        $this->makeSurveyIn($orgA, 'A-1');

        $actor = User::factory()->create([
            'organization_id' => null,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($actor, Capability::SURVEYS_VIEW);

        $response = $this->actingAs($actor, 'sanctum')
            ->getJson('/api/surveys');

        // SurveyController::index aborts 403 when ! super_admin && null org.
        $response->assertStatus(403);
    }

    public function test_user_without_view_capability_cannot_list_surveys(): void
    {
        $orgA = Organization::factory()->create();
        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);

        $actor = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
        ]);

        // Engine SURVEYS_VIEW not granted → 403.
        $response = $this->actingAs($actor, 'sanctum')
            ->getJson('/api/surveys');

        $response->assertStatus(403);
    }
}
