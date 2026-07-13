<?php

namespace Tests\Feature\Api;

use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Authorization\Models\AuthorizationRoleAssignment;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthControllerUserPayloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_payload_exposes_is_super_admin_and_is_org_admin_flags(): void
    {
        $org = Organization::factory()->create(['is_active' => true]);
        $user = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $role = AuthorizationRole::query()->updateOrCreate(
            ['name' => 'admin'],
            ['scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION, 'is_admin_role' => true, 'is_system' => false, 'is_active' => true, 'label' => 'Organization Admin']
        );
        AuthorizationRoleAssignment::query()->create([
            'user_id' => $user->id,
            'authorization_role_id' => $role->id,
            'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
            'scope_id' => $org->id,
            'organization_id' => $org->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/user');

        $response->assertOk();
        // The /api/user controller wraps the payload as { user: buildFormatUserPayload(...) },
        // so the flags live under user.is_super_admin / user.is_org_admin.
        $response->assertJsonPath('user.is_super_admin', false);
        $response->assertJsonPath('user.is_org_admin', true);
    }
}
