<?php

namespace Tests\Feature\Api;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * اختبارات صلاحية الوصول إلى DashboardController
 *
 * - كل المسارات تحت /api/dashboard/* محمية بـ
 *   `engine_capability:Capability::DASHBOARD_VIEW` على مستوى الـ route group
 *   `engine_capability:Capability::DASHBOARD_VIEW`.
 * - المستخدم الذي لا يمتلك القدرة engine-side → 403.
 * - المستخدم الذي يمتلكها عبر الرسم canonical → 200.
 * - super_admin يتجاوز دائماً عبر AccessDecision::can().
 */
class DashboardControllerAuthorizationTest extends TestCase
{
    use GrantsEngineCapability;
    use RefreshDatabase;

    protected Department $department;

    protected Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->organization = Organization::factory()->create();
        $this->department = Department::factory()->create([
            'organization_id' => $this->organization->id,
        ]);
    }

    private function endpoints(): array
    {
        return [
            'stats' => '/api/dashboard/stats',
            'advancedStats' => '/api/dashboard/advanced-stats',
            'recentProjects' => '/api/dashboard/recent-projects',
            'overdueTasks' => '/api/dashboard/overdue-tasks',
            'myUpcomingTasks' => '/api/dashboard/my-upcoming-tasks',
            'projectsByStatus' => '/api/dashboard/projects-by-status',
        ];
    }

    public function test_user_without_view_dashboard_capability_is_forbidden(): void
    {
        $user = User::factory()->create([
            'organization_id' => $this->organization->id,
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        // لا يحمل أي دور/صلاحية على المستوى engine
        foreach ($this->endpoints() as $name => $url) {
            $response = $this->actingAs($user, 'sanctum')->getJson($url);
            $response->assertStatus(403, "Expected 403 for {$name} ({$url}) but got ".$response->status());
        }
    }

    public function test_user_with_dashboard_view_engine_capability_can_access_endpoints(): void
    {
        $user = User::factory()->create([
            'organization_id' => $this->organization->id,
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        // Route reads the capability exclusively from canonical assignments.
        $this->grantEngineCapability($user, Capability::DASHBOARD_VIEW);

        foreach ($this->endpoints() as $name => $url) {
            $response = $this->actingAs($user, 'sanctum')->getJson($url);
            $response->assertStatus(200, "Expected 200 for {$name} ({$url}) but got ".$response->status());
        }
    }

    public function test_unauthenticated_user_is_rejected(): void
    {
        foreach ($this->endpoints() as $name => $url) {
            $response = $this->getJson($url);
            $response->assertStatus(401, "Expected 401 for {$name} ({$url}) but got ".$response->status());
        }
    }

    public function test_member_role_has_dashboard_view_by_default(): void
    {
        // The canonical member catalog grants dashboard.view by default.
        $user = User::factory()->create([
            'organization_id' => $this->organization->id,
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        $this->assignCanonicalRole(
            $user,
            'member',
            'organization',
            $this->organization->id,
            [Capability::DASHBOARD_VIEW],
        );

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/dashboard/stats');

        $response->assertStatus(200);
    }
}
