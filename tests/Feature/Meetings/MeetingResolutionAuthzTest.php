<?php

namespace Tests\Feature\Meetings;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Meetings\Models\MeetingResolution;
use App\Modules\Meetings\Models\ResolutionLink;
use App\Modules\Projects\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * MeetingResolutionAuthzTest
 *
 * Surface-level authz checks pinned against Phase 1 / Direction R:
 *   - Unauthenticated read is 401.
 *   - A super_admin in the resolution's org can create + view.
 *   - A user in org B cannot create a resolution against a meeting in org A
 *     (FormRequest::authorize() rejects via the engine before the DB check
 *     runs).
 *   - destroy() is a soft delete (the row sticks around, only deleted_at is
 *     stamped).
 *   - show() eager-loads the `links` pivot rows.
 *   - Phase 3 / Direction R — convert-to-tasks endpoint requires the
 *     meeting_resolutions.convert_to_tasks engine capability; a viewer is
 *     rejected with 403 (FormRequest::authorize() fails closed).
 */
class MeetingResolutionAuthzTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Organization $org;

    private Project $project;

    private Department $dept;

    private Meeting $meeting;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dept = Department::factory()->create();
        $this->project = Project::factory()->create(['department_id' => $this->dept->id]);
        $this->org = Organization::find($this->project->organization_id);

        $this->user = User::factory()->create([
            'department_id' => $this->dept->id,
            'organization_id' => $this->project->organization_id,
            'is_active' => true,
        ]);
        $this->user->assignRole('super_admin');

        $this->meeting = Meeting::factory()->create([
            'department_id' => $this->dept->id,
            'organization_id' => $this->project->organization_id,
            'organizer_id' => $this->user->id,
            'status' => Meeting::STATUS_SCHEDULED,
        ]);
    }

    public function test_unauthenticated_get_returns_401(): void
    {
        $this->getJson('/api/meeting-resolutions')
            ->assertStatus(401);
    }

    public function test_admin_can_create_and_view(): void
    {
        $create = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/meetings/{$this->meeting->id}/resolutions", [
                'meeting_id' => $this->meeting->id,
                'kind' => MeetingResolution::KIND_RECOMMENDATION,
                'title' => 'مخرج للمدير',
                'owner_id' => $this->user->id,
            ]);

        $create->assertStatus(201)
            ->assertJsonStructure(['message', 'resolution' => ['id', 'reference_number', 'status']]);
        $resolutionId = $create->json('resolution.id');

        $show = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/meeting-resolutions/{$resolutionId}");
        $show->assertStatus(200)
            ->assertJsonPath('id', $resolutionId);
    }

    public function test_create_requires_org_match(): void
    {
        // A non-super_admin user in the primary org, with no is_admin_role
        // engine grant, attempts to create a resolution against a meeting
        // in another org. They lack the meeting_resolutions.create capability
        // required by MeetingResolutionPolicy::create(), so
        // FormRequest::authorize() fails closed and the controller is never
        // invoked — 403, not 422 / 201.
        $noCapability = User::factory()->create([
            'department_id' => $this->dept->id,
            'organization_id' => $this->project->organization_id,
            'is_active' => true,
        ]);
        $noCapability->assignRole('viewer');

        $otherDept = Department::factory()->create();
        $otherProject = Project::factory()->create(['department_id' => $otherDept->id]);
        $foreignMeeting = Meeting::factory()->create([
            'department_id' => $otherDept->id,
            'organization_id' => $otherProject->organization_id,
            'status' => Meeting::STATUS_SCHEDULED,
        ]);

        $response = $this->actingAs($noCapability, 'sanctum')
            ->postJson("/api/meetings/{$foreignMeeting->id}/resolutions", [
                'meeting_id' => $foreignMeeting->id,
                'kind' => MeetingResolution::KIND_RECOMMENDATION,
                'title' => 'مخرج عبر الحدود',
                'owner_id' => $noCapability->id,
            ]);

        // Outside the user's organization — authorize() fails closed at the
        // engine policy / MeetingOrgGuard layer.
        $response->assertStatus(403);
        $this->assertDatabaseMissing('meeting_resolutions', [
            'meeting_id' => $foreignMeeting->id,
            'title' => 'مخرج عبر الحدود',
        ]);
    }

    public function test_destroy_soft_deletes(): void
    {
        $resolution = MeetingResolution::create([
            'meeting_id' => $this->meeting->id,
            'organization_id' => $this->project->organization_id,
            'kind' => MeetingResolution::KIND_RECOMMENDATION,
            'title' => 'مخرج للحذف',
            'owner_id' => $this->user->id,
            'created_by' => $this->user->id,
            'status' => MeetingResolution::STATUS_OPEN,
            'priority' => MeetingResolution::PRIORITY_MEDIUM,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/meeting-resolutions/{$resolution->id}");

        $response->assertStatus(200);

        // The row still exists — only deleted_at is stamped.
        $this->assertDatabaseHas('meeting_resolutions', ['id' => $resolution->id]);
        $this->assertSoftDeleted('meeting_resolutions', ['id' => $resolution->id]);
    }

    public function test_show_returns_links_included(): void
    {
        $resolution = MeetingResolution::create([
            'meeting_id' => $this->meeting->id,
            'organization_id' => $this->project->organization_id,
            'kind' => MeetingResolution::KIND_RECOMMENDATION,
            'title' => 'مخرج مع روابط',
            'owner_id' => $this->user->id,
            'created_by' => $this->user->id,
            'status' => MeetingResolution::STATUS_OPEN,
            'priority' => MeetingResolution::PRIORITY_MEDIUM,
        ]);

        ResolutionLink::create([
            'resolution_id' => $resolution->id,
            'linkable_type' => ResolutionLink::TYPE_PROJECT,
            'linkable_id' => $this->project->id,
            'link_role' => ResolutionLink::ROLE_RELATED_TO,
            'created_by' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/meeting-resolutions/{$resolution->id}");

        $response->assertStatus(200);
        $links = $response->json('links');
        $this->assertIsArray($links);
        $this->assertNotEmpty($links);
        $this->assertSame(ResolutionLink::TYPE_PROJECT, $links[0]['linkable_type']);
        $this->assertSame($this->project->id, $links[0]['linkable_id']);
    }

    /**
     * Phase 3 / Direction R — convert-to-tasks requires the
     * meeting_resolutions.convert_to_tasks engine capability. The viewer
     * role lacks it, so FormRequest::authorize() fails closed at the
     * policy layer (MeetingResolutionPolicy::convertToTasks → engine can()
     * returns false) before the controller body runs.
     */
    public function test_convert_to_tasks_requires_convert_capability(): void
    {
        $viewer = User::factory()->create([
            'department_id' => $this->dept->id,
            'organization_id' => $this->project->organization_id,
            'is_active' => true,
        ]);
        $viewer->assignRole('viewer');

        $resolution = MeetingResolution::create([
            'meeting_id' => $this->meeting->id,
            'organization_id' => $this->project->organization_id,
            'kind' => MeetingResolution::KIND_DECISION,
            'title' => 'مخرج للتحويل',
            'owner_id' => $this->user->id,
            'created_by' => $this->user->id,
            'status' => MeetingResolution::STATUS_OPEN,
            'priority' => MeetingResolution::PRIORITY_MEDIUM,
        ]);

        $response = $this->actingAs($viewer, 'sanctum')
            ->postJson("/api/meeting-resolutions/{$resolution->id}/convert-to-tasks", [
                'tasks' => [
                    ['title' => 'مهمة محظورة', 'assignee_id' => $this->user->id],
                ],
            ]);

        $response->assertStatus(403);
        // Status must NOT change when authorize() rejects.
        $this->assertSame(
            MeetingResolution::STATUS_OPEN,
            $resolution->fresh()->status,
        );
    }

    /**
     * Phase 3 / Direction R — a user in a different organization (without
     * the cross-org super_admin escape hatch) cannot convert a resolution
     * in another org. The MeetingOrgGuard fails the same-org check, so the
     * policy precheck returns false and authorize() throws 403.
     */
    public function test_convert_to_tasks_requires_same_organization(): void
    {
        $otherOrg = Organization::factory()->create();
        $otherDept = Department::factory()->create(['organization_id' => $otherOrg->id]);
        $crossOrgUser = User::factory()->create([
            'department_id' => $otherDept->id,
            'organization_id' => $otherOrg->id,
            'is_active' => true,
        ]);
        $crossOrgUser->assignRole('viewer');

        $resolution = MeetingResolution::create([
            'meeting_id' => $this->meeting->id,
            'organization_id' => $this->project->organization_id,
            'kind' => MeetingResolution::KIND_DECISION,
            'title' => 'مخرج محمي بالعزل',
            'owner_id' => $this->user->id,
            'created_by' => $this->user->id,
            'status' => MeetingResolution::STATUS_OPEN,
            'priority' => MeetingResolution::PRIORITY_MEDIUM,
        ]);

        $response = $this->actingAs($crossOrgUser, 'sanctum')
            ->postJson("/api/meeting-resolutions/{$resolution->id}/convert-to-tasks", [
                'tasks' => [
                    ['title' => 'مهمة عبر الحدود', 'assignee_id' => $crossOrgUser->id],
                ],
            ]);

        $response->assertStatus(403);
    }
}
