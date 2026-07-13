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

        // Compare (resource FQCN, action) pairs rather than capability
        // strings. Multiple capability constants (e.g. `departments.view`
        // and `hr.view`) can map to the same AuthorizationResource row via
        // `CapabilityToAuthorizationRolePermission::PREFIX_TO_RESOURCE`,
        // so a capability-string comparison would over-count pivots that
        // are reached by an alias. The pivot set is the actual data
        // structure the engine reads, so comparing pairs gives a stable
        // identity match that is independent of capability naming aliases.
        $expectedPairs = collect($expected)
            ->map(fn (string $capability) => CapabilityToAuthorizationRolePermission::map($capability))
            ->filter()
            ->map(fn (array $m) => $m['resource'].'::'.$m['action'])
            ->sort()
            ->values()
            ->all();

        $actualPairs = $role->permissions()
            ->with('resource')
            ->get()
            ->map(fn ($permission) => $permission->resource->key.'::'.$permission->action)
            ->sort()
            ->values()
            ->all();

        $this->assertEqualsCanonicalizing(
            $expectedPairs,
            $actualPairs,
            'OrgAdmin role pivots must equal the curated (resource, action) set exactly.',
        );
    }

    public function test_admin_role_does_not_grant_core_assign_roles(): void
    {
        (new RolesAndPermissionsSeeder)->run();
        $role = AuthorizationRole::query()->where('name', 'admin')->firstOrFail();

        $coreAssignRolesMapping = CapabilityToAuthorizationRolePermission::map(Capability::CORE_ASSIGN_ROLES);
        $this->assertNotNull(
            $coreAssignRolesMapping,
            'CapabilityToAuthorizationRolePermission::map must resolve CORE_ASSIGN_ROLES.',
        );

        $resourceId = AuthorizationResource::where('key', $coreAssignRolesMapping['resource'])->value('id');
        $this->assertNotNull(
            $resourceId,
            "AuthorizationResource row for {$coreAssignRolesMapping['resource']} must exist after seeding.",
        );

        $this->assertFalse(
            $role->permissions()
                ->where('authorization_resource_id', $resourceId)
                ->where('action', $coreAssignRolesMapping['action'])
                ->exists(),
            'OrgAdmin role must not grant core.assign_roles (capability was reserved for super_admin only).',
        );
    }
}
