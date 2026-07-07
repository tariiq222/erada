<?php

namespace Tests\Feature\Auth;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AuthMeContractTest — the /api/user (auth/me) response carries a `capabilities`
 * array used for menu/button/route gating in the SPA. After Phase 9.3 the legacy
 * `permissions[]` blob is removed; `capabilities[]` and the structured `access{}`
 * map are the single source of truth (see docs/authz/deprecation-policy.md).
 * The engine-derived canonical capabilities (`module.action`, e.g. projects.view)
 * are emitted; per-record ability checks MUST still read abilities from the
 * element endpoint, not from this generic list.
 */
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
            ->assertJsonStructure(['user' => ['capabilities']]);
    }

    public function test_auth_me_does_not_emit_legacy_permissions_array(): void
    {
        // Phase 9.3 cutover: `permissions[]` is removed from the payload.
        // Pin this for super_admin (the highest-grant user) so a future
        // regression that re-emits the legacy blob to "help" route guards
        // is caught before the SPA double-gates against two vocabularies.
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('super_admin');

        $response = $this->actingAs($user, 'sanctum')
            ->getJson(self::AUTH_ME_ENDPOINT)
            ->assertOk();

        $userPayload = $response->json('user');
        $this->assertIsArray($userPayload);
        $this->assertArrayNotHasKey(
            'permissions',
            $userPayload,
            '/api/user payload must NOT include the legacy permissions[] key (Phase 9.3 cutover).'
        );
    }

    public function test_auth_me_emits_canonical_keys(): void
    {
        // Single source of truth: every /api/user payload carries
        // capabilities[], access{}, roles[], scoped_roles[], organization_id.
        // Missing any one of these breaks the SPA — pin all of them.
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('super_admin');

        $this->actingAs($user, 'sanctum')
            ->getJson(self::AUTH_ME_ENDPOINT)
            ->assertOk()
            ->assertJsonStructure([
                'user' => [
                    'capabilities',
                    'access',
                    'roles',
                    'scoped_roles',
                    'organization_id',
                ],
            ]);
    }

    public function test_auth_me_includes_engine_capabilities_for_super_admin(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('super_admin');

        $response = $this->actingAs($user, 'sanctum')
            ->getJson(self::AUTH_ME_ENDPOINT)
            ->assertOk();

        $capabilities = $response->json('user.capabilities');
        $this->assertIsArray($capabilities);
        $this->assertContains(Capability::PROJECTS_VIEW, $capabilities);
        $this->assertContains(Capability::TASKS_VIEW, $capabilities);
    }
}
