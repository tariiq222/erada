<?php

namespace Tests\Feature\Authz;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Authorization\Models\AuthorizationRolePermission;
use App\Modules\Core\Authorization\Support\CapabilityToAuthorizationRolePermission;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationSuperAdminRoleSeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_provisions_organization_super_admin_role_with_curated_caps(): void
    {
        (new RolesAndPermissionsSeeder)->run();

        $role = AuthorizationRole::query()->where('name', 'organization_super_admin')->first();
        $this->assertNotNull($role, 'organization_super_admin role must be seeded');
        $this->assertSame('organization', $role->scope_type);
        $this->assertFalse((bool) $role->is_admin_role, 'must be is_admin_role=false to block the admin shortcut');
        $this->assertTrue((bool) $role->is_system);
    }

    public function test_organization_super_admin_pivots_match_the_curated_capability_list(): void
    {
        (new RolesAndPermissionsSeeder)->run();

        $expected = [
            Capability::USERS_VIEW,
            Capability::USERS_CREATE,
            Capability::USERS_EDIT,
            Capability::USERS_DELETE,
            Capability::USERS_ACTIVATE,
            Capability::USERS_DEACTIVATE,
            Capability::USERS_UNLOCK,
            Capability::DEPARTMENTS_VIEW,
            Capability::DEPARTMENTS_CREATE,
            Capability::DEPARTMENTS_EDIT,
            Capability::DEPARTMENTS_DELETE,
            Capability::ORGANIZATION_SETTINGS_VIEW,
            Capability::ORGANIZATION_SETTINGS_EDIT,
            Capability::AUDIT_VIEW,
            Capability::ROLES_VIEW,
            Capability::ROLES_ASSIGN,
        ];

        // Walk the role's stored pivots back through the same prefix
        // mapping the seeder used, and check that every curated
        // capability has a corresponding pivot AND every pivot maps
        // to a curated capability. Direct capability-list comparison
        // via pivot reverse-lookup is unreliable because multiple
        // capability strings can share the same (resource, action)
        // tuple once the prefix table resolves them — e.g.
        // `organization.settings.view` and `dashboard.view` both map
        // to (Organization, view). The pivots are the engine's
        // stored projection of the curated capability list, so a
        // two-direction membership check captures the same intent
        // without the aliasing pitfall.
        $role = AuthorizationRole::query()->where('name', 'organization_super_admin')->firstOrFail();
        $curatedPivots = [];
        foreach ($expected as $capability) {
            $mapping = CapabilityToAuthorizationRolePermission::map($capability);
            if ($mapping === null) {
                $this->fail("Curated capability {$capability} has no pivot mapping.");
            }
            $curatedPivots[] = $mapping['resource'].'::'.$mapping['action'];
        }

        $actualPivots = $role->permissions()
            ->with('resource')
            ->get()
            ->map(fn (AuthorizationRolePermission $permission) => ($permission->resource?->key ?? '').'::'.$permission->action)
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();

        sort($curatedPivots);
        $this->assertSame(
            $curatedPivots,
            $actualPivots,
            'organization_super_admin pivots must equal the curated capability set, exactly.',
        );
    }

    public function test_organization_super_admin_has_no_cluster_tree_or_global_caps(): void
    {
        (new RolesAndPermissionsSeeder)->run();

        $role = AuthorizationRole::query()->where('name', 'organization_super_admin')->firstOrFail();

        // Use the seeder's canonical `roleCatalog()` as the source of truth
        // for what capabilities the role grants. Pivots alone are unreliable
        // for capability-level assertions because multiple capability
        // strings can share the same (resource, action) tuple once the
        // prefix table resolves them — e.g. `audit.view` / `audit.export`
        // both resolve to (ActivityLog, view), and
        // `core.cluster_tree.view` / `organization.settings.view` both
        // resolve to (Organization, view). The catalog is the authoritative
        // input to the seeder; pivots are just the materialized projection.
        $granted = RolesAndPermissionsSeeder::roleCatalog()['organization_super_admin']['capabilities'];

        $forbidden = [
            'core.cluster_tree.view',
            'core.cluster_tree.manage',
            'core.cluster_tree.export',
            'core.view_organizations',
            'core.assign_roles',
            'projects.view',
            'projects.edit',
            'tasks.view',
            'kpis.view',
            'risks.view',
            'ovr.view',
            'audit.export',
            'settings.manage',
        ];
        foreach ($forbidden as $capability) {
            $mapping = CapabilityToAuthorizationRolePermission::map($capability);
            $this->assertNotNull($mapping, "{$capability} must have a pivot mapping");
            $this->assertNotContains(
                $capability,
                $granted,
                "organization_super_admin must not hold {$capability}",
            );
        }
    }
}
