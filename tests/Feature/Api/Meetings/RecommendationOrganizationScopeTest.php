<?php

namespace Tests\Feature\Api\Meetings;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Meetings\Models\Recommendation;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecommendationOrganizationScopeTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $orgA;

    protected Organization $orgB;

    protected User $userA;

    protected User $userB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $deptA = Department::factory()->create();
        $deptB = Department::factory()->create();
        $this->orgA = Organization::factory()->create();
        $this->orgB = Organization::factory()->create();

        $this->userA = User::factory()->create(['department_id' => $deptA->id, 'organization_id' => $this->orgA->id, 'is_active' => true]);
        $this->userA->assignRole('admin');
        $this->userB = User::factory()->create(['department_id' => $deptB->id, 'organization_id' => $this->orgB->id, 'is_active' => true]);
        $this->userB->assignRole('admin');
    }

    public function test_user_cannot_view_recommendation_in_other_organization(): void
    {
        $r = Recommendation::factory()->create(['organization_id' => $this->orgA->id]);
        $response = $this->actingAs($this->userB, 'sanctum')->getJson("/api/recommendations/{$r->id}");
        $response->assertStatus(403);
    }
}
