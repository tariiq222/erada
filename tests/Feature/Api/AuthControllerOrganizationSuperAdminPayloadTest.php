<?php

namespace Tests\Feature\Api;

use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Authorization\Models\AuthorizationRoleAssignment;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthControllerOrganizationSuperAdminPayloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_payload_exposes_is_organization_super_admin_for_org_super_actor(): void
    {
        $org = Organization::factory()->create(['is_active' => true]);
        $user = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $role = AuthorizationRole::query()->updateOrCreate(
            ['name' => 'organization_super_admin'],
            [
                'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
                'is_admin_role' => false,
                'is_system' => true,
                'is_active' => true,
                'label' => 'Organization Super Admin',
            ],
        );
        AuthorizationRoleAssignment::query()->create([
            'user_id' => $user->id,
            'authorization_role_id' => $role->id,
            'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
            'scope_id' => $org->id,
            'organization_id' => $org->id,
            'is_active' => true,
        ]);

        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson('/api/user');

        $response->assertOk();
        // Brief uses root-level JSON paths; the controller's `user()` method
        // (AuthController.php:356-361) wraps the buildFormatUserPayload() return
        // value as `{ "user": {...} }`, so the canonical /api/user response
        // exposes these flags under `user.*`. The sibling test
        // `AuthControllerUserPayloadTest.php` (lines 38-39) already asserts on
        // `user.is_super_admin` / `user.is_org_admin` for the same reason.
        $response->assertJsonPath('user.is_super_admin', false);
        $response->assertJsonPath('user.is_org_admin', false);
        $response->assertJsonPath('user.is_organization_super_admin', true);
    }

    public function test_payload_exposes_is_organization_super_admin_false_for_non_org_super_actor(): void
    {
        $org = Organization::factory()->create(['is_active' => true]);
        $user = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);

        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson('/api/user');

        $response->assertOk();
        // Same wrapper note as test #1 above: brief's root-level path
        // `is_organization_super_admin` does not match the controller's
        // `{ "user": {...} }` response shape; the canonical path is
        // `user.is_organization_super_admin`.
        $response->assertJsonPath('user.is_organization_super_admin', false);
    }
}
