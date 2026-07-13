<?php

namespace Tests\Feature\Core;

use App\Modules\Core\Models\GovernanceRule;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\OVR\Models\OvrSetting;
use App\Modules\OVR\Services\OvrAuthorizationService;
use App\Modules\Projects\Models\ProjectSetting;
use App\Modules\Projects\Services\ProjectAuthorizationService;
use App\Modules\RiskManagement\Models\RiskSetting;
use App\Modules\RiskManagement\Services\RiskAuthorizationService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Phase 1 of ADR-UNIFIED-ROLE-ACCESS: the three per-module governing-department
 * mechanisms now read from the single governance_rules table via thin Setting
 * shims. This proves the SAME service methods still elevate a governing-department
 * member org-wide, that project governance resolves per type, and that an unset
 * governor grants no elevation.
 */
class GovernanceRulesTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Department $memberDept;

    private Department $governingDept;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Cache::flush();

        $this->org = Organization::factory()->create();
        $this->memberDept = $this->makeDept('GR-MEMBER');
        $this->governingDept = $this->makeDept('GR-GOV');
    }

    private function makeDept(string $code): Department
    {
        return Department::factory()->create([
            'code' => $code.'-'.uniqid(),
            'organization_id' => $this->org->id,
            'parent_id' => null,
            'level' => Department::LEVEL_DEPARTMENT,
            'is_active' => true,
        ]);
    }

    private function governingMember(): User
    {
        $user = User::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $this->governingDept->id,
            'is_active' => true,
        ]);
        $this->assignCanonicalRole($user, 'dept_member', 'department', $this->governingDept->id);
        Cache::flush();

        return $user;
    }

    // (a) governing-department member gets org-wide view via the SAME service methods.

    public function test_setter_writes_a_governance_rule_scoped_to_the_departments_org(): void
    {
        $this->assertDatabaseCount('governance_rules', 0);

        RiskSetting::setGoverningDepartmentId($this->governingDept->id);

        $this->assertDatabaseHas('governance_rules', [
            'organization_id' => $this->org->id,
            'resource_type' => 'risk',
            'resource_subtype' => null,
            'governing_unit_id' => $this->governingDept->id,
        ]);
        // getter round-trips through governance_rules
        $this->assertSame($this->governingDept->id, RiskSetting::getGoverningDepartmentId());
    }

    public function test_risk_governing_member_governs_org_wide_from_governance_rules(): void
    {
        RiskSetting::setGoverningDepartmentId($this->governingDept->id);
        $gov = $this->governingMember();

        $svc = app(RiskAuthorizationService::class);
        $this->assertTrue($svc->governs($gov), 'governing-dept member should govern via governance_rules');
        $this->assertTrue($svc->canViewAny($gov));
        // org-wide create (unrestricted target departments)
        $this->assertNull($svc->creatableDepartmentIds($gov));
    }

    public function test_ovr_governing_member_governs_org_wide_from_governance_rules(): void
    {
        OvrSetting::setGoverningDepartmentId($this->governingDept->id);
        $gov = $this->governingMember();

        $svc = app(OvrAuthorizationService::class);
        $this->assertTrue($svc->governs($gov));
        $this->assertTrue($svc->canViewAny($gov));
    }

    // (b) project per-type governance still resolves per type.

    public function test_project_governance_resolves_per_type(): void
    {
        $improvementGov = $this->makeDept('GR-IMP');
        $developmentGov = $this->makeDept('GR-DEV');

        ProjectSetting::setGoverningDepartments([
            'improvement' => $improvementGov->id,
            'development' => $developmentGov->id,
        ]);

        $this->assertSame($improvementGov->id, ProjectSetting::getGoverningDepartmentForType('improvement'));
        $this->assertSame($developmentGov->id, ProjectSetting::getGoverningDepartmentForType('development'));
        $this->assertEqualsCanonicalizing(
            ['improvement' => $improvementGov->id, 'development' => $developmentGov->id],
            ProjectSetting::getGoverningDepartments(),
        );

        // A member of the improvement governor oversees ONLY the improvement type.
        $user = User::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $improvementGov->id,
            'is_active' => true,
        ]);
        $this->assignCanonicalRole($user, 'dept_member', 'department', $improvementGov->id);
        Cache::flush();

        $svc = app(ProjectAuthorizationService::class);
        $this->assertSame(['improvement'], $svc->governedTypes($user));

        // A subtype write is a distinct row (not a collision with the null-subtype rule).
        $this->assertDatabaseHas('governance_rules', [
            'resource_type' => 'project',
            'resource_subtype' => 'improvement',
            'governing_unit_id' => $improvementGov->id,
        ]);
    }

    public function test_clearing_a_project_type_removes_only_that_rule(): void
    {
        $impGov = $this->makeDept('GR-IMP2');
        $devGov = $this->makeDept('GR-DEV2');
        ProjectSetting::setGoverningDepartments(['improvement' => $impGov->id, 'development' => $devGov->id]);

        // Re-set without improvement -> its rule is cleared, development kept.
        ProjectSetting::setGoverningDepartments(['development' => $devGov->id]);

        $this->assertNull(ProjectSetting::getGoverningDepartmentForType('improvement'));
        $this->assertSame($devGov->id, ProjectSetting::getGoverningDepartmentForType('development'));
    }

    // (c) unset governor -> no elevation (the failure case, per LR-005).

    public function test_no_rule_means_no_governance_and_no_elevation(): void
    {
        // Deliberately do NOT set any governing department.
        $gov = $this->governingMember();

        $this->assertNull(RiskSetting::getGoverningDepartmentId());
        $this->assertNull(OvrSetting::getGoverningDepartmentId());

        $riskSvc = app(RiskAuthorizationService::class);
        $ovrSvc = app(OvrAuthorizationService::class);

        $this->assertFalse($riskSvc->governs($gov), 'no rule => no risk governance');
        $this->assertFalse($ovrSvc->governs($gov), 'no rule => no ovr governance');
        // A plain department member without a governor is scoped to its own dept, not org-wide.
        $this->assertEqualsCanonicalizing([$this->governingDept->id], $riskSvc->creatableDepartmentIds($gov));
    }

    public function test_clearing_the_risk_governor_removes_the_rule(): void
    {
        RiskSetting::setGoverningDepartmentId($this->governingDept->id);
        $this->assertDatabaseCount('governance_rules', 1);

        RiskSetting::setGoverningDepartmentId(null);

        $this->assertDatabaseCount('governance_rules', 0);
        $this->assertNull(RiskSetting::getGoverningDepartmentId());
        $this->assertFalse(app(RiskAuthorizationService::class)->governs($this->governingMember()));
    }

    public function test_governance_rule_resolver_falls_back_to_null_subtype(): void
    {
        // A null-subtype project rule governs any subtype when no specific rule exists.
        GovernanceRule::setGoverningUnit($this->org->id, GovernanceRule::TYPE_PROJECT, null, $this->governingDept->id, ['projects.*']);

        $this->assertSame(
            $this->governingDept->id,
            GovernanceRule::governingUnitId($this->org->id, GovernanceRule::TYPE_PROJECT, 'improvement'),
        );
        // A more specific subtype rule wins over the null fallback.
        $specific = $this->makeDept('GR-SPEC');
        GovernanceRule::setGoverningUnit($this->org->id, GovernanceRule::TYPE_PROJECT, 'improvement', $specific->id, ['projects.*']);
        $this->assertSame(
            $specific->id,
            GovernanceRule::governingUnitId($this->org->id, GovernanceRule::TYPE_PROJECT, 'improvement'),
        );
    }
}
