<?php

namespace Tests\Feature\Authz;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Actions\SweepObsoleteOrgSuperOrganizationViewEditPivotsAction;
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

    public function test_sweep_converges_by_deleting_obsolete_pivots_and_writing_exact_audit_rows(): void
    {
        // Seed the role catalog. The seeder inserts the curated pivots;
        // we then inject the obsolete OrgSuper Organization × view/edit
        // pivots that the targeted sweep is meant to remove. The
        // cluster_auditor Organization × view pivot was already inserted
        // by the seeder via the CLUSTER_TREE_VIEW capability and must
        // survive the sweep untouched.
        (new RolesAndPermissionsSeeder)->run();

        $orgSuper = AuthorizationRole::query()->where('name', 'organization_super_admin')->firstOrFail();
        $clusterAuditor = AuthorizationRole::query()->where('name', 'cluster_auditor')->firstOrFail();
        $organizationResourceId = DB::table('authorization_resources')
            ->where('key', Organization::class)
            ->value('id');

        $this->assertNotNull($organizationResourceId, 'precondition: Organization resource row must exist.');

        // The cluster_auditor Organization × view pivot is added by the
        // seeder via the CLUSTER_TREE_VIEW capability mapped to the
        // `Organization` resource. Assert it is present BEFORE the sweep
        // so the post-sweep preservation assertion is anchored to a
        // real pivot count (not zero).
        $clusterAuditorViewBefore = DB::table('authorization_role_permissions')
            ->where('authorization_role_id', $clusterAuditor->id)
            ->where('authorization_resource_id', $organizationResourceId)
            ->where('action', 'view')
            ->count();

        $this->assertSame(1, $clusterAuditorViewBefore, 'precondition: seeder must have produced the cluster_auditor Organization × view pivot before the sweep.');

        // Inject the 2 obsolete OrgSuper Organization × view/edit pivots
        // (must be deleted by the sweep).
        $this->seedObsoleteOrgSuperOrganizationViewEditPivots($orgSuper->id, $organizationResourceId);

        $auditBefore = $this->countSweepAuditRows();

        // Run the same engine the migration ships — calling the action
        // directly lets this test assert deletion + exact audit + role
        // preservation after seeding pivots post-RefreshDatabase.
        SweepObsoleteOrgSuperOrganizationViewEditPivotsAction::execute();

        // Assertion 1: obsolete OrgSuper Organization × view/edit pivots are gone.
        $this->assertSame(
            0,
            DB::table('authorization_role_permissions')
                ->where('authorization_role_id', $orgSuper->id)
                ->where('authorization_resource_id', $organizationResourceId)
                ->whereIn('action', SweepObsoleteOrgSuperOrganizationViewEditPivotsAction::TARGET_ACTIONS)
                ->count(),
            'Sweep must have deleted the obsolete OrgSuper Organization view/edit pivots.'
        );

        // Assertion 2 (preservation): the cluster_auditor Organization ×
        // view pivot must be untouched. cluster_auditor is the role whose
        // legitimate cluster_tree view capability maps to Organization ×
        // view and is the canary for "did we sweep too much?".
        $this->assertSame(
            $clusterAuditorViewBefore,
            DB::table('authorization_role_permissions')
                ->where('authorization_role_id', $clusterAuditor->id)
                ->where('authorization_resource_id', $organizationResourceId)
                ->where('action', 'view')
                ->count(),
            "Sweep must NOT touch cluster_auditor's legitimate Organization × view pivot."
        );

        // Assertion 3: exactly 2 audit rows were written (one per pivot
        // swept). Anything more would mean the audit skip incorrectly
        // triggered; anything less would mean a pivot deletion was not
        // mirrored by its audit (the bug the atomic delete+audit fix
        // closes).
        $this->assertSame(
            $auditBefore + 2,
            $this->countSweepAuditRows(),
            'Sweep must write exactly 2 audit rows (one for view, one for edit).'
        );
    }

    public function test_sweep_on_rerun_deletes_recreated_pivots_without_duplicate_audit(): void
    {
        // Convergent re-run: if the operator re-runs RolesAndPermissionsSeeder
        // between two migration executions, a recreated obsolete pivot must
        // be re-deleted (convergence) but no duplicate audit row must be
        // written (idempotency on the audit side). This is the scenario
        // the original migration's `continue` short-circuit got wrong.
        (new RolesAndPermissionsSeeder)->run();

        $orgSuper = AuthorizationRole::query()->where('name', 'organization_super_admin')->firstOrFail();
        $organizationResourceId = DB::table('authorization_resources')
            ->where('key', Organization::class)
            ->value('id');

        $this->seedObsoleteOrgSuperOrganizationViewEditPivots($orgSuper->id, $organizationResourceId);

        // First run: deletes both pivots, writes 2 fresh audit rows.
        SweepObsoleteOrgSuperOrganizationViewEditPivotsAction::execute();

        $auditAfterFirst = $this->countSweepAuditRows();

        $this->assertSame(
            0,
            DB::table('authorization_role_permissions')
                ->where('authorization_role_id', $orgSuper->id)
                ->where('authorization_resource_id', $organizationResourceId)
                ->whereIn('action', SweepObsoleteOrgSuperOrganizationViewEditPivotsAction::TARGET_ACTIONS)
                ->count(),
            'first run must have removed the obsolete pivots.'
        );

        // Operator re-runs the seeder, which (hypothetically) re-creates
        // the obsolete pivots before the next migration execution.
        $this->seedObsoleteOrgSuperOrganizationViewEditPivots($orgSuper->id, $organizationResourceId);

        $pivotsBeforeRerun = DB::table('authorization_role_permissions')
            ->where('authorization_role_id', $orgSuper->id)
            ->where('authorization_resource_id', $organizationResourceId)
            ->whereIn('action', SweepObsoleteOrgSuperOrganizationViewEditPivotsAction::TARGET_ACTIONS)
            ->count();

        $this->assertSame(
            2,
            $pivotsBeforeRerun,
            'precondition: 2 recreated obsolete pivots must be present before the re-run.'
        );

        // Second run: deletes the recreated pivots AGAIN (convergence),
        // but must NOT write duplicate audit rows (idempotency).
        SweepObsoleteOrgSuperOrganizationViewEditPivotsAction::execute();

        $auditAfterRerun = $this->countSweepAuditRows();

        $this->assertSame(
            $auditAfterFirst,
            $auditAfterRerun,
            'Re-run must NOT write duplicate audit rows for previously-swept pivots.'
        );

        $this->assertSame(
            0,
            DB::table('authorization_role_permissions')
                ->where('authorization_role_id', $orgSuper->id)
                ->where('authorization_resource_id', $organizationResourceId)
                ->whereIn('action', SweepObsoleteOrgSuperOrganizationViewEditPivotsAction::TARGET_ACTIONS)
                ->count(),
            'Re-run MUST delete recreated obsolete pivots (convergence).'
        );
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

    /**
     * Insert the two obsolete OrgSuper Organization × view/edit pivots.
     * Used by both the "convergence on first run" and the "convergence on
     * rerun" tests.
     */
    private function seedObsoleteOrgSuperOrganizationViewEditPivots(int $orgSuperRoleId, int $organizationResourceId): void
    {
        DB::table('authorization_role_permissions')->insert([
            [
                'authorization_role_id' => $orgSuperRoleId,
                'authorization_resource_id' => $organizationResourceId,
                'action' => 'view',
            ],
            [
                'authorization_role_id' => $orgSuperRoleId,
                'authorization_resource_id' => $organizationResourceId,
                'action' => 'edit',
            ],
        ]);
    }

    private function countSweepAuditRows(): int
    {
        return (int) DB::table('authorization_assignment_audits')
            ->where('event', SweepObsoleteOrgSuperOrganizationViewEditPivotsAction::AUDIT_EVENT)
            ->whereRaw("new_value ->> 'migration' = ?", [SweepObsoleteOrgSuperOrganizationViewEditPivotsAction::MIGRATION_NAME])
            ->count();
    }
}
