<?php

namespace Tests\Feature\Api;

use App\Modules\Core\Authorization\Capability;
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
 *   (Phase 8-C). كانت `can:view_dashboard` (Spatie) قبل ذلك.
 * - المستخدم الذي لا يمتلك القدرة engine-side → 403.
 * - المستخدم الذي يمتلكها عبر scoped_role_definitions.permissions[] → 200.
 * - super_admin يتجاوز دائماً عبر AccessDecision::can().
 */
class DashboardControllerAuthorizationTest extends TestCase
{
    use GrantsEngineCapability;
    use RefreshDatabase;

    protected Department $department;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->department = Department::factory()->create();
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
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        // Phase 8-C: route reads engine capability (scoped_role_definitions.permissions[]).
        // Use the engine mechanism — givePermissionTo() to the Spatie 'view_dashboard'
        // string no longer grants the route because EnsureEngineCapability delegates
        // to AccessDecision::can, which ignores Spatie's model_has_permissions.
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
        // The seeder's seedLegacyTestRoles() (test env only) provisions a
        // scoped_role_definitions row for the legacy 'member' Spatie role
        // whose permissions[] carries every view/view_all/view_reports
        // Capability::all() entry — `Capability::DASHBOARD_VIEW` is one of
        // them (action='view'), so a user with the member role is granted
        // the engine capability automatically.
        $user = User::factory()->create([
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        $user->assignRole('member');

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/dashboard/stats');

        $response->assertStatus(200);
    }
}
