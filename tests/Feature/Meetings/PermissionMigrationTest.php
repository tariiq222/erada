<?php

namespace Tests\Feature\Meetings;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use Database\Seeders\Meetings\MeetingsPermissionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PermissionMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_with_old_view_strategy_cannot_create_decision(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(MeetingsPermissionsSeeder::class);
        $department = Department::factory()->create();
        $user = User::factory()->create(['department_id' => $department->id, 'is_active' => true]);
        $user->givePermissionTo('view_strategy'); // legacy permission only.
        $project = Project::factory()->create(['department_id' => $department->id]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/decisions', [
            'title' => 'اختبار',
            'decidable_type' => 'project',
            'decidable_id' => $project->id,
            'type' => 'approval',
            'decision_date' => now()->toDateString(),
        ]);

        $response->assertStatus(403);
    }

    public function test_user_with_new_record_decisions_can_create_decision(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(MeetingsPermissionsSeeder::class);
        $organization = Organization::factory()->create();
        // Department must share the same org so ProjectObserver does not reroute
        // project.organization_id to a different org (which triggers assertSameOrganization 403).
        $department = Department::factory()->create(['organization_id' => $organization->id]);
        $user = User::factory()->create([
            'department_id' => $department->id,
            'organization_id' => $organization->id,
            'is_active' => true,
        ]);
        // Post-engine-cutover: flat Spatie permissions are NOT bridged by AccessDecision::can().
        // Grant via the 'admin' Spatie role — the engine maps 'admin' to the org-scoped
        // ScopedRoleDefinition with is_admin_role=true, which covers MEETINGS_CREATE.
        $user->assignRole('admin');
        AccessDecision::flushUserCache($user->id);
        $project = Project::factory()->create([
            'department_id' => $department->id,
            'organization_id' => $organization->id,
        ]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/decisions', [
            'title' => 'اختبار',
            'decidable_type' => 'project',
            'decidable_id' => $project->id,
            'type' => 'approval',
            'decision_date' => now()->toDateString(),
        ]);

        $response->assertStatus(201);
    }
}
