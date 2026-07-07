<?php

namespace Tests\Feature\Auth;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\ScopedRole;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AuthMeCapabilitiesTest — Phase 2 of ADR-UNIFIED-ROLE-ACCESS, advanced through
 * Phase 9.3 (2026-07-05).
 *
 * The /api/user (auth/me) payload exposes canonical, engine-derived keys:
 *   - `capabilities`: the canonical module.action list the user effectively
 *     holds (engine-derived, single form, one source of truth).
 *   - `access`: a structured `{module: {action: true}}` projection derived
 *     only from canonical capabilities — the read-side object consumed by the
 *     SPA `useCan` / `useAccess` hooks.
 *   - `scoped_roles`: the user's scoped role assignments from
 *     model_has_scoped_roles, shape {role, scope_type, scope_id, label}.
 *   - `roles`: the legacy Spatie role names, retained for SPA role-gated UI.
 *
 * After Phase 9.3 the legacy `permissions[]` flat blob is removed from the
 * payload. SPA gating MUST read `access` / `capabilities` (via the access
 * bridge) instead of `permissions`. See docs/authz/deprecation-policy.md.
 */
class AuthMeCapabilitiesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeaders(['X-Skip-Csrf' => '1']);
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_auth_me_returns_canonical_capabilities_key(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('super_admin');

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonStructure(['user' => ['capabilities', 'scoped_roles']]);

        $capabilities = $response->json('user.capabilities');
        $this->assertIsArray($capabilities);
        // Canonical module.action form present.
        $this->assertContains(Capability::PROJECTS_VIEW, $capabilities);
        $this->assertContains(Capability::TASKS_VIEW, $capabilities);
        // Every entry is a canonical module.action string (no legacy flat names).
        foreach ($capabilities as $cap) {
            $this->assertMatchesRegularExpression('/^[a-z_]+\.[a-z_]+$/', $cap, "capability '{$cap}' is not module.action form");
        }
    }

    public function test_capabilities_is_coherent_with_access_map(): void
    {
        // Phase 9.3: legacy `permissions[]` blob removed. `access` is the
        // read-side projection derived from `capabilities`; pin that every
        // `access.module.action` key exists in `capabilities`, so the SPA
        // `useCan` hook cannot drift from the engine-derived source of truth.
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('super_admin');

        $payload = $this->actingAs($user, 'sanctum')
            ->getJson('/api/user')
            ->assertOk()
            ->json('user');

        $this->assertNotEmpty($payload['capabilities']);
        foreach ($payload['access'] as $module => $actions) {
            foreach ($actions as $action => $_) {
                $this->assertContains(
                    $module.'.'.$action,
                    $payload['capabilities'],
                    "user.access key '{$module}.{$action}' must exist in user.capabilities",
                );
            }
        }
    }

    public function test_auth_me_includes_scoped_role_assignments_for_scoped_user(): void
    {
        $org = Organization::factory()->create(['is_active' => true]);
        $department = Department::factory()->create(['organization_id' => $org->id]);
        $user = User::factory()->create([
            'is_active' => true,
            'organization_id' => $org->id,
        ]);

        ScopedRole::create([
            'user_id' => $user->id,
            'role' => ScopedRole::DEPARTMENT_MANAGER,
            'scope_type' => ScopedRole::SCOPE_DEPARTMENT,
            'scope_id' => $department->id,
            'source' => 'manual',
        ]);

        $scoped = $this->actingAs($user, 'sanctum')
            ->getJson('/api/user')
            ->assertOk()
            ->json('user.scoped_roles');

        $this->assertIsArray($scoped);
        $this->assertCount(1, $scoped);
        $this->assertSame(ScopedRole::DEPARTMENT_MANAGER, $scoped[0]['role']);
        $this->assertSame(ScopedRole::SCOPE_DEPARTMENT, $scoped[0]['scope_type']);
        $this->assertSame($department->id, $scoped[0]['scope_id']);
        $this->assertArrayHasKey('label', $scoped[0]);
        $this->assertNotEmpty($scoped[0]['label']);
    }

    public function test_auth_me_scoped_roles_empty_for_user_without_scoped_role(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $scoped = $this->actingAs($user, 'sanctum')
            ->getJson('/api/user')
            ->assertOk()
            ->json('user.scoped_roles');

        $this->assertIsArray($scoped);
        $this->assertSame([], $scoped);
    }

    public function test_auth_me_keeps_legacy_roles_intact_but_drops_permissions(): void
    {
        // Phase 9.3 cutover: legacy `roles[]` is still emitted (used by
        // legacy role-gated UI) but the legacy `permissions[]` flat blob is
        // removed — engine-derived `capabilities[]` is the single source.
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('super_admin');
        $user->givePermissionTo('view_projects');

        $payload = $this->actingAs($user, 'sanctum')
            ->getJson('/api/user')
            ->assertOk()
            ->json('user');

        // Legacy role key still present and populated.
        $this->assertContains('super_admin', $payload['roles']);
        // Legacy permissions blob is removed (Phase 9.3 cutover).
        $this->assertArrayNotHasKey('permissions', $payload);
    }

    public function test_auth_me_exposes_structured_user_access_payload(): void
    {
        // Phase 1 of the master AuthZ unification plan: an additive
        // `user.access` projection is derived only from canonical
        // module.action capabilities (e.g. tasks.assign). Legacy
        // roles/permissions/capabilities keys MUST remain intact.
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('super_admin');

        $payload = $this->actingAs($user, 'sanctum')
            ->getJson('/api/user')
            ->assertOk()
            ->json('user');

        $this->assertArrayHasKey('access', $payload);
        $this->assertIsArray($payload['access']);
        $this->assertNotEmpty($payload['access']);
    }

    public function test_user_access_is_an_object_map_of_truthy_values(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('super_admin');

        $access = $this->actingAs($user, 'sanctum')
            ->getJson('/api/user')
            ->assertOk()
            ->json('user.access');

        $this->assertIsArray($access);
        foreach ($access as $module => $actions) {
            $this->assertIsString($module, "access module '{$module}' must be a string");
            $this->assertIsArray($actions, "access[{$module}] must be an array");
            foreach ($actions as $action => $value) {
                $this->assertIsString($action, "access[{$module}][action] key must be a string");
                $this->assertTrue($value === true, "access[{$module}][{$action}] must be exactly true");
            }
        }
    }

    public function test_every_user_access_key_is_a_real_capability(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('super_admin');

        $payload = $this->actingAs($user, 'sanctum')
            ->getJson('/api/user')
            ->assertOk()
            ->json('user');

        $this->assertNotEmpty($payload['capabilities']);
        foreach ($payload['access'] as $module => $actions) {
            foreach ($actions as $action => $_) {
                $key = $module.'.'.$action;
                $this->assertContains(
                    $key,
                    $payload['capabilities'],
                    "user.access key '{$key}' must exist in user.capabilities"
                );
            }
        }
    }

    public function test_user_access_does_not_introduce_grants_beyond_capabilities(): void
    {
        // The projection is read-side only: keys in user.access must be a
        // subset of user.capabilities. This pins that the projection does not
        // bypass the engine decision.
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('super_admin');

        $payload = $this->actingAs($user, 'sanctum')
            ->getJson('/api/user')
            ->assertOk()
            ->json('user');

        $accessKeys = [];
        foreach ($payload['access'] ?? [] as $module => $actions) {
            foreach ($actions as $action => $_) {
                $accessKeys[] = $module.'.'.$action;
            }
        }

        foreach ($accessKeys as $key) {
            $this->assertContains($key, $payload['capabilities']);
        }
    }

    public function test_user_access_is_empty_for_user_without_capabilities(): void
    {
        // A user with no roles and no capabilities gets an empty (but still
        // present and well-shaped) access map. This guards against 500s
        // when the frontend assumes access is always an object.
        $user = User::factory()->create(['is_active' => true]);

        $payload = $this->actingAs($user, 'sanctum')
            ->getJson('/api/user')
            ->assertOk()
            ->json('user');

        $this->assertArrayHasKey('access', $payload);
        $this->assertIsArray($payload['access']);
        $this->assertSame([], $payload['access']);
    }
}
