<?php

namespace Tests\Feature\Projects;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectExpense;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * Wave 1 Task 1.6: project edge-coverage gaps that the audit flagged as
 * zero-coverage or known holes:
 *
 *   1. GET /api/projects/{project}/expenses/{expense}/attachment
 *      was a "public receipts" endpoint before hardening; today it
 *      goes through ProjectPolicy::view but has no HTTP test.
 *
 *   2. POST /api/projects/{project}/members with role=manager: a
 *      project manager (with `update` only) must NOT be able to
 *      promote another user to manager. Today the escalation check
 *      lives at line 491-493 of ProjectController::addMember but
 *      has no test.
 *
 *   3. PUT /api/projects/{id}: partial updates must NOT clobber
 *      unmentioned fields. The audit found a data-corruption bug
 *      here historically.
 *
 *   4. PUT /api/projects/governing-departments: rejecting a request
 *      that points a project type at another organization's
 *      department.
 */
class ProjectAuthzTopUpTest extends TestCase
{
    use GrantsEngineCapability;
    use RefreshDatabase;

    protected Organization $orgA;

    protected Organization $orgB;

    protected Department $deptA;

    protected Department $deptB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->orgA = Organization::factory()->create();
        $this->orgB = Organization::factory()->create();
        $this->deptA = Department::factory()->create(['organization_id' => $this->orgA->id]);
        $this->deptB = Department::factory()->create(['organization_id' => $this->orgB->id]);

        Storage::fake('local');
    }

    private function makeUser(Organization $org, Department $dept, ?string $role = null): User
    {
        $user = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
            'is_active' => true,
        ]);
        if ($role) {
            $this->assignCanonicalRole($user, $role);
        }

        return $user;
    }

    private function makeProject(Organization $org, Department $dept): Project
    {
        return Project::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
            'status' => 'in_progress',
            'type' => 'new',
            'budget' => 10000,
            'description' => 'original description',
        ]);
    }

    // ========== 1. Expense attachment authz ==========

    public function test_expense_attachment_requires_authentication(): void
    {
        $project = $this->makeProject($this->orgA, $this->deptA);
        $expense = ProjectExpense::factory()->create([
            'project_id' => $project->id,
            'attachment_path' => 'expenses/sample.pdf',
        ]);

        $this->getJson("/api/projects/{$project->id}/expenses/{$expense->id}/attachment")
            ->assertStatus(401);
    }

    public function test_cross_org_actor_cannot_download_expense_attachment(): void
    {
        $project = $this->makeProject($this->orgA, $this->deptA);
        $expense = ProjectExpense::factory()->create([
            'project_id' => $project->id,
            'attachment_path' => 'expenses/sample.pdf',
        ]);

        $foreignAdmin = $this->makeUser($this->orgB, $this->deptB, 'admin');

        $this->actingAs($foreignAdmin, 'sanctum')
            ->getJson("/api/projects/{$project->id}/expenses/{$expense->id}/attachment")
            ->assertStatus(403);
    }

    public function test_expense_attachment_matching_404_when_expense_belongs_to_other_project(): void
    {
        $projectA = $this->makeProject($this->orgA, $this->deptA);
        $projectB = $this->makeProject($this->orgA, $this->deptA);
        $expenseOnB = ProjectExpense::factory()->create([
            'project_id' => $projectB->id,
            'attachment_path' => 'expenses/sample.pdf',
        ]);

        // Trying to fetch projectB's expense via projectA's URL: 404 by
        // the `expense->project_id !== $project->id` check at line 337.
        $admin = $this->makeUser($this->orgA, $this->deptA, 'admin');

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/projects/{$projectA->id}/expenses/{$expenseOnB->id}/attachment")
            ->assertStatus(404);
    }

    public function test_expense_attachment_returns_404_when_no_file(): void
    {
        $project = $this->makeProject($this->orgA, $this->deptA);
        $expense = ProjectExpense::factory()->create([
            'project_id' => $project->id,
            'attachment_path' => null,
        ]);

        $admin = $this->makeUser($this->orgA, $this->deptA, 'admin');

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/projects/{$project->id}/expenses/{$expense->id}/attachment")
            ->assertStatus(404);
    }

    // ========== 2. addMember privilege escalation ==========

    public function test_project_member_cannot_promote_to_manager_via_add_member(): void
    {
        $project = $this->makeProject($this->orgA, $this->deptA);
        $newUser = $this->makeUser($this->orgA, $this->deptA);
        // Caller is a regular member — has no `update` capability.
        $caller = $this->makeUser($this->orgA, $this->deptA);

        $this->actingAs($caller, 'sanctum')
            ->postJson("/api/projects/{$project->id}/members", [
                'user_id' => $newUser->id,
                'role' => 'manager',
            ])
            ->assertStatus(403);
    }

    public function test_add_member_with_member_role_succeeds_for_authorized_actor(): void
    {
        $project = $this->makeProject($this->orgA, $this->deptA);
        $newUser = $this->makeUser($this->orgA, $this->deptA);
        $admin = $this->makeUser($this->orgA, $this->deptA, 'admin');

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/projects/{$project->id}/members", [
                'user_id' => $newUser->id,
                'role' => 'member',
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('authorization_role_assignments', [
            'scope_type' => 'project',
            'scope_id' => $project->id,
            'user_id' => $newUser->id,
        ]);
    }

    public function test_add_member_with_unknown_role_returns_422(): void
    {
        $project = $this->makeProject($this->orgA, $this->deptA);
        $newUser = $this->makeUser($this->orgA, $this->deptA);
        $admin = $this->makeUser($this->orgA, $this->deptA, 'admin');

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/projects/{$project->id}/members", [
                'user_id' => $newUser->id,
                'role' => 'nonexistent_role',
            ])
            ->assertStatus(422);
    }

    // ========== 3. Partial update non-destructiveness ==========

    public function test_partial_project_update_leaves_unmentioned_fields_intact(): void
    {
        $project = $this->makeProject($this->orgA, $this->deptA);
        $admin = $this->makeUser($this->orgA, $this->deptA, 'admin');

        // Update only `description`. Status changes trigger the closure
        // validator (kpis/lessons_learned) which is a separate concern.
        // The point of this test is that fields NOT mentioned in the
        // request body must not be reset by the server.
        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/projects/{$project->id}", [
                'description' => 'updated description',
            ])
            ->assertStatus(200);

        $project->refresh();
        $this->assertSame('updated description', $project->description);
        $this->assertEquals(10000, $project->budget, 'budget must not change on partial update');
        $this->assertSame('new', $project->type, 'type must not change on partial update');
        $this->assertSame('in_progress', $project->status, 'status must not change on partial update');
    }

    // ========== 4. Governing-departments cross-org guard ==========

    public function test_governing_departments_rejects_foreign_org_department(): void
    {
        $project = $this->makeProject($this->orgA, $this->deptA);
        $superAdmin = User::factory()->create([
            'organization_id' => null,
            'is_active' => true,
        ]);
        $this->grantCanonicalSuperAdmin($superAdmin);

        // Pointing orgA's improvement-type governing-department at an
        // orgB department must be rejected — the org-floor says no.
        $this->actingAs($superAdmin, 'sanctum')
            ->putJson('/api/projects/governing-departments', [
                'departments' => [
                    'improvement' => $this->deptB->id,
                ],
            ])
            ->assertStatus(422);
    }
}
