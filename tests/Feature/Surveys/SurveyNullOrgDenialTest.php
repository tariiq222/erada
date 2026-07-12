<?php

namespace Tests\Feature\Surveys;

use App\Modules\Core\Authorization\Models\AuthorizationRoleAssignment;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Surveys\Models\Survey;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SurveyNullOrgDenialTest extends TestCase
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

    private function makeUser(?Organization $org, ?string $role = null): User
    {
        $user = User::factory()->create([
            'organization_id' => $org?->id,
            'is_active' => true,
        ]);

        if ($role === 'super_admin') {
            $this->grantCanonicalSuperAdmin($user);
        } elseif ($role === 'admin') {
            $this->grantCanonicalAdmin(
                $user,
                $org === null ? AuthorizationRoleAssignment::SCOPE_ALL : AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
                $org?->id,
            );
        }

        return $user;
    }

    public function test_null_org_non_super_user_is_forbidden_from_surveys_index(): void
    {
        // Updated contract (was: 200 with empty data — silent fail-open). The
        // null-org floor added in this commit now denies non-super null-org
        // users at the controller entry, so we assert 403 instead of an empty
        // list.
        $nullOrgUser = $this->makeUser(null, 'admin');
        Survey::factory()->create(['organization_id' => $this->orgA->id]);
        Survey::factory()->create(['organization_id' => $this->orgB->id]);

        $this->actingAs($nullOrgUser, 'sanctum')
            ->getJson('/api/surveys')
            ->assertStatus(403);
    }

    public function test_org_a_user_does_not_see_org_b_surveys_in_index(): void
    {
        $orgAUser = $this->makeUser($this->orgA, 'admin');
        $surveyA = Survey::factory()->create(['organization_id' => $this->orgA->id]);
        Survey::factory()->create(['organization_id' => $this->orgB->id]);

        $this->actingAs($orgAUser, 'sanctum')
            ->getJson('/api/surveys')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $surveyA->id);
    }

    public function test_super_admin_sees_surveys_across_organizations_in_index(): void
    {
        $superAdmin = $this->makeUser(null, 'super_admin');
        Survey::factory()->create(['organization_id' => $this->orgA->id]);
        Survey::factory()->create(['organization_id' => $this->orgB->id]);

        $this->actingAs($superAdmin, 'sanctum')
            ->getJson('/api/surveys')
            ->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    public function test_authorizes_survey_guard_denies_null_org_non_super_on_null_org_survey(): void
    {
        $nullOrgUser = $this->makeUser(null, 'admin');
        $nullSurvey = Survey::factory()->create(['organization_id' => null]);

        $this->actingAs($nullOrgUser, 'sanctum')
            ->getJson("/api/surveys/{$nullSurvey->id}/sections")
            ->assertStatus(403);
    }

    public function test_null_org_user_is_forbidden_from_creating_a_survey(): void
    {
        // A non-super user with no organization_id must not be able to default
        // organization_id to null on POST /api/surveys — that would create an
        // org-less tenant-less survey (fail-open data leak).
        $nullOrgUser = $this->makeUser(null, 'admin');

        $this->actingAs($nullOrgUser, 'sanctum')
            ->postJson('/api/surveys', [
                'title' => 'Null-org Survey',
                'type' => 'initial',
            ])
            ->assertStatus(403);

        $this->assertDatabaseMissing('surveys', ['title' => 'Null-org Survey']);
    }

    public function test_null_org_stats_is_forbidden_for_non_super(): void
    {
        // Updated contract (was: 200 with total=0 — silent fail-open). The
        // null-org floor added in this commit now denies non-super null-org
        // users at the controller entry, so we assert 403 instead.
        $nullOrgUser = $this->makeUser(null, 'admin');
        Survey::factory()->create(['organization_id' => $this->orgA->id]);
        Survey::factory()->create(['organization_id' => $this->orgB->id]);

        $this->actingAs($nullOrgUser, 'sanctum')
            ->getJson('/api/surveys/stats')
            ->assertStatus(403);
    }
}
