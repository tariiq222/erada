<?php

namespace Tests\Feature\Meetings;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Meetings\Models\Recommendation;
use App\Modules\Projects\Models\Project;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

class RecommendationStateMachineAuthorizationTest extends TestCase
{
    use GrantsEngineCapability;
    use RefreshDatabase;

    private User $editor;

    private Meeting $meeting;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $department = Department::factory()->create();
        $this->editor = User::factory()->create([
            'department_id' => $department->id,
            'organization_id' => $department->organization_id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($this->editor, [
            Capability::RECOMMENDATIONS_CREATE,
            Capability::RECOMMENDATIONS_EDIT,
        ], 'organization', $department->organization_id);

        $this->meeting = Meeting::factory()->create([
            'department_id' => $department->id,
            'organization_id' => $department->organization_id,
            'organizer_id' => $this->editor->id,
            'status' => Meeting::STATUS_SCHEDULED,
        ]);
    }

    public function test_create_rejects_client_supplied_status(): void
    {
        $response = $this->actingAs($this->editor, 'sanctum')
            ->postJson('/api/recommendations', [
                'kind' => Recommendation::KIND_RULING,
                'meeting_id' => $this->meeting->id,
                'title' => 'قرار متجاوز',
                'type' => 'approval',
                'priority' => Recommendation::PRIORITY_MEDIUM,
                'status' => Recommendation::STATUS_APPROVED,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    public function test_update_rejects_client_supplied_status(): void
    {
        $recommendation = $this->makeActionItem(Recommendation::STATUS_PROPOSED);

        $response = $this->actingAs($this->editor, 'sanctum')
            ->patchJson("/api/recommendations/{$recommendation->id}", [
                'title' => $recommendation->title,
                'priority' => $recommendation->priority,
                'status' => Recommendation::STATUS_COMPLETED,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
        $this->assertSame(Recommendation::STATUS_PROPOSED, $recommendation->fresh()->status);
    }

    public function test_update_rejects_client_supplied_kind(): void
    {
        $recommendation = $this->makeActionItem(Recommendation::STATUS_PROPOSED);

        $response = $this->actingAs($this->editor, 'sanctum')
            ->patchJson("/api/recommendations/{$recommendation->id}", [
                'title' => $recommendation->title,
                'priority' => $recommendation->priority,
                'kind' => Recommendation::KIND_RULING,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['kind']);
        $this->assertSame(Recommendation::KIND_ACTION_ITEM, $recommendation->fresh()->kind);
    }

    public function test_update_accepts_the_recommendations_existing_kind(): void
    {
        $recommendation = $this->makeActionItem(Recommendation::STATUS_PROPOSED);

        $this->actingAs($this->editor, 'sanctum')
            ->patchJson("/api/recommendations/{$recommendation->id}", [
                'title' => 'إجراء محدّث',
                'priority' => $recommendation->priority,
                'kind' => Recommendation::KIND_ACTION_ITEM,
            ])
            ->assertStatus(200)
            ->assertJsonPath('recommendation.kind', Recommendation::KIND_ACTION_ITEM);
    }

    public function test_create_rejects_invalid_decidable_type(): void
    {
        $this->postRuling(['decidable_type' => 'user', 'decidable_id' => $this->editor->id])
            ->assertStatus(422)->assertJsonValidationErrors(['decidable_type']);
    }

    public function test_create_rejects_missing_decidable_target(): void
    {
        $this->postRuling(['decidable_type' => 'project', 'decidable_id' => 999999])
            ->assertStatus(422)->assertJsonValidationErrors(['decidable_id']);
    }

    public function test_create_rejects_cross_organization_decidable_target(): void
    {
        $project = Project::factory()->create();

        $this->postRuling(['decidable_type' => 'project', 'decidable_id' => $project->id])
            ->assertStatus(422)->assertJsonValidationErrors(['decidable_id']);
    }

    public function test_create_accepts_same_organization_decidable_alias_and_persists_class(): void
    {
        $project = $this->makeSameOrganizationProject();

        $this->postRuling(['decidable_type' => 'project', 'decidable_id' => $project->id])
            ->assertCreated()->assertJsonPath('recommendation.decidable_type', Project::class);
        $this->assertDatabaseHas('recommendations', ['decidable_type' => Project::class, 'decidable_id' => $project->id]);
    }

    public function test_update_rejects_invalid_missing_and_cross_organization_decidable_targets(): void
    {
        $recommendation = $this->makeActionItem(Recommendation::STATUS_PROPOSED);
        $crossOrgProject = Project::factory()->create();

        foreach ([
            ['decidable_type' => 'user', 'decidable_id' => $this->editor->id, 'error' => 'decidable_type'],
            ['decidable_type' => 'project', 'decidable_id' => 999999, 'error' => 'decidable_id'],
            ['decidable_type' => 'project', 'decidable_id' => $crossOrgProject->id, 'error' => 'decidable_id'],
        ] as $case) {
            $this->actingAs($this->editor, 'sanctum')->patchJson("/api/recommendations/{$recommendation->id}", [
                'title' => $recommendation->title,
                'priority' => $recommendation->priority,
                'decidable_type' => $case['decidable_type'],
                'decidable_id' => $case['decidable_id'],
            ])->assertStatus(422)->assertJsonValidationErrors([$case['error']]);
        }
    }

    public function test_update_accepts_same_organization_decidable_alias_and_persists_class(): void
    {
        $recommendation = $this->makeActionItem(Recommendation::STATUS_PROPOSED);
        $project = $this->makeSameOrganizationProject();

        $this->actingAs($this->editor, 'sanctum')->patchJson("/api/recommendations/{$recommendation->id}", [
            'title' => $recommendation->title,
            'priority' => $recommendation->priority,
            'decidable_type' => 'project',
            'decidable_id' => $project->id,
        ])->assertOk()->assertJsonPath('recommendation.decidable_type', Project::class);
        $this->assertDatabaseHas('recommendations', ['id' => $recommendation->id, 'decidable_type' => Project::class, 'decidable_id' => $project->id]);
    }

    public function test_accept_requires_accept_capability_not_edit(): void
    {
        $recommendation = $this->makeActionItem(Recommendation::STATUS_PROPOSED);

        $this->actingAs($this->editor, 'sanctum')
            ->postJson("/api/recommendations/{$recommendation->id}/accept")
            ->assertStatus(403);
        $this->assertSame(Recommendation::STATUS_PROPOSED, $recommendation->fresh()->status);
    }

    public function test_complete_requires_complete_capability_not_edit(): void
    {
        $recommendation = $this->makeActionItem(Recommendation::STATUS_ACCEPTED);

        $this->actingAs($this->editor, 'sanctum')
            ->postJson("/api/recommendations/{$recommendation->id}/complete")
            ->assertStatus(403);
        $this->assertSame(Recommendation::STATUS_ACCEPTED, $recommendation->fresh()->status);
    }

    public function test_accept_succeeds_with_accept_capability_without_edit(): void
    {
        $actor = $this->makeActorWith(Capability::RECOMMENDATIONS_ACCEPT);
        $recommendation = $this->makeActionItem(Recommendation::STATUS_PROPOSED);

        $this->actingAs($actor, 'sanctum')
            ->postJson("/api/recommendations/{$recommendation->id}/accept")
            ->assertStatus(200)
            ->assertJsonPath('recommendation.status', Recommendation::STATUS_ACCEPTED);
    }

    public function test_complete_succeeds_with_complete_capability_without_edit(): void
    {
        $actor = $this->makeActorWith(Capability::RECOMMENDATIONS_COMPLETE);
        $recommendation = $this->makeActionItem(Recommendation::STATUS_ACCEPTED);

        $this->actingAs($actor, 'sanctum')
            ->postJson("/api/recommendations/{$recommendation->id}/complete")
            ->assertStatus(200)
            ->assertJsonPath('recommendation.status', Recommendation::STATUS_COMPLETED);
    }

    public function test_approve_rejects_an_action_item_even_with_accept_capability(): void
    {
        $actor = $this->makeActorWith(Capability::RECOMMENDATIONS_ACCEPT);
        $recommendation = $this->makeActionItem(Recommendation::STATUS_PROPOSED);

        $this->actingAs($actor, 'sanctum')
            ->postJson("/api/recommendations/{$recommendation->id}/approve")
            ->assertStatus(403);
        $this->assertSame(Recommendation::STATUS_PROPOSED, $recommendation->fresh()->status);
    }

    public function test_super_admin_cannot_approve_an_action_item(): void
    {
        $superAdmin = $this->makeSuperAdmin();
        $recommendation = $this->makeActionItem(Recommendation::STATUS_PROPOSED);

        $this->actingAs($superAdmin, 'sanctum')
            ->postJson("/api/recommendations/{$recommendation->id}/approve")
            ->assertStatus(403);
        $this->assertSame(Recommendation::STATUS_PROPOSED, $recommendation->fresh()->status);
    }

    public function test_super_admin_cannot_self_approve_a_ruling(): void
    {
        $superAdmin = $this->makeSuperAdmin();
        $ruling = Recommendation::create([
            'kind' => Recommendation::KIND_RULING,
            'meeting_id' => $this->meeting->id,
            'title' => 'قرار ذاتي',
            'type' => 'approval',
            'requested_by' => $superAdmin->id,
            'status' => Recommendation::STATUS_PENDING,
            'priority' => Recommendation::PRIORITY_MEDIUM,
            'organization_id' => $this->meeting->organization_id,
        ]);

        $this->actingAs($superAdmin, 'sanctum')
            ->postJson("/api/recommendations/{$ruling->id}/approve")
            ->assertStatus(403);
        $this->assertSame(Recommendation::STATUS_PENDING, $ruling->fresh()->status);
    }

    private function makeActionItem(string $status): Recommendation
    {
        return Recommendation::create([
            'kind' => Recommendation::KIND_ACTION_ITEM,
            'meeting_id' => $this->meeting->id,
            'title' => 'إجراء متابعة',
            'assignee_id' => $this->editor->id,
            'due_date' => now()->addDays(7)->toDateString(),
            'status' => $status,
            'priority' => Recommendation::PRIORITY_MEDIUM,
            'organization_id' => $this->meeting->organization_id,
        ]);
    }

    private function postRuling(array $overrides = []): TestResponse
    {
        return $this->actingAs($this->editor, 'sanctum')->postJson('/api/recommendations', array_merge([
            'kind' => Recommendation::KIND_RULING,
            'meeting_id' => $this->meeting->id,
            'title' => 'قرار مرتبط',
            'type' => 'approval',
            'priority' => Recommendation::PRIORITY_MEDIUM,
        ], $overrides));
    }

    private function makeSameOrganizationProject(): Project
    {
        $department = Department::factory()->create(['organization_id' => $this->meeting->organization_id]);

        return Project::factory()->create([
            'department_id' => $department->id,
            'organization_id' => $this->meeting->organization_id,
        ]);
    }

    private function makeActorWith(string $capability): User
    {
        $actor = User::factory()->create([
            'department_id' => $this->meeting->department_id,
            'organization_id' => $this->meeting->organization_id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($actor, $capability, 'organization', $this->meeting->organization_id);

        return $actor;
    }

    private function makeSuperAdmin(): User
    {
        $superAdmin = User::factory()->create([
            'department_id' => $this->meeting->department_id,
            'organization_id' => $this->meeting->organization_id,
            'is_active' => true,
        ]);
        $superAdmin->assignRole('super_admin');

        return $superAdmin;
    }
}
