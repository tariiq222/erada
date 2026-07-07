<?php

namespace Tests\Feature\Api\Projects;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\ScopedRole;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectSetting;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\ScopedDepartmentRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Assignable project manager at creation.
 *
 * Covers the backend half of the "choose a different project manager" feature:
 *  - POST /api/projects honours an optional manager_user_id (creator-vs-target
 *    scoped-role assignment + server-side scope/eligibility re-check).
 *  - GET  /api/projects/assignable-managers lists active, in-scope, eligible
 *    candidates with org isolation and no information leak.
 *
 * Scope/eligibility primitives are reused from ProjectAuthorizationService; the
 * setup mirrors ProjectCreationGovernanceTest (governing departments + scoped
 * department roles).
 */
class AssignableManagerTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Department $parentDept;   // higher-level department (manager covers subtree)

    private Department $childDept;    // child of parentDept

    private Department $otherDept;    // unrelated department, same org

    private Department $qualityDept;  // governs 'improvement'

    private Department $pmoDept;      // governs 'development'

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeaders(['X-Skip-Csrf' => '1']);

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(ScopedDepartmentRolesSeeder::class);
        Cache::flush();

        $this->org = Organization::factory()->create();

        $this->parentDept = $this->makeDept('PARENT', null, Department::LEVEL_DEPARTMENT);
        $this->childDept = $this->makeDept('CHILD', $this->parentDept->id, Department::LEVEL_SECTION);
        $this->otherDept = $this->makeDept('OTHER', null, Department::LEVEL_DEPARTMENT);
        $this->qualityDept = $this->makeDept('QUALITY', null, Department::LEVEL_DEPARTMENT);
        $this->pmoDept = $this->makeDept('PMO', null, Department::LEVEL_DEPARTMENT);

        ProjectSetting::setGoverningDepartments([
            'improvement' => $this->qualityDept->id,
            'development' => $this->pmoDept->id,
        ]);

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

    private function makeUser(?int $deptId, bool $active = true, ?int $orgId = null): User
    {
        return User::factory()->create([
            'organization_id' => $orgId ?? $this->org->id,
            'department_id' => $deptId,
            'is_active' => $active,
        ]);
    }

    private function withDeptRole(User $user, string $roleKey, Department $dept): User
    {
        $user->assignScopedRole($roleKey, ScopedRole::SCOPE_DEPARTMENT, $dept->id, null, true);
        Cache::flush();

        return $user;
    }

    /**
     * Minimal valid create payload for a given type. An improvement project
     * requires >= 1 KPI (StoreProjectRequest::methodologyRules()).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(string $type, int $departmentId, array $overrides = []): array
    {
        $base = [
            'name' => 'مشروع اختبار',
            'type' => $type,
            'department_id' => $departmentId,
            'priority' => 'high',
        ];

        if ($type === 'improvement') {
            $base['problem_statement'] = 'بيان المشكلة';
            $base['kpis'] = [
                ['name' => 'مؤشر 1', 'target' => 100, 'baseline' => 0],
            ];
        }

        return array_merge($base, $overrides);
    }

    /**
     * Assert the scoped manager role for a project is held by exactly $userId.
     */
    private function assertProjectManagerIs(int $projectId, int $userId): void
    {
        $this->assertDatabaseHas('model_has_scoped_roles', [
            'scope_type' => ScopedRole::SCOPE_PROJECT,
            'scope_id' => $projectId,
            'role' => ScopedRole::PROJECT_MANAGER,
            'user_id' => $userId,
        ]);
    }

    private function assertHasNoScopedRoleOnProject(int $projectId, int $userId): void
    {
        $this->assertDatabaseMissing('model_has_scoped_roles', [
            'scope_type' => ScopedRole::SCOPE_PROJECT,
            'scope_id' => $projectId,
            'user_id' => $userId,
        ]);
    }

    // ===================== Create: default (no manager_user_id) =====================

    public function test_creator_becomes_manager_when_no_manager_user_id(): void
    {
        $creator = $this->withDeptRole($this->makeUser($this->childDept->id), 'dept_manager', $this->childDept);

        $response = $this->actingAs($creator, 'sanctum')
            ->postJson('/api/projects', $this->payload('development', $this->childDept->id));

        $response->assertStatus(201);
        $projectId = (int) $response->json('project.id');

        $this->assertProjectManagerIs($projectId, $creator->id);
        $this->assertDatabaseHas('projects', ['id' => $projectId, 'created_by' => $creator->id]);
    }

    public function test_self_manager_user_id_keeps_creator_as_manager(): void
    {
        $creator = $this->withDeptRole($this->makeUser($this->childDept->id), 'dept_manager', $this->childDept);

        $response = $this->actingAs($creator, 'sanctum')
            ->postJson('/api/projects', $this->payload('development', $this->childDept->id, [
                'manager_user_id' => $creator->id,
            ]));

        $response->assertStatus(201);
        $projectId = (int) $response->json('project.id');

        $this->assertProjectManagerIs($projectId, $creator->id);
    }

    // ===================== Create: assign another eligible user =====================

    public function test_assigns_target_as_manager_for_new_project(): void
    {
        $creator = $this->withDeptRole($this->makeUser($this->childDept->id), 'dept_manager', $this->childDept);
        // Target is eligible (own department member) and in the creator's subtree.
        $target = $this->withDeptRole($this->makeUser($this->childDept->id), 'dept_member', $this->childDept);

        $response = $this->actingAs($creator, 'sanctum')
            ->postJson('/api/projects', $this->payload('development', $this->childDept->id, [
                'manager_user_id' => $target->id,
            ]));

        $response->assertStatus(201);
        $projectId = (int) $response->json('project.id');

        // Target holds the manager role; creator holds NO scoped role; created_by = creator.
        $this->assertProjectManagerIs($projectId, $target->id);
        $this->assertHasNoScopedRoleOnProject($projectId, $creator->id);
        $this->assertDatabaseHas('projects', ['id' => $projectId, 'created_by' => $creator->id]);

        // Manager accessor resolves to the target, not the creator.
        $this->assertSame($target->id, Project::find($projectId)->manager->id);
    }

    public function test_assigns_target_as_manager_for_improvement_project(): void
    {
        $creator = $this->withDeptRole($this->makeUser($this->childDept->id), 'dept_manager', $this->childDept);
        $target = $this->withDeptRole($this->makeUser($this->childDept->id), 'dept_member', $this->childDept);

        $response = $this->actingAs($creator, 'sanctum')
            ->postJson('/api/projects', $this->payload('improvement', $this->childDept->id, [
                'manager_user_id' => $target->id,
            ]));

        $response->assertStatus(201);
        $projectId = (int) $response->json('project.id');

        $this->assertProjectManagerIs($projectId, $target->id);
        $this->assertHasNoScopedRoleOnProject($projectId, $creator->id);
        $this->assertDatabaseHas('projects', [
            'id' => $projectId,
            'type' => 'improvement',
            'created_by' => $creator->id,
        ]);
    }

    // ===================== Create: rejection paths (422) =====================

    public function test_rejects_out_of_scope_target_when_creator_not_governing(): void
    {
        // Creator restricted to its own (child) subtree; target lives in an
        // unrelated department, and the creator does not govern 'development'.
        $creator = $this->withDeptRole($this->makeUser($this->childDept->id), 'dept_manager', $this->childDept);
        $target = $this->withDeptRole($this->makeUser($this->otherDept->id), 'dept_manager', $this->otherDept);

        $response = $this->actingAs($creator, 'sanctum')
            ->postJson('/api/projects', $this->payload('development', $this->childDept->id, [
                'manager_user_id' => $target->id,
            ]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors('manager_user_id');

        // No project leaked through on the failure path.
        $this->assertDatabaseMissing('projects', ['name' => 'مشروع اختبار']);
    }

    public function test_rejects_in_scope_but_ineligible_target(): void
    {
        // Creator can create; target is in the same department but holds NO
        // scoped role, so canCreateAny(target) is false (ineligible to manage).
        $creator = $this->withDeptRole($this->makeUser($this->childDept->id), 'dept_manager', $this->childDept);
        $ineligible = $this->makeUser($this->childDept->id); // no scoped role at all

        $response = $this->actingAs($creator, 'sanctum')
            ->postJson('/api/projects', $this->payload('development', $this->childDept->id, [
                'manager_user_id' => $ineligible->id,
            ]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors('manager_user_id');

        $this->assertDatabaseMissing('projects', ['name' => 'مشروع اختبار']);
    }

    public function test_rejects_inactive_target(): void
    {
        $creator = $this->withDeptRole($this->makeUser($this->childDept->id), 'dept_manager', $this->childDept);
        // Eligible role but inactive -> must be rejected.
        $inactive = $this->makeUser($this->childDept->id, active: false);
        $this->withDeptRole($inactive, 'dept_member', $this->childDept);

        $response = $this->actingAs($creator, 'sanctum')
            ->postJson('/api/projects', $this->payload('development', $this->childDept->id, [
                'manager_user_id' => $inactive->id,
            ]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors('manager_user_id');
    }

    public function test_rejects_cross_organization_target(): void
    {
        $creator = $this->withDeptRole($this->makeUser($this->childDept->id), 'dept_manager', $this->childDept);

        $otherOrg = Organization::factory()->create();
        $otherOrgDept = Department::factory()->create([
            'organization_id' => $otherOrg->id,
            'level' => Department::LEVEL_DEPARTMENT,
            'is_active' => true,
        ]);
        $foreign = $this->makeUser($otherOrgDept->id, orgId: $otherOrg->id);
        $foreign->assignScopedRole('dept_manager', ScopedRole::SCOPE_DEPARTMENT, $otherOrgDept->id, null, true);
        Cache::flush();

        $response = $this->actingAs($creator, 'sanctum')
            ->postJson('/api/projects', $this->payload('development', $this->childDept->id, [
                'manager_user_id' => $foreign->id,
            ]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors('manager_user_id');
    }

    // ===================== assignable-managers endpoint =====================

    public function test_assignable_managers_requires_valid_type(): void
    {
        $creator = $this->withDeptRole($this->makeUser($this->childDept->id), 'dept_manager', $this->childDept);

        $this->actingAs($creator, 'sanctum')
            ->getJson('/api/projects/assignable-managers')
            ->assertStatus(422)
            ->assertJsonValidationErrors('type');

        $this->actingAs($creator, 'sanctum')
            ->getJson('/api/projects/assignable-managers?type=bogus')
            ->assertStatus(422)
            ->assertJsonValidationErrors('type');
    }

    public function test_non_governing_creator_sees_only_own_department_eligible_users(): void
    {
        $creator = $this->withDeptRole($this->makeUser($this->childDept->id), 'dept_manager', $this->childDept);

        // Eligible, same department -> should appear.
        $eligibleSameDept = $this->withDeptRole($this->makeUser($this->childDept->id), 'dept_member', $this->childDept);
        // Ineligible (no role), same department -> excluded by canCreateAny filter.
        $ineligibleSameDept = $this->makeUser($this->childDept->id);
        // Eligible but in an unrelated department -> out of scope, excluded.
        $eligibleOtherDept = $this->withDeptRole($this->makeUser($this->otherDept->id), 'dept_manager', $this->otherDept);
        // Inactive eligible, same department -> excluded by is_active.
        $inactiveSameDept = $this->makeUser($this->childDept->id, active: false);
        $this->withDeptRole($inactiveSameDept, 'dept_member', $this->childDept);

        $response = $this->actingAs($creator, 'sanctum')
            ->getJson('/api/projects/assignable-managers?type=development')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'name', 'email', 'job_title', 'department_id']]]);

        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($creator->id, $ids, 'the creator is itself eligible and in scope');
        $this->assertContains($eligibleSameDept->id, $ids);
        $this->assertNotContains($ineligibleSameDept->id, $ids);
        $this->assertNotContains($eligibleOtherDept->id, $ids);
        $this->assertNotContains($inactiveSameDept->id, $ids);
    }

    public function test_governing_creator_sees_org_wide_eligible_users(): void
    {
        // PMO governs 'development' -> creator may pick from any department in the org.
        $creator = $this->withDeptRole($this->makeUser($this->pmoDept->id), 'dept_manager', $this->pmoDept);

        $eligibleElsewhere = $this->withDeptRole($this->makeUser($this->otherDept->id), 'dept_manager', $this->otherDept);
        $eligibleChild = $this->withDeptRole($this->makeUser($this->childDept->id), 'dept_member', $this->childDept);
        $ineligibleElsewhere = $this->makeUser($this->otherDept->id);

        $response = $this->actingAs($creator, 'sanctum')
            ->getJson('/api/projects/assignable-managers?type=development')
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($eligibleElsewhere->id, $ids, 'governing creator sees other-department eligible users');
        $this->assertContains($eligibleChild->id, $ids);
        $this->assertNotContains($ineligibleElsewhere->id, $ids, 'ineligible users are still filtered out');
    }

    public function test_assignable_managers_never_leaks_cross_organization_users(): void
    {
        $creator = $this->withDeptRole($this->makeUser($this->pmoDept->id), 'dept_manager', $this->pmoDept);

        $otherOrg = Organization::factory()->create();
        $otherOrgDept = Department::factory()->create([
            'organization_id' => $otherOrg->id,
            'level' => Department::LEVEL_DEPARTMENT,
            'is_active' => true,
        ]);
        $foreign = $this->makeUser($otherOrgDept->id, orgId: $otherOrg->id);
        $foreign->assignScopedRole('dept_manager', ScopedRole::SCOPE_DEPARTMENT, $otherOrgDept->id, null, true);
        Cache::flush();

        $response = $this->actingAs($creator, 'sanctum')
            ->getJson('/api/projects/assignable-managers?type=development')
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertNotContains($foreign->id, $ids);
    }

    public function test_assignable_managers_returns_empty_for_caller_who_cannot_create(): void
    {
        // No scoped role anywhere -> cannot create -> empty list, not a 403 leak.
        $outsider = $this->makeUser($this->otherDept->id);

        $this->actingAs($outsider, 'sanctum')
            ->getJson('/api/projects/assignable-managers?type=development')
            ->assertOk()
            ->assertExactJson(['data' => []]);
    }
}
