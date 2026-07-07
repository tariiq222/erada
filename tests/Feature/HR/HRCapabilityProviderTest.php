<?php

namespace Tests\Feature\HR;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Services\HRCapabilityProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * HRCapabilityProviderTest — regression for the Wave 3 P0 ship blocker.
 *
 * The unified AccessDecision engine exposes Capability::HR_VIEW /
 * Capability::HR_MANAGE. The SPA, however, still gates routes, menus,
 * and buttons on the flat legacy strings `view_hr` / `manage_hr`
 * (resources/js/app.tsx, resources/js/shared/nasaq/app.tsx,
 * resources/js/pages/hr/*). After the cutover removes the Spatie
 * `view_hr` / `manage_hr` rows, HRCapabilityProvider is the only path
 * that puts those keys back into auth/me.permissions.
 *
 * If this provider stops returning the wire-format flags (or the
 * service provider stops tagging it), every HR user gets locked out
 * of HR pages. These tests pin the four key states:
 *
 *   - view only      -> view_hr=true,  manage_hr=false
 *   - manage         -> view_hr=true,  manage_hr=true
 *   - no grant       -> view_hr=false, manage_hr=false
 *   - super_admin    -> view_hr=true,  manage_hr=true
 */
class HRCapabilityProviderTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    private function makeUser(): User
    {
        $org = Organization::factory()->create();

        return User::factory()->create(['organization_id' => $org->id]);
    }

    public function test_user_with_view_grant_sees_view_only(): void
    {
        $user = $this->makeUser();
        $this->grantEngineCapability($user, Capability::HR_VIEW);

        $flags = (new HRCapabilityProvider)->userCapabilities($user);

        $this->assertSame(
            ['view_hr' => true, 'manage_hr' => false],
            $flags
        );
    }

    public function test_user_with_manage_grant_sees_both(): void
    {
        $user = $this->makeUser();
        // Single role covers both — see GrantsEngineCapability notes on
        // single-role-per-scope semantics. HR_MANAGE implies HR_VIEW at
        // the wire-format level because manage grants full HR access.
        $this->grantEngineCapability(
            $user,
            [Capability::HR_VIEW, Capability::HR_MANAGE]
        );

        $flags = (new HRCapabilityProvider)->userCapabilities($user);

        $this->assertSame(
            ['view_hr' => true, 'manage_hr' => true],
            $flags
        );
    }

    public function test_user_with_no_grant_sees_neither(): void
    {
        $user = $this->makeUser();
        // No engine grant, no role bypass.

        $flags = (new HRCapabilityProvider)->userCapabilities($user);

        $this->assertSame(
            ['view_hr' => false, 'manage_hr' => false],
            $flags
        );
    }

    public function test_super_admin_sees_both(): void
    {
        $user = $this->makeUser();
        $user->assignRole('super_admin');
        // No engine grant — super_admin short-circuit inside
        // AccessDecision::can() must produce both flags true.

        $flags = (new HRCapabilityProvider)->userCapabilities($user);

        $this->assertSame(
            ['view_hr' => true, 'manage_hr' => true],
            $flags
        );
    }
}
