<?php

namespace Tests\Feature\Auth;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Authorization\Models\AuthorizationRoleAssignment;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Verifies the canonical capabilities/access/role_assignments /api/user payload. */
class AuthMeCapabilitiesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeaders(['X-Skip-Csrf' => '1']);
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_auth_me_returns_engine_capabilities_and_canonical_assignments(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $this->grantCanonicalSuperAdmin($user);

        $payload = $this->actingAs($user, 'sanctum')
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonStructure(['user' => ['capabilities', 'access', 'role_assignments']])
            ->json('user');

        $this->assertContains(Capability::PROJECTS_VIEW, $payload['capabilities']);
        $this->assertContains(Capability::TASKS_VIEW, $payload['capabilities']);
        $this->assertEveryCapabilityIsCanonical($payload['capabilities']);
        $this->assertCount(1, $payload['role_assignments']);
    }

    public function test_access_is_a_truthy_projection_of_capabilities(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $this->grantCanonicalSuperAdmin($user);

        $payload = $this->actingAs($user, 'sanctum')
            ->getJson('/api/user')
            ->assertOk()
            ->json('user');

        $this->assertNotEmpty($payload['capabilities']);
        $this->assertSame(array_fill_keys($payload['capabilities'], true), $payload['access']);
    }

    public function test_auth_me_includes_canonical_department_assignment(): void
    {
        $organization = Organization::factory()->create(['is_active' => true]);
        $department = Department::factory()->create(['organization_id' => $organization->id]);
        $user = User::factory()->create([
            'is_active' => true,
            'organization_id' => $organization->id,
        ]);
        $assignment = $this->createContractRoleAssignment(
            $user,
            'dept_manager',
            AuthorizationRoleAssignment::SCOPE_DEPARTMENT,
            $department->id,
        );

        $payload = $this->actingAs($user, 'sanctum')
            ->getJson('/api/user')
            ->assertOk()
            ->json('user');

        $assignments = $payload['role_assignments'];

        $this->assertCount(1, $assignments);
        $this->assertSame($assignment->id, $assignments[0]['id']);
        $this->assertSame('dept_manager', $assignments[0]['role']);
        $this->assertSame(AuthorizationRoleAssignment::SCOPE_DEPARTMENT, $assignments[0]['scope_type']);
        $this->assertSame($department->id, $assignments[0]['scope_id']);
        $this->assertSame($organization->id, $assignments[0]['organization_id']);
        $this->assertSame('manual', $assignments[0]['source']);
        $this->assertContains(Capability::DEPARTMENTS_MANAGE_MEMBERS, $payload['capabilities']);
        $this->assertContains(Capability::PROJECTS_VIEW, $payload['capabilities']);
        $this->assertTrue($payload['access'][Capability::DEPARTMENTS_MANAGE_MEMBERS]);
    }

    public function test_role_assignments_is_empty_for_user_without_assignment(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $assignments = $this->actingAs($user, 'sanctum')
            ->getJson('/api/user')
            ->assertOk()
            ->json('user.role_assignments');

        $this->assertSame([], $assignments);
    }

    public function test_auth_me_omits_all_legacy_authorization_payloads(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $this->grantCanonicalSuperAdmin($user);

        $payload = $this->actingAs($user, 'sanctum')
            ->getJson('/api/user')
            ->assertOk()
            ->json('user');

        $this->assertArrayNotHasKey('roles', $payload);
        $this->assertArrayNotHasKey('permissions', $payload);
        $this->assertArrayNotHasKey('scoped_roles', $payload);
    }

    public function test_access_and_capabilities_are_empty_for_user_without_assignments(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $payload = $this->actingAs($user, 'sanctum')
            ->getJson('/api/user')
            ->assertOk()
            ->json('user');

        $this->assertSame([], $payload['capabilities']);
        $this->assertSame([], $payload['access']);
        $this->assertSame([], $payload['role_assignments']);
    }

    /** @param list<string> $capabilities */
    private function assertEveryCapabilityIsCanonical(array $capabilities): void
    {
        $known = array_fill_keys(Capability::all(), true);

        foreach ($capabilities as $capability) {
            $this->assertArrayHasKey($capability, $known, "capability '{$capability}' is not canonical");
            $this->assertMatchesRegularExpression(
                '/^[a-z_]+(?:\.[a-z_]+)+$/',
                $capability,
                "capability '{$capability}' is not in canonical dotted form",
            );
        }
    }

    private function createContractRoleAssignment(
        User $user,
        string $roleName,
        string $scopeType = AuthorizationRoleAssignment::SCOPE_ALL,
        ?int $scopeId = null,
    ): AuthorizationRoleAssignment {
        $role = AuthorizationRole::query()->where('name', $roleName)->firstOrFail();

        return AuthorizationRoleAssignment::query()->create([
            'authorization_role_id' => $role->id,
            'user_id' => $user->id,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'organization_id' => $scopeType === AuthorizationRoleAssignment::SCOPE_DEPARTMENT
                ? Department::query()->whereKey($scopeId)->value('organization_id')
                : null,
            'source' => 'manual',
        ]);
    }
}
