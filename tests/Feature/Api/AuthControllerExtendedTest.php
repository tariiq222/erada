<?php

namespace Tests\Feature\Api;

use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthControllerExtendedTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $regularUser;

    protected Department $department;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->department = Department::factory()->create();

        $this->admin = User::factory()->create([
            'department_id' => $this->department->id,
            'is_active' => true,
            'password' => Hash::make('Password123!'),
        ]);
        $this->grantCanonicalSuperAdmin($this->admin);

        $this->regularUser = User::factory()->create([
            'department_id' => $this->department->id,
            'is_active' => true,
            'password' => Hash::make('Password123!'),
        ]);
        $this->assignCanonicalRole($this->regularUser, 'member');
    }

    // ========== updateLocale ==========

    public function test_can_update_locale_to_arabic(): void
    {
        $response = $this->actingAs($this->regularUser, 'sanctum')
            ->putJson('/api/user/locale', ['locale' => 'ar']);

        $response->assertStatus(200)
            ->assertJsonFragment(['locale' => 'ar']);

        $this->assertDatabaseHas('users', [
            'id' => $this->regularUser->id,
            'preferred_locale' => 'ar',
        ]);
    }

    public function test_can_update_locale_to_english(): void
    {
        $response = $this->actingAs($this->regularUser, 'sanctum')
            ->putJson('/api/user/locale', ['locale' => 'en']);

        $response->assertStatus(200)
            ->assertJsonFragment(['locale' => 'en']);
    }

    public function test_locale_rejects_invalid_value(): void
    {
        $response = $this->actingAs($this->regularUser, 'sanctum')
            ->putJson('/api/user/locale', ['locale' => 'fr']);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['locale']);
    }

    public function test_locale_requires_locale_field(): void
    {
        $response = $this->actingAs($this->regularUser, 'sanctum')
            ->putJson('/api/user/locale', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['locale']);
    }

    // ========== getSecurityStatus ==========

    public function test_admin_can_view_user_security_status(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/users/{$this->regularUser->id}/security");

        $response->assertStatus(200)
            ->assertJsonStructure(['security']);
    }

    public function test_member_cannot_view_other_user_security_status(): void
    {
        $otherUser = User::factory()->create([
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        $this->assignCanonicalRole($otherUser, 'member');

        $response = $this->actingAs($this->regularUser, 'sanctum')
            ->getJson("/api/users/{$otherUser->id}/security");

        $response->assertStatus(403);
    }

    // ========== unlockAccount ==========

    public function test_admin_can_unlock_account(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/users/{$this->regularUser->id}/unlock");

        $response->assertStatus(200);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'unlock_account',
            'loggable_type' => User::class,
            'loggable_id' => $this->regularUser->id,
            'user_id' => $this->admin->id,
        ]);
    }

    public function test_member_cannot_unlock_account(): void
    {
        $lockedUser = User::factory()->create([
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        $this->assignCanonicalRole($lockedUser, 'member');

        $response = $this->actingAs($this->regularUser, 'sanctum')
            ->postJson("/api/users/{$lockedUser->id}/unlock");

        $response->assertStatus(403);
    }

    // ========== unauthenticated ==========

    public function test_unauthenticated_cannot_update_locale(): void
    {
        $response = $this->putJson('/api/user/locale', ['locale' => 'ar']);
        $response->assertStatus(401);
    }
}
