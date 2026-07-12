<?php

namespace Tests\Feature\Auth;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Pins the canonical /api/user authorization contract after the final cutover. */
class AuthMeContractTest extends TestCase
{
    use RefreshDatabase;

    private const AUTH_ME_ENDPOINT = '/api/user';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeaders(['X-Skip-Csrf' => '1']);
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_auth_me_returns_canonical_capability_list(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $this->actingAs($user, 'sanctum')
            ->getJson(self::AUTH_ME_ENDPOINT)
            ->assertOk()
            ->assertJsonStructure(['user' => ['capabilities', 'access', 'role_assignments']]);
    }

    public function test_auth_me_does_not_emit_any_legacy_authorization_keys(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $this->grantCanonicalSuperAdmin($user);

        $payload = $this->actingAs($user, 'sanctum')
            ->getJson(self::AUTH_ME_ENDPOINT)
            ->assertOk()
            ->json('user');

        $this->assertIsArray($payload);
        $this->assertArrayNotHasKey('roles', $payload);
        $this->assertArrayNotHasKey('permissions', $payload);
        $this->assertArrayNotHasKey('scoped_roles', $payload);
    }

    public function test_auth_me_emits_only_the_canonical_authorization_keys(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $this->grantCanonicalSuperAdmin($user);

        $this->actingAs($user, 'sanctum')
            ->getJson(self::AUTH_ME_ENDPOINT)
            ->assertOk()
            ->assertJsonStructure([
                'user' => [
                    'capabilities',
                    'access',
                    'role_assignments',
                    'organization_id',
                ],
            ]);
    }

    public function test_auth_me_includes_engine_capabilities_for_canonical_super_admin(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $this->grantCanonicalSuperAdmin($user);

        $capabilities = $this->actingAs($user, 'sanctum')
            ->getJson(self::AUTH_ME_ENDPOINT)
            ->assertOk()
            ->json('user.capabilities');

        $this->assertIsArray($capabilities);
        $this->assertContains(Capability::PROJECTS_VIEW, $capabilities);
        $this->assertContains(Capability::TASKS_VIEW, $capabilities);
    }
}
