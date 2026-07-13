<?php

namespace Tests\Unit\Policies;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Core\Policies\SystemSettingsPolicy;
use App\Modules\HR\Models\Department;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit tests for SystemSettingsPolicy.
 *
 * The policy has three methods:
 *   - before(): super_admin → true, everyone else → null (fall-through)
 *   - viewAny() / view(): always true (public read)
 *   - update(): admin or holder of edit_settings permission → true; viewer → false
 */
class SystemSettingsPolicyTest extends TestCase
{
    use RefreshDatabase;

    private SystemSettingsPolicy $policy;

    private Organization $org;

    private Department $dept;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->policy = new SystemSettingsPolicy;
        $this->org = Organization::factory()->create();
        $this->dept = Department::factory()->create(['organization_id' => $this->org->id]);
    }

    private function makeUser(string $role): User
    {
        $user = User::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
            'is_active' => true,
        ]);
        $role === 'super_admin'
                ? $this->grantCanonicalSuperAdmin($user)
                : $this->assignCanonicalRole($user, $role);

        return $user;
    }

    // ========== before() ==========

    public function test_super_admin_before_returns_true(): void
    {
        $sa = $this->makeUser('super_admin');

        $this->assertTrue($this->policy->before($sa, 'update'));
    }

    public function test_admin_before_returns_null(): void
    {
        $admin = $this->makeUser('admin');

        $this->assertNull($this->policy->before($admin, 'update'));
    }

    public function test_viewer_before_returns_null(): void
    {
        $viewer = $this->makeUser('viewer');

        $this->assertNull($this->policy->before($viewer, 'update'));
    }

    // ========== viewAny() ==========

    public function test_view_any_is_public(): void
    {
        // viewAny accepts no parameters — it is a public endpoint
        $this->assertTrue($this->policy->viewAny());
    }

    // ========== view() ==========

    public function test_view_is_allowed_for_any_user(): void
    {
        $viewer = $this->makeUser('viewer');

        $this->assertTrue($this->policy->view($viewer));
    }

    public function test_view_is_allowed_with_null_user(): void
    {
        // Settings are readable even when unauthenticated (public read)
        $this->assertTrue($this->policy->view(null));
    }

    // ========== update() ==========

    public function test_admin_cannot_update_system_settings(): void
    {
        // Direction B (M-01): global system settings are platform-wide
        // (no organization_id) so only super_admin may write them. An
        // org-scoped admin carrying SETTINGS_EDIT must NOT pass here --
        // the pre-Direction-B policy allowed org admin to update global
        // settings, which leaked across org boundaries.
        $admin = $this->makeUser('admin');

        $this->assertFalse($this->policy->update($admin));
    }

    public function test_viewer_cannot_update_system_settings(): void
    {
        $viewer = $this->makeUser('viewer');

        $this->assertFalse($this->policy->update($viewer));
    }

    public function test_super_admin_can_update_system_settings_via_before_short_circuit(): void
    {
        // Confirm before() short-circuits before update() is reached for
        // super_admin. The Gate would return true for super_admin without
        // calling update() at all -- verify both paths agree.
        $sa = $this->makeUser('super_admin');

        $this->assertTrue($this->policy->before($sa, 'update'));
        $this->assertTrue($this->policy->update($sa));
    }
}
