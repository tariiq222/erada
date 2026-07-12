<?php

namespace Tests\Feature\Projects;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * Coverage for PATCH /api/projects/{id}/pdca-phase.
 *
 * Existing tests cover the happy path (advance / no-skip / act-loops-back /
 * rejected-for-non-improvement). Wave-2 remediation rows A5 add:
 *   - 401 unauthenticated
 *   - Same-org viewer → 403 (wrong-role denial)
 *   - Cross-org actor (with edit capability) → [403, 404]
 */
class PdcaPhaseTest extends TestCase
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
    }

    /**
     * @return array{0: User, 1: Project}
     */
    private function improvementProjectWithManager(array $overrides = []): array
    {
        $project = Project::factory()->create(array_merge([
            'organization_id' => $this->orgA->id,
            'department_id' => $this->deptA->id,
            'type' => 'improvement',
            'current_pdca_phase' => 'plan',
        ], $overrides));

        $manager = User::factory()->create([
            'organization_id' => $this->orgA->id,
            'department_id' => $this->deptA->id,
            'is_active' => true,
        ]);
        $this->grantCanonicalSuperAdmin($manager);

        return [$manager, $project];
    }

    private function makeUser(?Organization $org, ?string $role = null): User
    {
        $user = User::factory()->create([
            'organization_id' => $org?->id,
            'department_id' => $org ? ($org->id === $this->orgA->id ? $this->deptA->id : $this->deptB->id) : null,
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

    public function test_manager_can_advance_one_step_forward(): void
    {
        [$manager, $project] = $this->improvementProjectWithManager(['current_pdca_phase' => 'plan']);

        $this->actingAs($manager)
            ->patchJson("/api/projects/{$project->id}/pdca-phase", ['phase' => 'do'], ['X-Skip-Csrf' => '1'])
            ->assertOk();

        $this->assertSame('do', $project->fresh()->current_pdca_phase);
    }

    public function test_cannot_skip_phases(): void
    {
        [$manager, $project] = $this->improvementProjectWithManager(['current_pdca_phase' => 'plan']);

        $this->actingAs($manager)
            ->patchJson("/api/projects/{$project->id}/pdca-phase", ['phase' => 'act'], ['X-Skip-Csrf' => '1'])
            ->assertStatus(422);

        $this->assertSame('plan', $project->fresh()->current_pdca_phase);
    }

    public function test_act_loops_back_to_plan(): void
    {
        [$manager, $project] = $this->improvementProjectWithManager(['current_pdca_phase' => 'act']);

        $this->actingAs($manager)
            ->patchJson("/api/projects/{$project->id}/pdca-phase", ['phase' => 'plan'], ['X-Skip-Csrf' => '1'])
            ->assertOk();
    }

    public function test_pdca_phase_is_rejected_for_new_projects(): void
    {
        [$manager, $project] = $this->improvementProjectWithManager([
            'type' => 'development',
            'current_pdca_phase' => 'plan',
        ]);

        $this->actingAs($manager)
            ->patchJson("/api/projects/{$project->id}/pdca-phase", ['phase' => 'do'], ['X-Skip-Csrf' => '1'])
            ->assertStatus(422);
    }

    // ========== Wave-2 remediation rows A5 ==========

    public function test_pdca_phase_requires_authentication(): void
    {
        [, $project] = $this->improvementProjectWithManager();

        $this->patchJson("/api/projects/{$project->id}/pdca-phase", ['phase' => 'do'])
            ->assertStatus(401);
    }

    public function test_viewer_cannot_advance_pdca_phase(): void
    {
        $viewer = $this->makeUser($this->orgA, 'viewer');
        $project = Project::factory()->create([
            'organization_id' => $this->orgA->id,
            'department_id' => $this->deptA->id,
            'type' => 'improvement',
            'current_pdca_phase' => 'plan',
        ]);

        $this->actingAs($viewer, 'sanctum')
            ->patchJson("/api/projects/{$project->id}/pdca-phase", ['phase' => 'do'], ['X-Skip-Csrf' => '1'])
            ->assertStatus(403);

        $this->assertSame('plan', $project->fresh()->current_pdca_phase);
    }

    public function test_cross_org_actor_with_edit_capability_cannot_advance_foreign_pdca_phase(): void
    {
        $actor = $this->makeUser($this->orgA, 'viewer');
        $this->grantEngineCapability($actor, Capability::PROJECTS_EDIT);

        $foreignProject = Project::factory()->create([
            'organization_id' => $this->orgB->id,
            'department_id' => $this->deptB->id,
            'type' => 'improvement',
            'current_pdca_phase' => 'plan',
        ]);

        $this->assertDeniedByIsolation(
            $this->actingAs($actor, 'sanctum')
                ->patchJson(
                    "/api/projects/{$foreignProject->id}/pdca-phase",
                    ['phase' => 'do'],
                    ['X-Skip-Csrf' => '1']
                )->status(),
            'org-A editor must not advance an org-B project PDCA phase'
        );

        $this->assertSame('plan', $foreignProject->fresh()->current_pdca_phase);
    }
}
