<?php

namespace Tests\Feature\Projects;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectRisk;
use App\Modules\Projects\Models\Stakeholder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\ScopedDepartmentRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * HTTP coverage for the sub-resource CRUD endpoints of the Projects module.
 *
 * Each test exercises a controller endpoint that was previously uncovered at
 * the HTTP layer (only service-level tests existed):
 *  - POST   /api/projects/{project}/members        (addMember)
 *  - PUT    /api/projects/{project}/members/{user} (updateMemberRole)
 *  - POST   /api/projects/{project}/stakeholders   (addStakeholder)
 *  - PUT    /api/projects/{project}/stakeholders/{stakeholder} (updateStakeholder)
 *  - POST   /api/projects/{project}/risks          (addRisk)
 *  - PUT    /api/projects/{project}/risks/{risk}   (updateRisk)
 *  - DELETE /api/projects/{project}/risks/{risk}   (removeRisk)
 *
 * Wave-2 remediation rows A6 add 403-denied / cross-org coverage for the risk
 * write endpoints and the stakeholder write endpoints (currently all flows
 * pass via super_admin only).
 */
class ProjectMembersStakeholdersRisksEndpointTest extends TestCase
{
    use GrantsEngineCapability;
    use RefreshDatabase;

    protected Organization $orgA;

    protected Organization $orgB;

    protected Department $deptA;

    protected Department $deptB;

    protected User $superAdmin;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(ScopedDepartmentRolesSeeder::class);

        $this->orgA = Organization::factory()->create();
        $this->orgB = Organization::factory()->create();

        $this->deptA = Department::factory()->create([
            'organization_id' => $this->orgA->id,
            'is_active' => true,
        ]);
        $this->deptB = Department::factory()->create([
            'organization_id' => $this->orgB->id,
            'is_active' => true,
        ]);

        $this->superAdmin = User::factory()->create([
            'department_id' => $this->deptA->id,
            'organization_id' => $this->orgA->id,
            'is_active' => true,
        ]);
        $this->grantCanonicalSuperAdmin($this->superAdmin);

        $this->project = Project::factory()->create([
            'department_id' => $this->deptA->id,
            'organization_id' => $this->orgA->id,
            'created_by' => $this->superAdmin->id,
            'type' => 'development',
            'status' => 'in_progress',
        ]);

        Cache::flush();
    }

    private function makeUser(?Organization $org, ?string $role = null): User
    {
        $deptId = $org ? ($org->id === $this->orgA->id ? $this->deptA->id : $this->deptB->id) : null;

        $user = User::factory()->create([
            'organization_id' => $org?->id,
            'department_id' => $deptId,
            'is_active' => true,
        ]);
        if ($role) {
            $this->assignCanonicalRole($user, $role);
        }

        return $user;
    }

    private function assertDeniedByIsolation(int $status, string $msg): void
    {
        $this->assertContains($status, [403, 404], $msg);
    }

    // ==================== Members ====================

    public function test_super_admin_can_add_member_via_http(): void
    {
        $newUser = User::factory()->create([
            'department_id' => $this->deptA->id,
            'organization_id' => $this->orgA->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->withHeader('X-Skip-Csrf', '1')
            ->postJson("/api/projects/{$this->project->id}/members", [
                'user_id' => $newUser->id,
                'role' => 'member',
            ]);

        $response->assertOk()
            ->assertJsonStructure(['message', 'members']);

        $this->assertDatabaseHas('authorization_role_assignments', [
            'scope_type' => 'project',
            'scope_id' => $this->project->id,
            'user_id' => $newUser->id,
        ]);
    }

    public function test_adding_duplicate_member_returns_422(): void
    {
        $existingUser = User::factory()->create([
            'department_id' => $this->deptA->id,
            'organization_id' => $this->orgA->id,
            'is_active' => true,
        ]);
        $this->assignCanonicalRole($existingUser, 'project_member', 'project', $this->project->id);

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->withHeader('X-Skip-Csrf', '1')
            ->postJson("/api/projects/{$this->project->id}/members", [
                'user_id' => $existingUser->id,
                'role' => 'member',
            ]);

        $response->assertStatus(422);
    }

    public function test_adding_member_with_unknown_role_returns_422(): void
    {
        $newUser = User::factory()->create([
            'department_id' => $this->deptA->id,
            'organization_id' => $this->orgA->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->withHeader('X-Skip-Csrf', '1')
            ->postJson("/api/projects/{$this->project->id}/members", [
                'user_id' => $newUser->id,
                'role' => 'not-a-real-role',
            ]);

        $response->assertStatus(422);
    }

    public function test_adding_member_from_foreign_org_is_rejected_for_non_super(): void
    {
        // super_admin bypasses org isolation by design, so we exercise the
        // defense-in-depth org check with a non-super caller (admin scoped to
        // the project's own org).
        $orgScopedAdmin = User::factory()->create([
            'department_id' => $this->deptA->id,
            'organization_id' => $this->orgA->id,
            'is_active' => true,
        ]);
        $this->grantCanonicalAdmin($orgScopedAdmin);
        Cache::flush();

        $foreignUser = User::factory()->create([
            'department_id' => $this->deptB->id,
            'organization_id' => $this->orgB->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($orgScopedAdmin, 'sanctum')
            ->withHeader('X-Skip-Csrf', '1')
            ->postJson("/api/projects/{$this->project->id}/members", [
                'user_id' => $foreignUser->id,
                'role' => 'member',
            ]);

        $response->assertStatus(403);
    }

    public function test_super_admin_can_update_member_role_via_http(): void
    {
        $member = User::factory()->create([
            'department_id' => $this->deptA->id,
            'organization_id' => $this->orgA->id,
            'is_active' => true,
        ]);
        $this->assignCanonicalRole($member, 'project_member', 'project', $this->project->id);

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->withHeader('X-Skip-Csrf', '1')
            ->putJson("/api/projects/{$this->project->id}/members/{$member->id}", [
                'role' => 'viewer',
            ]);

        $response->assertOk();
        $this->assertDatabaseHas('authorization_role_assignments', [
            'scope_type' => 'project',
            'scope_id' => $this->project->id,
            'user_id' => $member->id,
        ]);
    }

    public function test_updating_member_role_with_invalid_value_returns_422(): void
    {
        $member = User::factory()->create([
            'department_id' => $this->deptA->id,
            'organization_id' => $this->orgA->id,
            'is_active' => true,
        ]);
        $this->assignCanonicalRole($member, 'project_member', 'project', $this->project->id);

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->withHeader('X-Skip-Csrf', '1')
            ->putJson("/api/projects/{$this->project->id}/members/{$member->id}", [
                'role' => 'super_admin',
            ]);

        $response->assertStatus(422);
    }

    // ==================== Stakeholders ====================

    public function test_super_admin_can_add_stakeholder_via_http(): void
    {
        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->withHeader('X-Skip-Csrf', '1')
            ->postJson("/api/projects/{$this->project->id}/stakeholders", [
                'name' => 'Suad Alqahtani',
                'role' => 'end_user',
                'organization' => 'Ministry of Health',
                'email' => 'suad@example.com',
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['message', 'stakeholder' => ['id', 'name', 'role']]);

        $this->assertDatabaseHas('stakeholders', [
            'project_id' => $this->project->id,
            'name' => 'Suad Alqahtani',
            'role' => 'end_user',
        ]);
    }

    public function test_adding_stakeholder_with_missing_name_returns_422(): void
    {
        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->withHeader('X-Skip-Csrf', '1')
            ->postJson("/api/projects/{$this->project->id}/stakeholders", [
                'role' => 'end_user',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_super_admin_can_update_stakeholder_via_http(): void
    {
        $stakeholder = Stakeholder::create([
            'project_id' => $this->project->id,
            'name' => 'Original Stakeholder',
            'role' => 'end_user',
        ]);

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->withHeader('X-Skip-Csrf', '1')
            ->putJson("/api/projects/{$this->project->id}/stakeholders/{$stakeholder->id}", [
                'role' => 'consultant',
                'notes' => 'Updated role',
            ]);

        $response->assertOk()
            ->assertJsonPath('stakeholder.role', 'consultant');

        $this->assertDatabaseHas('stakeholders', [
            'id' => $stakeholder->id,
            'role' => 'consultant',
            'notes' => 'Updated role',
            'name' => 'Original Stakeholder',
        ]);
    }

    // ==================== Risks ====================

    public function test_super_admin_can_add_risk_via_http(): void
    {
        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->withHeader('X-Skip-Csrf', '1')
            ->postJson("/api/projects/{$this->project->id}/risks", [
                'risk' => 'Late equipment delivery',
                'probability' => 'medium',
                'impact' => 'high',
                'response' => 'Follow up with vendor',
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['message', 'risk' => ['id', 'risk', 'probability', 'impact', 'status']]);

        $this->assertDatabaseHas('project_risks', [
            'project_id' => $this->project->id,
            'risk' => 'Late equipment delivery',
            'status' => 'open',
        ]);
    }

    public function test_adding_risk_without_risk_or_description_returns_422(): void
    {
        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->withHeader('X-Skip-Csrf', '1')
            ->postJson("/api/projects/{$this->project->id}/risks", [
                'probability' => 'low',
                'impact' => 'low',
            ]);

        $response->assertStatus(422);
    }

    public function test_adding_risk_with_invalid_probability_returns_422(): void
    {
        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->withHeader('X-Skip-Csrf', '1')
            ->postJson("/api/projects/{$this->project->id}/risks", [
                'risk' => 'Test risk',
                'probability' => 'extreme',
                'impact' => 'low',
            ]);

        $response->assertStatus(422);
    }

    public function test_super_admin_can_update_risk_via_http(): void
    {
        $risk = ProjectRisk::create([
            'project_id' => $this->project->id,
            'risk' => 'Original risk',
            'probability' => 'low',
            'impact' => 'low',
            'status' => 'open',
            'order' => 1,
        ]);

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->withHeader('X-Skip-Csrf', '1')
            ->putJson("/api/projects/{$this->project->id}/risks/{$risk->id}", [
                'risk' => 'Updated risk description',
                'probability' => 'high',
                'impact' => 'medium',
                'status' => 'mitigated',
            ]);

        $response->assertOk()
            ->assertJsonPath('risk.risk', 'Updated risk description');

        $this->assertDatabaseHas('project_risks', [
            'id' => $risk->id,
            'risk' => 'Updated risk description',
            'probability' => 'high',
            'impact' => 'medium',
            'status' => 'mitigated',
        ]);
    }

    public function test_super_admin_can_remove_risk_via_http(): void
    {
        $risk = ProjectRisk::create([
            'project_id' => $this->project->id,
            'risk' => 'Risk to delete',
            'probability' => 'low',
            'impact' => 'low',
            'status' => 'open',
            'order' => 1,
        ]);

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->withHeader('X-Skip-Csrf', '1')
            ->deleteJson("/api/projects/{$this->project->id}/risks/{$risk->id}");

        $response->assertOk();
        // Audit 2026-07-06 (P1-5): project_risks now uses SoftDeletes so the
        // delete is a soft-delete (deleted_at is set) rather than a hard row
        // removal. Verify the row is soft-deleted, not missing entirely.
        $this->assertSoftDeleted('project_risks', ['id' => $risk->id]);
    }

    public function test_unauthenticated_member_addition_is_rejected(): void
    {
        $newUser = User::factory()->create([
            'department_id' => $this->deptA->id,
            'organization_id' => $this->orgA->id,
            'is_active' => true,
        ]);

        $response = $this->withHeader('X-Skip-Csrf', '1')
            ->postJson("/api/projects/{$this->project->id}/members", [
                'user_id' => $newUser->id,
            ]);

        $response->assertStatus(401);
    }

    // ========== Wave-2 remediation rows A6 — risk / stakeholder authz-denial ==========

    public function test_viewer_cannot_add_risk(): void
    {
        $viewer = $this->makeUser($this->orgA, 'viewer');

        $response = $this->actingAs($viewer, 'sanctum')
            ->withHeader('X-Skip-Csrf', '1')
            ->postJson("/api/projects/{$this->project->id}/risks", [
                'risk' => 'Should be denied',
                'probability' => 'low',
                'impact' => 'low',
            ]);

        $response->assertStatus(403);
        $this->assertDatabaseCount('project_risks', 0);
    }

    public function test_viewer_cannot_update_risk(): void
    {
        $viewer = $this->makeUser($this->orgA, 'viewer');
        $risk = ProjectRisk::create([
            'project_id' => $this->project->id,
            'risk' => 'Untouched',
            'probability' => 'low',
            'impact' => 'low',
            'status' => 'open',
            'order' => 1,
        ]);

        $response = $this->actingAs($viewer, 'sanctum')
            ->withHeader('X-Skip-Csrf', '1')
            ->putJson("/api/projects/{$this->project->id}/risks/{$risk->id}", [
                'risk' => 'Hijacked',
            ]);

        $response->assertStatus(403);
        $this->assertDatabaseHas('project_risks', [
            'id' => $risk->id,
            'risk' => 'Untouched',
        ]);
    }

    public function test_viewer_cannot_remove_risk(): void
    {
        $viewer = $this->makeUser($this->orgA, 'viewer');
        $risk = ProjectRisk::create([
            'project_id' => $this->project->id,
            'risk' => 'Survives',
            'probability' => 'low',
            'impact' => 'low',
            'status' => 'open',
            'order' => 1,
        ]);

        $response = $this->actingAs($viewer, 'sanctum')
            ->withHeader('X-Skip-Csrf', '1')
            ->deleteJson("/api/projects/{$this->project->id}/risks/{$risk->id}");

        $response->assertStatus(403);
        $this->assertDatabaseHas('project_risks', ['id' => $risk->id]);
    }

    public function test_viewer_cannot_add_stakeholder(): void
    {
        $viewer = $this->makeUser($this->orgA, 'viewer');

        $response = $this->actingAs($viewer, 'sanctum')
            ->withHeader('X-Skip-Csrf', '1')
            ->postJson("/api/projects/{$this->project->id}/stakeholders", [
                'name' => 'Denied Stakeholder',
                'role' => 'end_user',
            ]);

        $response->assertStatus(403);
        $this->assertDatabaseCount('stakeholders', 0);
    }

    public function test_viewer_cannot_update_stakeholder(): void
    {
        $viewer = $this->makeUser($this->orgA, 'viewer');
        $stakeholder = Stakeholder::create([
            'project_id' => $this->project->id,
            'name' => 'Original Stakeholder',
            'role' => 'end_user',
        ]);

        $response = $this->actingAs($viewer, 'sanctum')
            ->withHeader('X-Skip-Csrf', '1')
            ->putJson("/api/projects/{$this->project->id}/stakeholders/{$stakeholder->id}", [
                'role' => 'consultant',
            ]);

        $response->assertStatus(403);
        $this->assertDatabaseHas('stakeholders', [
            'id' => $stakeholder->id,
            'role' => 'end_user',
        ]);
    }

    public function test_cross_org_editor_cannot_add_risk_to_foreign_project(): void
    {
        $actor = $this->makeUser($this->orgA, 'viewer');
        $this->grantEngineCapability($actor, Capability::PROJECTS_EDIT);

        $foreignProject = Project::factory()->create([
            'organization_id' => $this->orgB->id,
            'department_id' => $this->deptB->id,
            'type' => 'development',
            'status' => 'in_progress',
        ]);

        $this->assertDeniedByIsolation(
            $this->actingAs($actor, 'sanctum')
                ->withHeader('X-Skip-Csrf', '1')
                ->postJson("/api/projects/{$foreignProject->id}/risks", [
                    'risk' => 'Cross-org risk attempt',
                    'probability' => 'low',
                    'impact' => 'low',
                ])->status(),
            'org-A editor must not add risks to an org-B project'
        );

        $this->assertDatabaseCount('project_risks', 0);
    }

    public function test_cross_org_editor_cannot_update_risk_on_foreign_project(): void
    {
        $actor = $this->makeUser($this->orgA, 'viewer');
        $this->grantEngineCapability($actor, Capability::PROJECTS_EDIT);

        $foreignProject = Project::factory()->create([
            'organization_id' => $this->orgB->id,
            'department_id' => $this->deptB->id,
            'type' => 'development',
            'status' => 'in_progress',
        ]);
        $foreignRisk = ProjectRisk::create([
            'project_id' => $foreignProject->id,
            'risk' => 'Foreign risk',
            'probability' => 'low',
            'impact' => 'low',
            'status' => 'open',
            'order' => 1,
        ]);

        $this->assertDeniedByIsolation(
            $this->actingAs($actor, 'sanctum')
                ->withHeader('X-Skip-Csrf', '1')
                ->putJson("/api/projects/{$foreignProject->id}/risks/{$foreignRisk->id}", [
                    'risk' => 'Hijacked',
                ])->status(),
            'org-A editor must not update risks on an org-B project'
        );

        $this->assertDatabaseHas('project_risks', [
            'id' => $foreignRisk->id,
            'risk' => 'Foreign risk',
        ]);
    }

    public function test_cross_org_editor_cannot_remove_risk_from_foreign_project(): void
    {
        $actor = $this->makeUser($this->orgA, 'viewer');
        $this->grantEngineCapability($actor, Capability::PROJECTS_EDIT);

        $foreignProject = Project::factory()->create([
            'organization_id' => $this->orgB->id,
            'department_id' => $this->deptB->id,
            'type' => 'development',
            'status' => 'in_progress',
        ]);
        $foreignRisk = ProjectRisk::create([
            'project_id' => $foreignProject->id,
            'risk' => 'Survives cross-org delete',
            'probability' => 'low',
            'impact' => 'low',
            'status' => 'open',
            'order' => 1,
        ]);

        $this->assertDeniedByIsolation(
            $this->actingAs($actor, 'sanctum')
                ->withHeader('X-Skip-Csrf', '1')
                ->deleteJson("/api/projects/{$foreignProject->id}/risks/{$foreignRisk->id}")
                ->status(),
            'org-A editor must not delete risks on an org-B project'
        );

        $this->assertDatabaseHas('project_risks', ['id' => $foreignRisk->id]);
    }

    public function test_cross_org_editor_cannot_add_stakeholder_to_foreign_project(): void
    {
        $actor = $this->makeUser($this->orgA, 'viewer');
        $this->grantEngineCapability($actor, Capability::PROJECTS_EDIT);

        $foreignProject = Project::factory()->create([
            'organization_id' => $this->orgB->id,
            'department_id' => $this->deptB->id,
            'type' => 'development',
            'status' => 'in_progress',
        ]);

        $this->assertDeniedByIsolation(
            $this->actingAs($actor, 'sanctum')
                ->withHeader('X-Skip-Csrf', '1')
                ->postJson("/api/projects/{$foreignProject->id}/stakeholders", [
                    'name' => 'Cross-org stakeholder',
                    'role' => 'end_user',
                ])->status(),
            'org-A editor must not add stakeholders to an org-B project'
        );

        $this->assertDatabaseCount('stakeholders', 0);
    }

    public function test_cross_org_editor_cannot_update_stakeholder_on_foreign_project(): void
    {
        $actor = $this->makeUser($this->orgA, 'viewer');
        $this->grantEngineCapability($actor, Capability::PROJECTS_EDIT);

        $foreignProject = Project::factory()->create([
            'organization_id' => $this->orgB->id,
            'department_id' => $this->deptB->id,
            'type' => 'development',
            'status' => 'in_progress',
        ]);
        $foreignStakeholder = Stakeholder::create([
            'project_id' => $foreignProject->id,
            'name' => 'Foreign stakeholder',
            'role' => 'end_user',
        ]);

        $this->assertDeniedByIsolation(
            $this->actingAs($actor, 'sanctum')
                ->withHeader('X-Skip-Csrf', '1')
                ->putJson("/api/projects/{$foreignProject->id}/stakeholders/{$foreignStakeholder->id}", [
                    'role' => 'consultant',
                ])->status(),
            'org-A editor must not update stakeholders on an org-B project'
        );

        $this->assertDatabaseHas('stakeholders', [
            'id' => $foreignStakeholder->id,
            'role' => 'end_user',
        ]);
    }

    public function test_stakeholder_from_sibling_project_cannot_be_updated_and_persists(): void
    {
        $actor = $this->makeUser($this->orgA, 'viewer');
        $this->grantEngineCapability($actor, Capability::PROJECTS_EDIT);
        $siblingProject = Project::factory()->create([
            'organization_id' => $this->orgA->id,
            'department_id' => $this->deptA->id,
            'type' => 'development',
            'status' => 'in_progress',
        ]);
        $stakeholder = Stakeholder::create([
            'project_id' => $siblingProject->id,
            'name' => 'Sibling stakeholder',
            'role' => 'end_user',
        ]);

        $this->actingAs($actor, 'sanctum')
            ->withHeader('X-Skip-Csrf', '1')
            ->putJson("/api/projects/{$this->project->id}/stakeholders/{$stakeholder->id}", [
                'role' => 'consultant',
            ])
            ->assertNotFound();

        $this->assertDatabaseHas('stakeholders', [
            'id' => $stakeholder->id,
            'project_id' => $siblingProject->id,
            'role' => 'end_user',
        ]);
    }
}
