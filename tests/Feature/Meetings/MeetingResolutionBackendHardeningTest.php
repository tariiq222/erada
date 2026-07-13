<?php

namespace Tests\Feature\Meetings;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Meetings\Models\MeetingResolution;
use App\Modules\Meetings\Models\ResolutionLink;
use App\Modules\Projects\Models\Project;
use App\Modules\RiskManagement\Models\Risk;
use App\Modules\Tasks\Models\Task;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

class MeetingResolutionBackendHardeningTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    private User $user;

    private Department $department;

    private Project $project;

    private Meeting $meeting;

    protected function setUp(): void
    {
        parent::setUp();

        $this->department = Department::factory()->create();
        $this->project = Project::factory()->create([
            'organization_id' => $this->department->organization_id,
            'department_id' => $this->department->id,
        ]);
        $this->user = User::factory()->create([
            'organization_id' => $this->department->organization_id,
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        $this->user->assignRole('super_admin');

        $this->meeting = Meeting::factory()->create([
            'organization_id' => $this->department->organization_id,
            'department_id' => $this->department->id,
            'organizer_id' => $this->user->id,
            'status' => Meeting::STATUS_SCHEDULED,
        ]);
    }

    public function test_nested_create_uses_route_meeting_without_requiring_meeting_id_in_payload(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/meetings/{$this->meeting->id}/resolutions", $this->validCreatePayload());

        $response->assertCreated()
            ->assertJsonPath('resolution.meeting_id', $this->meeting->id);

        $this->assertDatabaseHas('meeting_resolutions', [
            'meeting_id' => $this->meeting->id,
            'title' => 'قرار محكم',
        ]);
    }

    public function test_nested_create_rejects_conflicting_payload_meeting_id(): void
    {
        $otherMeeting = Meeting::factory()->create([
            'organization_id' => $this->meeting->organization_id,
            'department_id' => $this->department->id,
            'organizer_id' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/meetings/{$this->meeting->id}/resolutions", [
                ...$this->validCreatePayload(),
                'meeting_id' => $otherMeeting->id,
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['meeting_id']);
        $this->assertDatabaseMissing('meeting_resolutions', ['title' => 'قرار محكم']);
    }

    public function test_nested_create_rejects_cross_org_meeting_before_link_validation(): void
    {
        $foreignDepartment = Department::factory()->create();
        $foreignMeeting = Meeting::factory()->create([
            'organization_id' => $foreignDepartment->organization_id,
            'department_id' => $foreignDepartment->id,
        ]);
        $actor = User::factory()->create([
            'organization_id' => $this->meeting->organization_id,
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability(
            $actor,
            Capability::MEETING_RESOLUTIONS_CREATE,
            scopeType: 'organization',
            scopeId: $this->meeting->organization_id,
            roleKey: 'resolution_create_oracle_test',
        );

        $response = $this->actingAs($actor, 'sanctum')
            ->postJson("/api/meetings/{$foreignMeeting->id}/resolutions", [
                'kind' => MeetingResolution::KIND_DECISION,
                'title' => 'محاولة كشف رابط',
                'owner_id' => $actor->id,
                'links' => [[
                    'linkable_type' => ResolutionLink::TYPE_PROJECT,
                    'linkable_id' => 999999,
                ]],
            ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('meeting_resolutions', ['title' => 'محاولة كشف رابط']);
    }

    public function test_create_rejects_missing_project_link_target(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/meetings/{$this->meeting->id}/resolutions", [
                ...$this->validCreatePayload(),
                'meeting_id' => $this->meeting->id,
                'links' => [[
                    'linkable_type' => ResolutionLink::TYPE_PROJECT,
                    'linkable_id' => 999999,
                ]],
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['links.0.linkable_id']);
        $this->assertDatabaseMissing('meeting_resolutions', ['title' => 'قرار محكم']);
    }

    public function test_create_rejects_risk_link_from_another_organization(): void
    {
        $otherRisk = Risk::factory()->create([
            'organization_id' => Organization::factory()->create()->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/meetings/{$this->meeting->id}/resolutions", [
                ...$this->validCreatePayload(),
                'meeting_id' => $this->meeting->id,
                'links' => [[
                    'linkable_type' => ResolutionLink::TYPE_RISK,
                    'linkable_id' => $otherRisk->id,
                ]],
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['links.0.linkable_id']);
        $this->assertDatabaseMissing('meeting_resolutions', ['title' => 'قرار محكم']);
    }

    public function test_update_rejects_missing_risk_link_and_preserves_existing_links(): void
    {
        $resolution = $this->makeResolution();
        $existingLink = ResolutionLink::create([
            'resolution_id' => $resolution->id,
            'linkable_type' => ResolutionLink::TYPE_PROJECT,
            'linkable_id' => $this->project->id,
            'created_by' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/meeting-resolutions/{$resolution->id}", [
                'links' => [[
                    'linkable_type' => ResolutionLink::TYPE_RISK,
                    'linkable_id' => 999999,
                ]],
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['links.0.linkable_id']);
        $this->assertDatabaseHas('resolution_links', ['id' => $existingLink->id]);
    }

    public function test_update_rejects_project_link_from_another_organization(): void
    {
        $resolution = $this->makeResolution();
        $otherProject = Project::factory()->create();

        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/meeting-resolutions/{$resolution->id}", [
                'links' => [[
                    'linkable_type' => ResolutionLink::TYPE_PROJECT,
                    'linkable_id' => $otherProject->id,
                ]],
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['links.0.linkable_id']);
        $this->assertDatabaseMissing('resolution_links', [
            'resolution_id' => $resolution->id,
            'linkable_id' => $otherProject->id,
        ]);
    }

    public function test_create_revalidates_link_target_inside_transaction_after_prevalidation_race(): void
    {
        $targetWasDeletedAfterPrevalidation = false;

        DB::listen(function (QueryExecuted $query) use (&$targetWasDeletedAfterPrevalidation): void {
            if ($targetWasDeletedAfterPrevalidation
                || ! str_starts_with(strtolower(ltrim($query->sql)), 'select')
                || ! str_contains($query->sql, 'projects')
                || ! str_contains($query->sql, 'exists')) {
                return;
            }

            $targetWasDeletedAfterPrevalidation = true;
            $this->project->delete();
        });

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/meetings/{$this->meeting->id}/resolutions", [
                ...$this->validCreatePayload(),
                'links' => [[
                    'linkable_type' => ResolutionLink::TYPE_PROJECT,
                    'linkable_id' => $this->project->id,
                ]],
            ]);

        $this->assertTrue($targetWasDeletedAfterPrevalidation);
        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['links.0.linkable_id']);
        $this->assertDatabaseMissing('meeting_resolutions', ['title' => 'قرار محكم']);
        $this->assertDatabaseMissing('resolution_links', [
            'linkable_type' => ResolutionLink::TYPE_PROJECT,
            'linkable_id' => $this->project->id,
        ]);
    }

    public function test_update_revalidates_link_target_inside_transaction_and_rolls_back_replacement(): void
    {
        $resolution = $this->makeResolution();
        $existingLink = ResolutionLink::create([
            'resolution_id' => $resolution->id,
            'linkable_type' => ResolutionLink::TYPE_PROJECT,
            'linkable_id' => $this->project->id,
            'created_by' => $this->user->id,
        ]);
        $replacementProject = Project::factory()->create([
            'organization_id' => $this->meeting->organization_id,
            'department_id' => $this->department->id,
        ]);
        $targetWasDeletedAfterPrevalidation = false;

        DB::listen(function (QueryExecuted $query) use ($replacementProject, &$targetWasDeletedAfterPrevalidation): void {
            if ($targetWasDeletedAfterPrevalidation
                || ! str_starts_with(strtolower(ltrim($query->sql)), 'select')
                || ! str_contains($query->sql, 'projects')
                || ! str_contains($query->sql, 'exists')) {
                return;
            }

            $targetWasDeletedAfterPrevalidation = true;
            $replacementProject->delete();
        });

        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/meeting-resolutions/{$resolution->id}", [
                'links' => [[
                    'linkable_type' => ResolutionLink::TYPE_PROJECT,
                    'linkable_id' => $replacementProject->id,
                ]],
            ]);

        $this->assertTrue($targetWasDeletedAfterPrevalidation);
        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['links.0.linkable_id']);
        $this->assertDatabaseHas('resolution_links', ['id' => $existingLink->id]);
        $this->assertDatabaseMissing('resolution_links', [
            'resolution_id' => $resolution->id,
            'linkable_id' => $replacementProject->id,
        ]);
    }

    public function test_update_locks_parent_resolution_before_replacing_links(): void
    {
        $resolution = $this->makeResolution();
        ResolutionLink::create([
            'resolution_id' => $resolution->id,
            'linkable_type' => ResolutionLink::TYPE_PROJECT,
            'linkable_id' => $this->project->id,
            'created_by' => $this->user->id,
        ]);
        $replacementProject = Project::factory()->create([
            'organization_id' => $this->meeting->organization_id,
            'department_id' => $this->department->id,
        ]);
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/meeting-resolutions/{$resolution->id}", [
                'links' => [[
                    'linkable_type' => ResolutionLink::TYPE_PROJECT,
                    'linkable_id' => $replacementProject->id,
                ]],
            ]);

        $response->assertOk();
        $parentLockIndex = collect($queries)->search(
            fn (string $sql): bool => str_contains($sql, 'meeting_resolutions')
                && str_contains($sql, 'for update'),
        );
        $linkDeleteIndex = collect($queries)->search(
            fn (string $sql): bool => str_starts_with(ltrim($sql), 'delete')
                && str_contains($sql, 'resolution_links'),
        );

        $this->assertIsInt($parentLockIndex, 'Expected the parent resolution to be locked for update.');
        $this->assertIsInt($linkDeleteIndex, 'Expected existing resolution links to be deleted.');
        $this->assertLessThan($linkDeleteIndex, $parentLockIndex);
    }

    public function test_conversion_revalidates_explicit_project_organization_before_insert(): void
    {
        $resolution = $this->makeResolution();
        $otherProject = Project::factory()->create();

        $response = $this->convert($resolution, [[
            'title' => 'مهمة بمشروع خارجي',
            'assignee_id' => $this->user->id,
            'project_id' => $otherProject->id,
        ]]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['tasks.0.project_id']);
        $this->assertSame(MeetingResolution::STATUS_OPEN, $resolution->fresh()->status);
        $this->assertDatabaseMissing('tasks', [
            'source_type' => 'MeetingResolution',
            'source_id' => $resolution->id,
        ]);
    }

    public function test_conversion_revalidates_fallback_project_link_before_insert(): void
    {
        $resolution = $this->makeResolution();
        $otherProject = Project::factory()->create();
        ResolutionLink::create([
            'resolution_id' => $resolution->id,
            'linkable_type' => ResolutionLink::TYPE_PROJECT,
            'linkable_id' => $otherProject->id,
            'created_by' => $this->user->id,
        ]);

        $response = $this->convert($resolution, [[
            'title' => 'مهمة من رابط خارجي قديم',
            'assignee_id' => $this->user->id,
        ]]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['tasks.0.project_id']);
        $this->assertSame(MeetingResolution::STATUS_OPEN, $resolution->fresh()->status);
        $this->assertDatabaseMissing('tasks', [
            'source_type' => 'MeetingResolution',
            'source_id' => $resolution->id,
        ]);
    }

    public function test_conversion_response_contains_exactly_the_tasks_inserted_by_this_request(): void
    {
        $resolution = $this->makeResolution();
        $preexistingTask = Task::factory()->create([
            'title' => 'مهمة سابقة حديثة',
            'project_id' => $this->project->id,
            'department_id' => $this->department->id,
            'organization_id' => $resolution->organization_id,
            'assigned_to' => $this->user->id,
            'source_type' => 'MeetingResolution',
            'source_id' => $resolution->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->convert($resolution, [[
            'title' => 'المهمة الجديدة فقط',
            'assignee_id' => $this->user->id,
        ]]);

        $response->assertCreated();
        $this->assertSame(['المهمة الجديدة فقط'], $response->collect('tasks')->pluck('title')->all());
        $this->assertNotContains($preexistingTask->id, $response->collect('tasks')->pluck('id')->all());
    }

    public function test_conversion_rechecks_locked_resolution_state_instead_of_using_stale_route_model(): void
    {
        $resolution = $this->makeResolution();
        $stateChangedAfterRouteSelect = false;

        DB::listen(function (QueryExecuted $query) use ($resolution, &$stateChangedAfterRouteSelect): void {
            if ($stateChangedAfterRouteSelect
                || ! str_starts_with(strtolower(ltrim($query->sql)), 'select')
                || ! str_contains($query->sql, 'meeting_resolutions')) {
                return;
            }

            $stateChangedAfterRouteSelect = true;
            DB::table('meeting_resolutions')
                ->where('id', $resolution->id)
                ->update(['status' => MeetingResolution::STATUS_CONVERTED_TO_TASKS]);
        });

        $response = $this->convert($resolution, [[
            'title' => 'مهمة من طلب متزامن',
            'assignee_id' => $this->user->id,
        ]]);

        $this->assertTrue($stateChangedAfterRouteSelect);
        $response->assertConflict();
        $this->assertDatabaseMissing('tasks', [
            'source_type' => 'MeetingResolution',
            'source_id' => $resolution->id,
        ]);
    }

    private function validCreatePayload(): array
    {
        return [
            'kind' => MeetingResolution::KIND_DECISION,
            'title' => 'قرار محكم',
            'owner_id' => $this->user->id,
        ];
    }

    private function makeResolution(): MeetingResolution
    {
        return MeetingResolution::factory()->create([
            'organization_id' => $this->meeting->organization_id,
            'meeting_id' => $this->meeting->id,
            'owner_id' => $this->user->id,
            'created_by' => $this->user->id,
            'status' => MeetingResolution::STATUS_OPEN,
        ]);
    }

    private function convert(MeetingResolution $resolution, array $tasks)
    {
        return $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/meeting-resolutions/{$resolution->id}/convert-to-tasks", [
                'tasks' => $tasks,
            ]);
    }
}
