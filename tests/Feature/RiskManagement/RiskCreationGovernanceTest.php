<?php

namespace Tests\Feature\RiskManagement;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\RiskManagement\Models\Risk;
use App\Modules\RiskManagement\Models\RiskSetting;
use App\Modules\RiskManagement\Scopes\UserRiskScope;
use App\Modules\RiskManagement\Services\RiskAuthorizationService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\ScopedDepartmentRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * Department-scoped + governing-department creation & visibility for risks.
 * A single governing department applies to all risk types. Also asserts the
 * visibility scope closes the prior over-fetch (every risk visible org-wide).
 */
class RiskCreationGovernanceTest extends TestCase
{
    use GrantsEngineCapability;
    use RefreshDatabase;

    private Organization $org;

    private Department $parentDept;

    private Department $childDept;

    private Department $otherDept;

    private Department $governingDept;

    private RiskAuthorizationService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(ScopedDepartmentRolesSeeder::class);
        Cache::flush();

        $this->org = Organization::factory()->create();
        $this->parentDept = $this->makeDept('R-PARENT', null, Department::LEVEL_DEPARTMENT);
        $this->childDept = $this->makeDept('R-CHILD', $this->parentDept->id, Department::LEVEL_SECTION);
        $this->otherDept = $this->makeDept('R-OTHER', null, Department::LEVEL_DEPARTMENT);
        $this->governingDept = $this->makeDept('R-GOV', null, Department::LEVEL_DEPARTMENT);

        RiskSetting::setGoverningDepartmentId($this->governingDept->id);

        $this->svc = app(RiskAuthorizationService::class);
        Cache::flush();
    }

    private function makeDept(string $code, ?int $parentId, int $level): Department
    {
        return Department::factory()->create([
            'code' => $code.'-'.uniqid(),
            'organization_id' => $this->org->id,
            'parent_id' => $parentId,
            'level' => $level,
            'is_active' => true,
        ]);
    }

    private function makeUser(?int $deptId): User
    {
        return User::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $deptId,
            'is_active' => true,
        ]);
    }

    private function withDeptRole(User $user, string $roleKey, Department $dept): User
    {
        $this->grantEngineCapability(
            $user,
            [Capability::RISKS_CREATE, Capability::RISKS_VIEW],
            'department',
            $dept->id,
            $roleKey,
            ['inherit_to_children' => true],
        );
        Cache::flush();

        return $user;
    }

    private function makeRisk(Department $dept): Risk
    {
        return Risk::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $dept->id,
        ]);
    }

    /** @return list<int> */
    private function visibleIds(User $user): array
    {
        $q = Risk::query();
        (new UserRiskScope)->apply($q, $user);

        return $q->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    // ---- creation ----

    public function test_member_can_create_for_own_department_only(): void
    {
        $m = $this->withDeptRole($this->makeUser($this->childDept->id), 'dept_member', $this->childDept);
        $this->assertTrue($this->svc->canCreate($m, $this->childDept->id));
        $this->assertFalse($this->svc->canCreate($m, $this->otherDept->id));
        $this->assertEqualsCanonicalizing([$this->childDept->id], $this->svc->creatableDepartmentIds($m));
    }

    public function test_higher_manager_covers_subtree(): void
    {
        $mgr = $this->withDeptRole($this->makeUser($this->parentDept->id), 'dept_manager', $this->parentDept);
        $this->assertTrue($this->svc->canCreate($mgr, $this->childDept->id));
        $this->assertFalse($this->svc->canCreate($mgr, $this->otherDept->id));
    }

    public function test_governing_member_creates_for_any_department(): void
    {
        $gov = $this->withDeptRole($this->makeUser($this->governingDept->id), 'dept_member', $this->governingDept);
        $this->assertTrue($this->svc->governs($gov));
        $this->assertTrue($this->svc->canCreate($gov, $this->otherDept->id));
        $this->assertNull($this->svc->creatableDepartmentIds($gov));
    }

    public function test_user_without_role_cannot_create(): void
    {
        $out = $this->makeUser($this->otherDept->id);
        $this->assertFalse($this->svc->canCreateAny($out));
        $this->assertSame([], $this->svc->creatableDepartmentIds($out));
    }

    // ---- visibility (over-fetch fix) ----

    public function test_member_sees_only_own_department_risks(): void
    {
        $m = $this->withDeptRole($this->makeUser($this->childDept->id), 'dept_member', $this->childDept);
        $own = $this->makeRisk($this->childDept);
        $foreign = $this->makeRisk($this->otherDept);

        $vis = $this->visibleIds($m);
        $this->assertContains($own->id, $vis);
        $this->assertNotContains($foreign->id, $vis, 'over-fetch fixed: foreign-dept risk hidden');
    }

    public function test_governing_member_sees_all_risks_org_wide(): void
    {
        $gov = $this->withDeptRole($this->makeUser($this->governingDept->id), 'dept_member', $this->governingDept);
        $a = $this->makeRisk($this->otherDept);
        $b = $this->makeRisk($this->childDept);

        $vis = $this->visibleIds($gov);
        $this->assertContains($a->id, $vis);
        $this->assertContains($b->id, $vis);
    }

    public function test_higher_manager_sees_child_department_risks(): void
    {
        $mgr = $this->withDeptRole($this->makeUser($this->parentDept->id), 'dept_manager', $this->parentDept);
        $child = $this->makeRisk($this->childDept);
        $foreign = $this->makeRisk($this->otherDept);

        $vis = $this->visibleIds($mgr);
        $this->assertContains($child->id, $vis);
        $this->assertNotContains($foreign->id, $vis);
    }

    public function test_cross_org_risks_never_visible(): void
    {
        $gov = $this->withDeptRole($this->makeUser($this->governingDept->id), 'dept_member', $this->governingDept);
        $otherOrg = Organization::factory()->create();
        $otherOrgDept = Department::factory()->create(['organization_id' => $otherOrg->id, 'level' => Department::LEVEL_DEPARTMENT]);
        $foreign = Risk::factory()->create(['organization_id' => $otherOrg->id, 'department_id' => $otherOrgDept->id]);

        $this->assertNotContains($foreign->id, $this->visibleIds($gov));
    }

    // ---- HTTP create authorization ----

    public function test_http_member_creates_own_dept_but_not_foreign(): void
    {
        $m = $this->withDeptRole($this->makeUser($this->childDept->id), 'dept_member', $this->childDept);

        $payload = fn (int $dept) => [
            'title' => 'E2E risk',
            'discovery_date' => now()->toDateString(),
            'type' => 'operational',
            'department_id' => $dept,
            'initial_likelihood' => 3,
            'initial_impact' => 3,
        ];

        $this->actingAs($m)->withHeader('X-Skip-Csrf', '1')
            ->postJson('/api/risk-management/risks', $payload($this->childDept->id))
            ->assertCreated();

        $this->actingAs($m)->withHeader('X-Skip-Csrf', '1')
            ->postJson('/api/risk-management/risks', $payload($this->otherDept->id))
            ->assertForbidden();
    }

    // ---- admin governing endpoints ----

    public function test_admin_reads_and_updates_governing_department(): void
    {
        $admin = $this->makeUser($this->parentDept->id);
        $this->grantEngineCapability($admin, Capability::SETTINGS_MANAGE);
        Cache::flush();

        $this->actingAs($admin)->getJson('/api/risk-management/settings/governing-department')
            ->assertOk()->assertJsonStructure(['department_id', 'departments']);

        $this->actingAs($admin)->withHeader('X-Skip-Csrf', '1')
            ->putJson('/api/risk-management/settings/governing-department', ['department_id' => $this->otherDept->id])
            ->assertOk();

        $this->assertSame($this->otherDept->id, RiskSetting::getGoverningDepartmentId());
    }

    public function test_non_admin_cannot_update_governing_department(): void
    {
        $m = $this->withDeptRole($this->makeUser($this->childDept->id), 'dept_member', $this->childDept);

        $this->actingAs($m)->withHeader('X-Skip-Csrf', '1')
            ->putJson('/api/risk-management/settings/governing-department', ['department_id' => $this->childDept->id])
            ->assertForbidden();
    }
}
