<?php

namespace Tests\Feature\Projects;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Projects\Models\Project;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class UpdateMemberRoleRouteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_put_members_user_route_is_registered(): void
    {
        $routes = collect(Route::getRoutes())
            ->map(fn ($r) => strtoupper($r->methods()[0]).' '.$r->uri());

        $this->assertTrue(
            $routes->contains(fn ($r) => str_contains($r, 'PUT')
                && str_contains($r, 'projects/{project}/members/{user}')),
            'Expected PUT route for /projects/{project}/members/{user} to be registered.'
        );
    }

    public function test_put_roles_user_route_is_registered_as_alias(): void
    {
        $routes = collect(Route::getRoutes())
            ->map(fn ($r) => strtoupper($r->methods()[0]).' '.$r->uri());

        $this->assertTrue(
            $routes->contains(fn ($r) => str_contains($r, 'PUT')
                && str_contains($r, 'projects/{project}/roles/{user}')),
            'Expected PUT alias route for /projects/{project}/roles/{user} to be registered.'
        );
    }

    public function test_put_members_user_returns_non_404_when_controller_method_is_missing(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->create(['organization_id' => $org->id]);
        $this->grantCanonicalSuperAdmin($admin);

        $project = Project::factory()->create([
            'organization_id' => $org->id,
        ]);
        $member = User::factory()->create(['organization_id' => $org->id]);
        $this->assignCanonicalRole($member, 'project_member', 'project', $project->id);

        $response = $this->actingAs($admin)
            ->putJson(
                "/api/projects/{$project->id}/members/{$member->id}",
                ['role' => 'viewer'],
                ['X-Skip-Csrf' => '1'],
            );

        $this->assertNotSame(
            404,
            $response->status(),
            'Route must be registered (non-404). Other agents will add the controller method.',
        );
    }

    public function test_put_roles_user_alias_returns_non_404_when_controller_method_is_missing(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->create(['organization_id' => $org->id]);
        $this->grantCanonicalSuperAdmin($admin);

        $project = Project::factory()->create([
            'organization_id' => $org->id,
        ]);
        $member = User::factory()->create(['organization_id' => $org->id]);
        $this->assignCanonicalRole($member, 'project_member', 'project', $project->id);

        $response = $this->actingAs($admin)
            ->putJson(
                "/api/projects/{$project->id}/roles/{$member->id}",
                ['role' => 'viewer'],
                ['X-Skip-Csrf' => '1'],
            );

        $this->assertNotSame(
            404,
            $response->status(),
            'Alias route must be registered (non-404).',
        );
    }
}
