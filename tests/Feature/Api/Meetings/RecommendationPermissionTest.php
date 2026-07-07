<?php

namespace Tests\Feature\Api\Meetings;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

class RecommendationPermissionTest extends TestCase
{
    use GrantsEngineCapability;
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $dept = Department::factory()->create();
        $this->user = User::factory()->create([
            'department_id' => $dept->id,
            'organization_id' => $dept->organization_id,
            'is_active' => true,
        ]);
        $this->user->assignRole('viewer');
    }

    public function test_viewer_can_list_recommendations_with_view_meetings_perm(): void
    {
        // The recommendations index gates on RECOMMENDATIONS_VIEW (see
        // RecommendationController::canListRecommendations + Recommendation::scopeVisibleTo).
        // Phase 3 (ADR-UNIFIED-ROLE-ACCESS) removed the legacy can_* flag path that
        // used to let a single meetings.view grant leak into every view capability
        // across modules — so the capability that actually opens this endpoint must
        // be granted explicitly.
        $this->grantEngineCapability($this->user, Capability::RECOMMENDATIONS_VIEW);
        $response = $this->actingAs($this->user, 'sanctum')->getJson('/api/recommendations');
        $response->assertStatus(200);
    }

    public function test_viewer_cannot_create_recommendation(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/recommendations', [
            'decision_id' => 1, 'title' => 'x', 'priority' => 'medium',
        ]);
        $response->assertStatus(403);
    }
}
