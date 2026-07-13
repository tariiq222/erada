<?php

namespace Tests\Feature\Authz;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Authorization\Models\AuthorizationResource;
use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Authorization\Support\CapabilityToAuthorizationRolePermission;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrgAdminCuratedCapabilitiesTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_role_capabilities_match_curated_org_admin_set(): void
    {
        (new RolesAndPermissionsSeeder)->run();

        $role = AuthorizationRole::query()->where('name', 'admin')->firstOrFail();
        $mapping = CapabilityToAuthorizationRolePermission::mapAll();
        $expected = [
            Capability::USERS_VIEW,
            Capability::USERS_CREATE,
            Capability::USERS_EDIT,
            Capability::DEPARTMENTS_VIEW,
            Capability::DEPARTMENTS_CREATE,
            Capability::DEPARTMENTS_EDIT,
            Capability::DEPARTMENTS_DELETE,
            Capability::ROLES_VIEW,
            Capability::SETTINGS_VIEW,
            Capability::SETTINGS_EDIT,
            Capability::AUDIT_VIEW,
        ];
        $grantedCapabilities = collect($mapping)
            ->filter(fn (array $m) => $role->permissions()
                ->where('authorization_resource_id', AuthorizationResource::where('key', $m['resource'])->value('id'))
                ->where('action', $m['action'])
                ->exists())
            ->pluck('capability')
            ->unique()
            ->values()
            ->all();

        foreach ($expected as $capability) {
            $this->assertContains($capability, $grantedCapabilities, "OrgAdmin should hold {$capability}");
        }
    }

    public function test_admin_role_does_not_grant_core_assign_roles(): void
    {
        (new RolesAndPermissionsSeeder)->run();
        $role = AuthorizationRole::query()->where('name', 'admin')->firstOrFail();

        $this->assertFalse($role->permissions()
            ->whereHas('resource', fn ($q) => $q->where('key', 'core'))
            ->where('action', 'assign_roles')
            ->exists());
    }
}
