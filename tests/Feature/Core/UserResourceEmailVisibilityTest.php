<?php

namespace Tests\Feature\Core;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Http\Resources\UserResource;
use App\Modules\Core\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * Regression tests for PII gating of the `email` field in UserResource.
 *
 * Email is personally identifiable information. The resource must only
 * expose it to:
 *   - the resource owner (self),
 *   - users with the `manage_organization` permission (User::isAdmin()),
 *   - users with the `super_admin` role (User::isSuperAdmin()),
 *   - users with the `view_users` permission.
 *
 * Any other authenticated user must see the email omitted from the payload.
 */
class UserResourceEmailVisibilityTest extends TestCase
{
    use GrantsEngineCapability;
    use RefreshDatabase;

    protected User $owner;

    protected User $adminUser;

    protected User $viewerUser;

    protected User $regularUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->owner = User::factory()->create([
            'email' => 'owner@example.com',
            'is_active' => true,
        ]);

        $this->adminUser = User::factory()->create([
            'email' => 'admin@example.com',
            'is_active' => true,
        ]);
        $this->grantEngineCapability($this->adminUser, Capability::SETTINGS_MANAGE);

        $this->viewerUser = User::factory()->create([
            'email' => 'viewerperm@example.com',
            'is_active' => true,
        ]);
        $this->viewerUser->givePermissionTo('view_users');

        $this->regularUser = User::factory()->create([
            'email' => 'regular@example.com',
            'is_active' => true,
        ]);
    }

    /**
     * Build an authenticated request whose `$request->user()` resolves
     * to the supplied viewer. This mirrors what actingAs() does for
     * real HTTP requests, so the resource's authorization branch sees
     * the right principal.
     */
    protected function requestAs(User $viewer): Request
    {
        $this->actingAs($viewer, 'sanctum');

        $request = Request::create('/api/test');
        $request->setUserResolver(fn () => $viewer);

        return $request;
    }

    public function test_email_visible_to_self(): void
    {
        $request = $this->requestAs($this->owner);

        $payload = (new UserResource($this->owner))->resolve($request);

        $this->assertArrayHasKey('email', $payload);
        $this->assertSame('owner@example.com', $payload['email']);
    }

    public function test_email_visible_to_admin(): void
    {
        $request = $this->requestAs($this->adminUser);

        $payload = (new UserResource($this->owner))->resolve($request);

        $this->assertArrayHasKey('email', $payload);
        $this->assertSame('owner@example.com', $payload['email']);
    }

    public function test_email_hidden_from_regular_user_without_view_users(): void
    {
        $request = $this->requestAs($this->regularUser);

        $payload = (new UserResource($this->owner))->resolve($request);

        $this->assertArrayNotHasKey('email', $payload);
    }

    public function test_email_visible_to_user_with_view_users_permission(): void
    {
        $request = $this->requestAs($this->viewerUser);

        $payload = (new UserResource($this->owner))->resolve($request);

        $this->assertArrayHasKey('email', $payload);
        $this->assertSame('owner@example.com', $payload['email']);
    }
}
