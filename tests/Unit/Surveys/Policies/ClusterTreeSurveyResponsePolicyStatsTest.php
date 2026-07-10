<?php

namespace Tests\Unit\Surveys\Policies;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Surveys\Models\Survey;
use App\Modules\Surveys\Models\SurveyResponse;
use App\Modules\Surveys\Policies\SurveyResponsePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * ClusterTreeSurveyResponsePolicyStatsTest - Phase CFA-10.
 *
 * Proves the SurveyResponsePolicy::viewStats() two-path rescue contract:
 *
 *   1. cluster user with SURVEYS_VIEW + CLUSTER_TREE_VIEW on actor.org
 *      ⇒ viewStats(child-org survey) = true
 *   2. cluster user WITHOUT CLUSTER_TREE_VIEW ⇒ viewStats = false
 *      (one-path missing ⇒ deny; SURVEYS_VIEW alone is not enough)
 *   3. cluster user WITHOUT SURVEYS_VIEW ⇒ viewStats = false
 *      (the module capability is the floor; cluster_tree alone is not enough)
 *   4. sibling cluster ⇒ viewStats = false (cross-org isolation holds)
 *   5. child user (descendant org) ⇒ viewStats(parent-cluster survey) = false
 *      (cluster widening is one-directional: ancestor ⇒ descendants only)
 *   6. super_admin bypasses everything
 *   7. null-org actor ⇒ viewStats = false (fail-closed floor)
 *   8. review() STAYS strict same-org (regression: cluster widening does NOT
 *      leak into the raw per-response review path)
 *
 * Critical CFA-00 stop conditions pinned by these tests:
 *   - No raw response widening (review() unchanged)
 *   - No respondent PII leakage (viewStats is a survey-level aggregate gate)
 *   - Two-path grant (BOTH capabilities required)
 */
class ClusterTreeSurveyResponsePolicyStatsTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    private SurveyResponsePolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new SurveyResponsePolicy;
    }

    /**
     * @return array{0: Organization, 1: Organization, 2: Organization}
     */
    private function makeClusterTree(string $clusterName = 'cluster', string $hospitalName = 'hospital'): array
    {
        $cluster = Organization::factory()->cluster()->create(['name' => $clusterName]);
        $hospital = Organization::factory()->hospital()->childOf($cluster)->create(['name' => $hospitalName]);
        $sibling = Organization::factory()->create(['name' => 'sibling of '.$hospitalName]);

        return [$cluster, $hospital, $sibling];
    }

    private function makeSurveyWithOrg(?int $orgId): Survey
    {
        return Survey::factory()->create(['organization_id' => $orgId]);
    }

    // ──────────────────────────────────────────────────────────────
    // viewStats — positive paths
    // ──────────────────────────────────────────────────────────────

    public function test_cluster_user_with_both_grants_can_view_stats_for_child_org_survey(): void
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

        $childSurvey = $this->makeSurveyWithOrg($hospital->id);

        $this->assertTrue($this->policy->viewStats($user, $childSurvey));
    }

    public function test_same_org_user_with_surveys_view_can_view_stats_without_cluster_tree(): void
    {
        // Same-org path does NOT require CLUSTER_TREE_VIEW.
        [$cluster] = $this->makeClusterTree();

        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, Capability::SURVEYS_VIEW);

        $sameOrgSurvey = $this->makeSurveyWithOrg($cluster->id);

        $this->assertTrue($this->policy->viewStats($user, $sameOrgSurvey));
    }

    // ──────────────────────────────────────────────────────────────
    // viewStats — missing one of the two grants ⇒ deny
    // ──────────────────────────────────────────────────────────────

    public function test_cluster_user_without_cluster_tree_view_cannot_view_stats_for_child_org_survey(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        // SURVEYS_VIEW only — CLUSTER_TREE_VIEW missing ⇒ two-path deny.
        $this->grantEngineCapability($user, Capability::SURVEYS_VIEW);

        $childSurvey = $this->makeSurveyWithOrg($hospital->id);

        $this->assertFalse($this->policy->viewStats($user, $childSurvey));
    }

    public function test_cluster_user_without_surveys_view_cannot_view_stats_for_child_org_survey(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        // CLUSTER_TREE_VIEW only — SURVEYS_VIEW missing ⇒ two-path deny.
        $this->grantEngineCapability($user, Capability::CLUSTER_TREE_VIEW);

        $childSurvey = $this->makeSurveyWithOrg($hospital->id);

        $this->assertFalse($this->policy->viewStats($user, $childSurvey));
    }

    // ──────────────────────────────────────────────────────────────
    // viewStats — sibling / child / unrelated isolation
    // ──────────────────────────────────────────────────────────────

    public function test_sibling_cluster_cannot_view_stats_for_other_cluster_child_survey(): void
    {
        [$clusterA, $hospitalA] = $this->makeClusterTree('cluster A', 'hospital A');
        [$clusterB] = $this->makeClusterTree('cluster B', 'hospital B');

        $user = User::factory()->create([
            'organization_id' => $clusterB->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, [
            Capability::SURVEYS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $unrelatedChildSurvey = $this->makeSurveyWithOrg($hospitalA->id);

        // clusterB is a sibling of clusterA — not an ancestor of hospitalA.
        $this->assertFalse($this->policy->viewStats($user, $unrelatedChildSurvey));
    }

    public function test_child_user_cannot_view_stats_for_parent_cluster_survey_via_cluster_tree(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        // child user (hospital) — must NOT widen to parent cluster.
        $childUser = User::factory()->create([
            'organization_id' => $hospital->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($childUser, [
            Capability::SURVEYS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $parentSurvey = $this->makeSurveyWithOrg($cluster->id);

        $this->assertFalse($this->policy->viewStats($childUser, $parentSurvey));
    }

    public function test_unrelated_org_outside_cluster_cannot_view_stats_even_with_grants(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();
        $outsider = Organization::factory()->create(['name' => 'unrelated org']);

        $user = User::factory()->create([
            'organization_id' => $outsider->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, [
            Capability::SURVEYS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $childSurvey = $this->makeSurveyWithOrg($hospital->id);

        // outsider is neither ancestor nor same-org as hospital.
        $this->assertFalse($this->policy->viewStats($user, $childSurvey));
    }

    // ──────────────────────────────────────────────────────────────
    // viewStats — super_admin + null-org floors
    // ──────────────────────────────────────────────────────────────

    public function test_super_admin_bypasses_view_stats_everything(): void
    {
        [, $hospital] = $this->makeClusterTree();

        $superAdmin = User::factory()->create([
            'organization_id' => null,
            'is_active' => true,
        ]);
        $superAdmin->assignRole('super_admin');

        $childSurvey = $this->makeSurveyWithOrg($hospital->id);

        $this->assertTrue($this->policy->viewStats($superAdmin, $childSurvey));
    }

    public function test_null_org_user_cannot_view_stats_with_cluster_grants(): void
    {
        [, $hospital] = $this->makeClusterTree();

        $nullOrgUser = User::factory()->create([
            'organization_id' => null,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($nullOrgUser, [
            Capability::SURVEYS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $childSurvey = $this->makeSurveyWithOrg($hospital->id);

        $this->assertFalse($this->policy->viewStats($nullOrgUser, $childSurvey));
    }

    public function test_null_org_target_survey_fails_closed(): void
    {
        $cluster = Organization::factory()->cluster()->create();

        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, [
            Capability::SURVEYS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $nullOrgSurvey = $this->makeSurveyWithOrg(null);

        $this->assertFalse($this->policy->viewStats($user, $nullOrgSurvey));
    }

    // ──────────────────────────────────────────────────────────────
    // REGRESSION — review() stays strict same-org
    // ──────────────────────────────────────────────────────────────

    public function test_review_stays_org_strict_no_cluster_widening(): void
    {
        // CRITICAL CFA-00 stop condition: the cluster widening for viewStats
        // must NEVER leak into the raw per-response review path. Even with
        // both grants, review() on a descendant-org response must remain
        // strict same-org (deny).
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, [
            Capability::SURVEYS_REVIEW_RESPONSES,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $childSurvey = $this->makeSurveyWithOrg($hospital->id);
        $childResponse = SurveyResponse::factory()->create([
            'survey_id' => $childSurvey->id,
        ]);

        // Even though viewStats() widens, review() must NOT.
        $this->assertFalse($this->policy->review($user, $childResponse));
    }

    public function test_view_stats_works_for_same_org_even_without_cluster_tree_grant(): void
    {
        // Same-org path: SURVEYS_VIEW alone is sufficient (cluster_tree is
        // an additional rescue, not a strict requirement for same-org).
        $cluster = Organization::factory()->cluster()->create();

        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, Capability::SURVEYS_VIEW);

        $sameOrgSurvey = $this->makeSurveyWithOrg($cluster->id);

        $this->assertTrue($this->policy->viewStats($user, $sameOrgSurvey));
    }
}
