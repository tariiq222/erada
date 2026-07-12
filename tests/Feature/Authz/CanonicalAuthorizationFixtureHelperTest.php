<?php

namespace Tests\Feature\Authz;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Authorization\Models\AuthorizationRoleAssignment;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CanonicalAuthorizationFixtureHelperTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_assigns_catalog_roles_and_custom_capabilities_through_the_canonical_graph(): void
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->create(['organization_id' => $organization->id]);
        $viewer = User::factory()->create(['organization_id' => $organization->id]);

        $adminAssignment = $this->grantCanonicalAdmin($admin);
        $viewerAssignment = $this->assignCanonicalRole(
            $viewer,
            'viewer',
            AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
            $organization->id,
            [Capability::PROJECTS_EDIT],
            ['scope' => 'same_organization'],
        );

        $this->assertSame('admin', $adminAssignment->role->name);
        $this->assertSame($organization->id, $adminAssignment->scope_id);
        $this->assertSame('viewer', $viewerAssignment->role->name);
        $this->assertDatabaseHas('authorization_role_assignments', [
            'authorization_role_id' => $viewerAssignment->authorization_role_id,
            'user_id' => $viewer->id,
            'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
            'scope_id' => $organization->id,
        ]);
        $this->assertTrue(AccessDecision::can($viewer, Capability::PROJECTS_EDIT));
    }

    public function test_convenience_helpers_apply_the_expected_catalog_scope_defaults(): void
    {
        $organization = Organization::factory()->create();
        $superAdmin = User::factory()->create(['organization_id' => null]);
        $viewer = User::factory()->create(['organization_id' => $organization->id]);

        $superAssignment = $this->grantCanonicalSuperAdmin($superAdmin);
        $viewerAssignment = $this->grantCanonicalViewer($viewer);

        $this->assertSame(AuthorizationRoleAssignment::SCOPE_ALL, $superAssignment->scope_type);
        $this->assertNull($superAssignment->scope_id);
        $this->assertSame(AuthorizationRoleAssignment::SCOPE_ORGANIZATION, $viewerAssignment->scope_type);
        $this->assertSame($organization->id, $viewerAssignment->scope_id);
    }
}
