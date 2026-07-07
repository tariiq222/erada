<?php

namespace Tests\Feature\Api\Surveys;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

class SurveysRouteEngineMiddlewareTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    public function test_view_surveys_requires_engine_capability(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $this->grantEngineCapability($user, Capability::SURVEYS_VIEW);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/surveys');
        $response->assertStatus(200);
    }

    public function test_view_surveys_denies_without_engine_capability(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/surveys');
        $response->assertStatus(403);
    }

    public function test_create_survey_requires_engine_capability(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        // Group-level SURVEYS_VIEW gate fires first; grant both so the
        // CREATE-specific middleware is the one actually being tested.
        $this->grantEngineCapability($user, [Capability::SURVEYS_VIEW, Capability::SURVEYS_CREATE]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/surveys', ['name' => 'test']);
        // 422 (validation) is acceptable; 403 means engine gate denied
        $this->assertNotEquals(403, $response->status());
    }
}
