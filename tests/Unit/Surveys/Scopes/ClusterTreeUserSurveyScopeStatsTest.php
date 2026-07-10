<?php

namespace Tests\Unit\Surveys\Scopes;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Surveys\Models\Survey;
use App\Modules\Surveys\Models\SurveyResponse;
use App\Modules\Surveys\Scopes\UserSurveyScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * ClusterTreeUserSurveyScopeStatsTest - Phase CFA-10.
 *
 * Proves the UserSurveyScope::clusterVisibleOrgIds() widening contract for
 * the cluster aggregate stats endpoint.
 *
 * Strict CFA-00 contract:
 *   - cluster user with SURVEYS_VIEW + CLUSTER_TREE_VIEW ⇒ widens to descendants
 *   - cluster user WITHOUT CLUSTER_TREE_VIEW ⇒ strict same-org (single id)
 *   - cluster user WITHOUT SURVEYS_VIEW ⇒ strict same-org (single id)
 *   - super_admin ⇒ empty list (caller chooses how to enumerate)
 *   - null-org user ⇒ empty list (fail-closed)
 *   - sibling cluster ⇒ strict same-org (no cross-cluster leakage)
 *   - child user ⇒ strict same-org (one-directional: ancestor ⇒ descendants)
 *
 * The cluster widening for stats applies ONLY at the scope layer; the
 * per-survey strict methods (`applyToSurveys` / `applyToSurveyResponses`)
 * stay unchanged so raw response reads remain strict same-org.
 */
class ClusterTreeUserSurveyScopeStatsTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    private UserSurveyScope $scope;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scope = new UserSurveyScope;
    }

    /**
     * @return array{0: Organization, 1: Organization, 2: Organization}
     */
    private function makeClusterTree(string $clusterName = 'cluster', string $hospitalName = 'hospital'): array
    {
        $cluster = Organization::factory()->cluster()->create(['name' => $clusterName]);
        $hospital = Organization::factory()->hospital()->childOf($cluster)->create(['name' => $hospitalName]);
        $other = Organization::factory()->create(['name' => 'sibling of '.$hospitalName]);

        return [$cluster, $hospital, $other];
    }

    // ──────────────────────────────────────────────────────────────
    // positive — both grants widen
    // ──────────────────────────────────────────────────────────────

    public function test_cluster_user_with_both_grants_gets_descendant_org_ids(): void
    {
        [$cluster, $hospital, $other] = $this->makeClusterTree();

        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, [
            Capability::SURVEYS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $ids = $this->scope->clusterVisibleOrgIds($user);

        // cluster + hospital (descendants only — no sibling).
        $this->assertContains($cluster->id, $ids);
        $this->assertContains($hospital->id, $ids);
        $this->assertNotContains($other->id, $ids, 'sibling must be excluded from cluster widening');
    }

    // ──────────────────────────────────────────────────────────────
    // negative — missing one grant ⇒ strict same-org
    // ──────────────────────────────────────────────────────────────

    public function test_cluster_user_without_cluster_tree_view_sees_only_own_org(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, Capability::SURVEYS_VIEW);

        $ids = $this->scope->clusterVisibleOrgIds($user);

        $this->assertSame([$cluster->id], $ids, 'SURVEYS_VIEW alone must NOT widen (CLUSTER_TREE_VIEW required)');
        $this->assertNotContains($hospital->id, $ids);
    }

    public function test_cluster_user_without_surveys_view_sees_only_own_org(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, Capability::CLUSTER_TREE_VIEW);

        $ids = $this->scope->clusterVisibleOrgIds($user);

        $this->assertSame([$cluster->id], $ids, 'CLUSTER_TREE_VIEW alone must NOT widen (SURVEYS_VIEW required)');
        $this->assertNotContains($hospital->id, $ids);
    }

    public function test_user_without_any_grants_sees_only_own_org(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        // No grants at all.

        $ids = $this->scope->clusterVisibleOrgIds($user);

        $this->assertSame([$cluster->id], $ids);
        $this->assertNotContains($hospital->id, $ids);
    }

    // ──────────────────────────────────────────────────────────────
    // negative — sibling / child isolation
    // ──────────────────────────────────────────────────────────────

    public function test_sibling_cluster_isolated_no_cross_cluster_widening(): void
    {
        [$clusterA, $hospitalA] = $this->makeClusterTree('cluster A', 'hospital A');
        [$clusterB, $hospitalB] = $this->makeClusterTree('cluster B', 'hospital B');

        $user = User::factory()->create([
            'organization_id' => $clusterA->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, [
            Capability::SURVEYS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $ids = $this->scope->clusterVisibleOrgIds($user);

        $this->assertContains($clusterA->id, $ids);
        $this->assertContains($hospitalA->id, $ids);
        $this->assertNotContains($clusterB->id, $ids);
        $this->assertNotContains($hospitalB->id, $ids);
    }

    public function test_child_user_one_directional_no_widening_upward(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        // child user (hospital) with both grants — must NOT widen upward to parent.
        $childUser = User::factory()->create([
            'organization_id' => $hospital->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($childUser, [
            Capability::SURVEYS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $ids = $this->scope->clusterVisibleOrgIds($childUser);

        $this->assertSame([$hospital->id], $ids);
        $this->assertNotContains($cluster->id, $ids, 'child user must NOT see parent cluster via cluster widening');
    }

    // ──────────────────────────────────────────────────────────────
    // floors — super_admin / null-org / strict modes stay intact
    // ──────────────────────────────────────────────────────────────

    public function test_super_admin_gets_empty_list_caller_enumerates(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $superAdmin = User::factory()->create([
            'organization_id' => null,
            'is_active' => true,
        ]);
        $superAdmin->assignRole('super_admin');

        $ids = $this->scope->clusterVisibleOrgIds($superAdmin);

        // The scope intentionally returns [] for super_admin — the controller
        // translates this to "enumerate every organization".
        $this->assertSame([], $ids);
    }

    public function test_null_org_user_gets_empty_list_fail_closed(): void
    {
        [$cluster] = $this->makeClusterTree();

        $user = User::factory()->create([
            'organization_id' => null,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, [
            Capability::SURVEYS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $ids = $this->scope->clusterVisibleOrgIds($user);

        $this->assertSame([], $ids, 'null-org user must fail-closed on cluster widening');
    }

    // ──────────────────────────────────────────────────────────────
    // REGRESSION — strict applyTo* methods stay same-org
    // ──────────────────────────────────────────────────────────────

    public function test_apply_to_surveys_stays_strict_no_cluster_widening(): void
    {
        // CRITICAL: the cluster widening applies ONLY to clusterVisibleOrgIds
        // (the stats path). The strict per-survey filter must NOT widen, even
        // with both grants — that's how raw response reads stay safe.
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, [
            Capability::SURVEYS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        Survey::factory()->create(['organization_id' => $cluster->id]);
        Survey::factory()->create(['organization_id' => $cluster->id]);
        Survey::factory()->create(['organization_id' => $hospital->id]);
        Survey::factory()->create(['organization_id' => $hospital->id]);
        Survey::factory()->create(['organization_id' => $hospital->id]);

        $query = Survey::query();
        $this->scope->applyToSurveys($query, $user);

        $this->assertSame(
            2,
            (clone $query)->count(),
            'applyToSurveys must remain strict same-org even with cluster_tree grants'
        );
    }

    public function test_apply_to_survey_responses_stays_strict_no_cluster_widening(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, [
            Capability::SURVEYS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $clusterSurvey = Survey::factory()->create(['organization_id' => $cluster->id]);
        $hospitalSurvey = Survey::factory()->create(['organization_id' => $hospital->id]);

        SurveyResponse::factory()->count(2)->create(['survey_id' => $clusterSurvey->id]);
        SurveyResponse::factory()->count(3)->create(['survey_id' => $hospitalSurvey->id]);

        $query = SurveyResponse::query();
        $this->scope->applyToSurveyResponses($query, $user);

        $this->assertSame(
            2,
            (clone $query)->count(),
            'applyToSurveyResponses must remain strict same-org even with cluster_tree grants'
        );
    }
}
