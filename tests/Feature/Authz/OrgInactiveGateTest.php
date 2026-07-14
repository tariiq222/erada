<?php

namespace Tests\Feature\Authz;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * OrgAdmin plan — Task 4: organization-inactive login gate.
 *
 * Coverage contract:
 *  - `AuthController::login()` MUST reject credentials when the user's
 *    organization is `is_active=false` with HTTP 401 + `reason: organization_inactive`.
 *  - `EnsureUserIsActive` middleware MUST apply the same gate to already
 *    authenticated API requests so that flipping the org inactive mid-session
 *    invalidates existing Sanctum tokens.
 *
 * The existing `account_deactivated` and `account_locked` paths MUST NOT
 * change — these tests assert only the new `organization_inactive` reason.
 */
class OrgInactiveGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_login_rejects_user_whose_org_is_inactive(): void
    {
        $org = Organization::factory()->create(['is_active' => false]);
        $user = User::factory()->create([
            'organization_id' => $org->id,
            'is_active' => true,
            'password' => Hash::make('password'),
        ]);

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertStatus(401);
        $response->assertJsonPath('reason', 'organization_inactive');
    }

    public function test_authenticated_request_returns_401_when_user_org_becomes_inactive(): void
    {
        $org = Organization::factory()->create(['is_active' => true]);
        $user = User::factory()->create([
            'organization_id' => $org->id,
            'is_active' => true,
        ]);

        Sanctum::actingAs($user, ['*']);
        $this->getJson('/api/user')->assertOk();

        $org->update(['is_active' => false]);

        $response = $this->getJson('/api/user');
        $response->assertStatus(401);
        $response->assertJsonPath('reason', 'organization_inactive');
    }
}
