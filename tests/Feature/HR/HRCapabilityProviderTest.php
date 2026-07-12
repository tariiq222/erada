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
 * HRCapabilityProviderTest — capability-provider contract pin.
 *
 * Verified residual (2026-07-12): HRCapabilityProvider is a legacy/advisory
 * module helper tagged under `engined_capability_providers`. The canonical
 * /api/user projection (AuthController) does NOT iterate the tag — it derives
 * capabilities from `User::canonicalCapabilityNames()`, which returns the
 * canonical dotted strings (`hr.view`, `hr.manage`) backed by
 * AccessDecision + role grants. The provider itself is kept in place only
 * because modules still wire it; future cleanup may retire it.
 *
 * These tests document the contract split:
 *   - The canonical /api/user authority is `User::canonicalCapabilityNames()`,
 *     which surfaces canonical dotted capabilities (`hr.view`, `hr.manage`).
 *   - The provider remains a non-authoritative helper that mirrors decisions
 *     via AccessDecision; its flat `view_hr` / `manage_hr` keys are advisory
 *     only and MUST NOT be treated as the wire-format source of truth.
 */
class HRCapabilityProviderTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    private function makeUser(): User
    {
        $org = Organization::factory()->create();

        return User::factory()->create(['organization_id' => $org->id]);
    }

    public function test_canonical_user_projection_returns_dotted_hr_capabilities_for_view_grant(): void
    {
        $user = $this->makeUser();
        $this->grantEngineCapability($user, Capability::HR_VIEW);

        $capabilities = $user->canonicalCapabilityNames();

        $this->assertContains('hr.view', $capabilities);
        $this->assertNotContains('hr.manage', $capabilities);
        $this->assertNotContains('view_hr', $capabilities);
        $this->assertNotContains('manage_hr', $capabilities);
    }

    public function test_canonical_user_projection_returns_dotted_hr_capabilities_for_manage_grant(): void
    {
        $user = $this->makeUser();
        $this->grantEngineCapability(
            $user,
            [Capability::HR_VIEW, Capability::HR_MANAGE]
        );

        $capabilities = $user->canonicalCapabilityNames();

        $this->assertContains('hr.view', $capabilities);
        $this->assertContains('hr.manage', $capabilities);
    }

    public function test_canonical_user_projection_is_empty_when_no_engine_grant(): void
    {
        $user = $this->makeUser();

        $capabilities = $user->canonicalCapabilityNames();

        $this->assertNotContains('hr.view', $capabilities);
        $this->assertNotContains('hr.manage', $capabilities);
    }

    public function test_canonical_user_projection_short_circuits_to_all_capabilities_for_super_admin(): void
    {
        $user = $this->makeUser();
        $this->grantCanonicalSuperAdmin($user);

        $capabilities = $user->canonicalCapabilityNames();

        $this->assertContains('hr.view', $capabilities);
        $this->assertContains('hr.manage', $capabilities);
    }

    public function test_provider_is_not_the_api_user_authority_and_mirrors_access_decision(): void
    {
        // The provider is a legacy/advisory helper, not the /api/user
        // authority. The SPA should never rely on its keys; canonical
        // capabilities are derived via User::canonicalCapabilityNames().
        $user = $this->makeUser();
        $this->grantEngineCapability($user, Capability::HR_VIEW);

        $flags = (new HRCapabilityProvider)->userCapabilities($user);

        $this->assertSame(
            ['view_hr' => true, 'manage_hr' => false],
            $flags,
            'HRCapabilityProvider output is non-authoritative — canonical /api/user uses canonicalCapabilityNames().'
        );
    }
}
