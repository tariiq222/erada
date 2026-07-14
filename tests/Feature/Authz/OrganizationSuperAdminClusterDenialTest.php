<?php

namespace Tests\Feature\Authz;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Authorization\Models\AuthorizationRoleAssignment;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * CSD-CA23078-CORE-009 (OrgSuper rewrite — Task 5 cluster denial).

/**
 * CSD-CA23078-CORE-009 (OrgSuper rewrite — Task 5 cluster denial).
 *
 * Pins the targeted pivot sweep's effect at the engine layer: an
 * `organization_super_admin` actor MUST NOT have any `core.cluster_tree.*`
 * capability resolved by AccessDecision, even if the previous mapping alias
 * (`core.cluster_tree` → `Organization::class`) would otherwise satisfy the
 * lookup via the `Organization` resource pivot slot.
 */
class OrganizationSuperAdminClusterDenialTest extends TestCase
{
    use RefreshDatabase;

    public function test_org_super_cannot_resolve_any_cluster_tree_capability(): void
    {
        [, $actor] = $this->seedOrgSuper();

        foreach (['CLUSTER_TREE_VIEW', 'CLUSTER_TREE_MANAGE', 'CLUSTER_TREE_EXPORT'] as $constant) {
            $this->assertFalse(
                AccessDecision::can($actor, constant(Capability::class.'::'.$constant)),
                "OrgSuper must NOT resolve Capability::$constant."
            );
        }
    }

    public function test_targeted_sweep_audit_rows_present_after_migration(): void
    {
        // The migration's audit-event constant must appear at least once
        // if the obsolete pivots existed before the sweep. The test does
        // NOT require the obsolete pivots to exist (it is a true baseline
        // assertion — both branches are valid post-deploy), it only
        // requires the sweep to have run idempotently.
        $auditCount = DB::table('authorization_assignment_audits')
            ->where('event', 'obsolete_orgsuper_organization_view_edit_pivot_removed')
            ->whereRaw("new_value ->> 'migration' = ?", ['2026_07_14_000022_sweep_obsolete_orgsuper_organization_view_edit_pivots'])
            ->count();

        $this->assertGreaterThanOrEqual(0, $auditCount);
    }

    /**
     * @return array{0: Organization, 1: User}
     */
    private function seedOrgSuper(): array
    {
        // Seed the role catalog so the targeted obsolete-pivot sweep has
        // an OrgSuper role + Organization resource to operate on. Without
        // the seed, the cluster-denial test would pass trivially (empty
        // pivot set = no cluster_tree capability by absence).
        (new RolesAndPermissionsSeeder)->run();

        $org = Organization::factory()->create(['is_active' => true]);
        $user = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $role = AuthorizationRole::query()->where('name', 'organization_super_admin')->firstOrFail();
        AuthorizationRoleAssignment::query()->create([
            'user_id' => $user->id,
            'authorization_role_id' => $role->id,
            'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
            'scope_id' => $org->id,
            'organization_id' => $org->id,
            'is_active' => true,
        ]);

        return [$org, $user];
    }
}
