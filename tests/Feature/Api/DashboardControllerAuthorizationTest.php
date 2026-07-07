<?php

namespace Tests\Feature\Api;

use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * اختبارات صلاحية الوصول إلى DashboardController
 *
 * - كل المسارات تحت /api/dashboard/* محمية بصلاحية view_dashboard
 *   عبر $this->middleware('can:view_dashboard') في __construct.
 * - المستخدم الذي لا يمتلك الصلاحية → 403.
 * - المستخدم الذي يمتلكها → 200.
 */
class DashboardControllerAuthorizationTest extends TestCase
{
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

    public function test_user_without_view_dashboard_permission_is_forbidden(): void
    {
        $user = User::factory()->create([
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        // لا يحمل أي دور ولا صلاحية view_dashboard
        $this->assertFalse($user->hasPermissionTo('view_dashboard'));

        foreach ($this->endpoints() as $name => $url) {
            $response = $this->actingAs($user, 'sanctum')->getJson($url);
            $response->assertStatus(403, "Expected 403 for {$name} ({$url}) but got ".$response->status());
        }
    }

    public function test_user_with_view_dashboard_permission_can_access_endpoints(): void
    {
        $user = User::factory()->create([
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        $user->givePermissionTo('view_dashboard');

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

    public function test_member_role_has_view_dashboard_by_default(): void
    {
        $user = User::factory()->create([
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        $user->assignRole('member');

        $this->assertTrue($user->hasPermissionTo('view_dashboard'));

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/dashboard/stats');

        $response->assertStatus(200);
    }
}
