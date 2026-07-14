<?php

namespace Tests\Unit\Core;

use App\Modules\Core\Authorization\Capability;
use PHPUnit\Framework\TestCase;

class OrganizationSuperAdminCapabilityConstantsTest extends TestCase
{
    public function test_org_super_capability_constants_resolve_to_dotted_strings(): void
    {
        $this->assertSame('users.activate', Capability::USERS_ACTIVATE);
        $this->assertSame('users.deactivate', Capability::USERS_DEACTIVATE);
        $this->assertSame('organization.settings.view', Capability::ORGANIZATION_SETTINGS_VIEW);
        $this->assertSame('organization.settings.edit', Capability::ORGANIZATION_SETTINGS_EDIT);
    }

    public function test_org_super_capability_constants_are_part_of_capability_all(): void
    {
        $all = Capability::all();
        $this->assertContains(Capability::USERS_ACTIVATE, $all);
        $this->assertContains(Capability::USERS_DEACTIVATE, $all);
        $this->assertContains(Capability::ORGANIZATION_SETTINGS_VIEW, $all);
        $this->assertContains(Capability::ORGANIZATION_SETTINGS_EDIT, $all);
    }
}
