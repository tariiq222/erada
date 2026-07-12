<?php

namespace Tests\Feature\Authorization;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\DepartmentCapacityRole;
use App\Modules\HR\Services\ScopedDepartmentRoleSyncService;
use App\Modules\Projects\Models\Project;
use App\Modules\RiskManagement\Models\Risk;
use App\Modules\RiskManagement\Models\RiskAssessment;
use App\Modules\Strategy\Models\Blocker;
use App\Modules\Strategy\Models\Review;
use App\Modules\Surveys\Models\DataImportRequest;
use App\Modules\Surveys\Models\Survey;
use App\Modules\Surveys\Models\SurveyResponse;
use Database\Seeders\ScopedDepartmentRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase51ScopeCoverageTest — verifies that the four operational models brought
 * into the scope system in Phase 5.1 (Blocker, Review, RiskAssessment,
 * DataImportRequest) are governed by the unified AuthZ engine through their parent
 * chain.
 *
 * For each model two invariants are asserted:
 *  - CHAIN VISIBILITY: a department manager can view a record that rolls up (via
 *    its parent) into their department, vertically through the ascending chain.
 *  - ISOLATION + NEED-TO-KNOW: a manager of a department in another organization is
 *    denied (org isolation), and a manager of a sibling department in the SAME
 *    organization is denied (no role on that branch).
 */
class Phase51ScopeCoverageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ScopedDepartmentRolesSeeder::class);
    }

    /**
     * Build a department whose manager holds the seeded dept_manager scoped role.
     *
     * @return array{0: Organization, 1: Department, 2: User}
     */
    private function departmentWithManager(?Organization $org = null): array
    {
        $org ??= Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        DepartmentCapacityRole::create([
            'department_id' => $dept->id,
            'capacity' => 'manager',
            'role_key' => 'dept_manager',
        ]);

        $mgr = User::factory()->create(['organization_id' => $org->id]);
        $dept->update(['manager_id' => $mgr->id]);
        app(ScopedDepartmentRoleSyncService::class)->syncDepartment($dept->fresh());

        return [$org, $dept, $mgr->fresh()];
    }

    /**
     * Build a Blocker inline (no factory exists), against the real columns.
     */
    private function makeBlocker(string $blockableType, int $blockableId, ?int $organizationId): Blocker
    {
        $blocker = new Blocker([
            'title' => 'Phase 5.1 coverage blocker',
            'blockable_type' => $blockableType,
            'blockable_id' => $blockableId,
            'organization_id' => $organizationId,
            'severity' => Blocker::SEVERITY_HIGH,
            'status' => Blocker::STATUS_OPEN,
            'identified_date' => now()->toDateString(),
        ]);
        $blocker->save();

        return $blocker;
    }

    /**
     * Build a Review inline (no factory exists), against the real columns.
     */
    private function makeReview(string $reviewableType, int $reviewableId, ?int $organizationId): Review
    {
        $review = new Review([
            'title' => 'Phase 5.1 coverage review',
            'reviewable_type' => $reviewableType,
            'reviewable_id' => $reviewableId,
            'organization_id' => $organizationId,
            'type' => Review::TYPE_QUARTERLY,
            'pdca_phase' => Review::PDCA_CHECK,
            'review_date' => now()->toDateString(),
            'period_start' => now()->subMonths(3)->toDateString(),
            'period_end' => now()->toDateString(),
            'overall_status' => Review::STATUS_ON_TRACK,
        ]);
        $review->save();

        return $review;
    }

    // ========================================================
    // RiskAssessment — rolls up to its parent Risk (Risk.department_id)
    // ========================================================

    public function test_department_manager_can_view_risk_assessment_through_parent_risk(): void
    {
        [$org, $dept, $mgr] = $this->departmentWithManager();

        $risk = Risk::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
        ]);
        $assessment = RiskAssessment::factory()->create([
            'risk_id' => $risk->id,
            'organization_id' => $org->id,
        ]);

        $this->assertTrue(
            AccessDecision::can($mgr, Capability::RISKS_VIEW, $assessment),
            'Department manager should view a risk assessment that rolls up to a risk in their department.'
        );
    }

    public function test_risk_assessment_denied_cross_org_and_sibling_branch(): void
    {
        [$org, $dept, $mgr] = $this->departmentWithManager();

        $risk = Risk::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
        ]);
        $assessment = RiskAssessment::factory()->create([
            'risk_id' => $risk->id,
            'organization_id' => $org->id,
        ]);

        // Cross-org: a manager in a different organization is denied (org isolation).
        [, , $otherOrgMgr] = $this->departmentWithManager();
        $this->assertFalse(
            AccessDecision::can($otherOrgMgr, Capability::RISKS_VIEW, $assessment),
            'A manager from another organization must not view this risk assessment.'
        );

        // Sibling branch (same org, different department): denied (need-to-know).
        [, , $siblingMgr] = $this->departmentWithManager($org);
        $this->assertFalse(
            AccessDecision::can($siblingMgr, Capability::RISKS_VIEW, $assessment),
            'A manager of a sibling department must not view this risk assessment.'
        );
    }

    // ========================================================
    // Blocker — polymorphic blockable (here: a Project in the department)
    // ========================================================

    public function test_department_manager_can_view_blocker_through_project_chain(): void
    {
        [$org, $dept, $mgr] = $this->departmentWithManager();

        $project = Project::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
        ]);
        $blocker = $this->makeBlocker(Project::class, $project->id, $org->id);

        $this->assertTrue(
            AccessDecision::can($mgr, Capability::STRATEGY_VIEW, $blocker),
            'Department manager should view a blocker attached to a project in their department.'
        );
    }

    public function test_blocker_denied_cross_org_and_sibling_branch(): void
    {
        [$org, $dept, $mgr] = $this->departmentWithManager();

        $project = Project::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
        ]);
        $blocker = $this->makeBlocker(Project::class, $project->id, $org->id);

        [, , $otherOrgMgr] = $this->departmentWithManager();
        $this->assertFalse(
            AccessDecision::can($otherOrgMgr, Capability::STRATEGY_VIEW, $blocker),
            'A manager from another organization must not view this blocker.'
        );

        [, , $siblingMgr] = $this->departmentWithManager($org);
        $this->assertFalse(
            AccessDecision::can($siblingMgr, Capability::STRATEGY_VIEW, $blocker),
            'A manager of a sibling department must not view this blocker.'
        );
    }

    public function test_blocker_with_unresolvable_polymorph_is_governed_by_org_only(): void
    {
        // Polymorphic safety: a legacy / dropped target type must not crash the
        // engine. The record is then governed by its own organization_id alone.
        [$org, $dept, $mgr] = $this->departmentWithManager();

        $blocker = $this->makeBlocker('App\\Modules\\Strategy\\Models\\StrategicObjective', 999999, $org->id);

        // No exception thrown, and no department-vertical role grants it (the chain
        // cannot ascend past the unresolved polymorph), so a dept manager is denied.
        $this->assertFalse(
            AccessDecision::can($mgr, Capability::STRATEGY_VIEW, $blocker),
            'A blocker on an unresolvable polymorph must not leak via the department chain.'
        );

        // Cross-org isolation still holds via the own organization_id.
        [, , $otherOrgMgr] = $this->departmentWithManager();
        $this->assertFalse(
            AccessDecision::can($otherOrgMgr, Capability::STRATEGY_VIEW, $blocker),
            'Cross-org isolation must hold for a blocker with an unresolvable polymorph.'
        );
    }

    // ========================================================
    // Review — polymorphic reviewable (here: a Project in the department)
    // ========================================================

    public function test_department_manager_can_view_review_through_project_chain(): void
    {
        [$org, $dept, $mgr] = $this->departmentWithManager();

        $project = Project::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
        ]);
        $review = $this->makeReview(Project::class, $project->id, $org->id);

        $this->assertTrue(
            AccessDecision::can($mgr, Capability::STRATEGY_VIEW, $review),
            'Department manager should view a review attached to a project in their department.'
        );
    }

    public function test_review_denied_cross_org_and_sibling_branch(): void
    {
        [$org, $dept, $mgr] = $this->departmentWithManager();

        $project = Project::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
        ]);
        $review = $this->makeReview(Project::class, $project->id, $org->id);

        [, , $otherOrgMgr] = $this->departmentWithManager();
        $this->assertFalse(
            AccessDecision::can($otherOrgMgr, Capability::STRATEGY_VIEW, $review),
            'A manager from another organization must not view this review.'
        );

        [, , $siblingMgr] = $this->departmentWithManager($org);
        $this->assertFalse(
            AccessDecision::can($siblingMgr, Capability::STRATEGY_VIEW, $review),
            'A manager of a sibling department must not view this review.'
        );
    }

    public function test_review_on_legacy_objective_is_governed_by_org_only(): void
    {
        // The StrategicObjective backing table was dropped; reviews on objectives
        // must be governed by organization_id alone, never crash the engine.
        [$org, $dept, $mgr] = $this->departmentWithManager();

        $review = $this->makeReview('App\\Modules\\Strategy\\Models\\StrategicObjective', 999999, $org->id);

        $this->assertFalse(
            AccessDecision::can($mgr, Capability::STRATEGY_VIEW, $review),
            'A review on a legacy objective must not leak via the department chain.'
        );

        [, , $otherOrgMgr] = $this->departmentWithManager();
        $this->assertFalse(
            AccessDecision::can($otherOrgMgr, Capability::STRATEGY_VIEW, $review),
            'Cross-org isolation must hold for a review on a legacy objective.'
        );
    }

    // ========================================================
    // DataImportRequest — rolls up via SurveyResponse -> Survey (no own org column)
    // ========================================================

    public function test_department_manager_can_view_data_import_request_through_survey_chain(): void
    {
        [$org, $dept, $mgr] = $this->departmentWithManager();

        $survey = Survey::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
        ]);
        $response = SurveyResponse::factory()->create(['survey_id' => $survey->id]);
        $request = DataImportRequest::factory()->create(['response_id' => $response->id]);

        $this->assertTrue(
            AccessDecision::can($mgr, Capability::SURVEYS_VIEW, $request),
            'Department manager should view an import request that rolls up to a survey in their department.'
        );
    }

    public function test_data_import_request_denied_cross_org_and_sibling_branch(): void
    {
        [$org, $dept, $mgr] = $this->departmentWithManager();

        $survey = Survey::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
        ]);
        $response = SurveyResponse::factory()->create(['survey_id' => $survey->id]);
        $request = DataImportRequest::factory()->create(['response_id' => $response->id]);

        [, , $otherOrgMgr] = $this->departmentWithManager();
        $this->assertFalse(
            AccessDecision::can($otherOrgMgr, Capability::SURVEYS_VIEW, $request),
            'A manager from another organization must not view this import request.'
        );

        [, , $siblingMgr] = $this->departmentWithManager($org);
        $this->assertFalse(
            AccessDecision::can($siblingMgr, Capability::SURVEYS_VIEW, $request),
            'A manager of a sibling department must not view this import request.'
        );
    }
}
