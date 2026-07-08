<?php

namespace Tests\Feature\Meetings;

use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Meetings\Models\MeetingResolution;
use App\Modules\Meetings\Models\ResolutionLink;
use App\Modules\Projects\Models\Project;
use App\Modules\RiskManagement\Models\Risk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * MeetingResolutionLinksTest
 *
 * Pins the `links` allowlist (`project` | `risk`) and roles (`related_to` |
 * `implementation_scope`), and the `syncLinks` replace-semantics on update:
 * the controller deletes the existing pivot rows and re-inserts the payload.
 *
 * The DB carries a UNIQUE(resolution_id, linkable_type, linkable_id, link_role)
 * constraint, so the update-replacement test uses distinct linkable_ids (or
 * distinct roles) per side to keep the assertion verifiable.
 */
class MeetingResolutionLinksTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Project $project;

    private Risk $risk;

    private Department $dept;

    private Meeting $meeting;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dept = Department::factory()->create();
        $this->project = Project::factory()->create(['department_id' => $this->dept->id]);
        $this->risk = Risk::factory()->create(['organization_id' => $this->project->organization_id]);
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

    public function test_create_with_project_link(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/meetings/{$this->meeting->id}/resolutions", [
                'meeting_id' => $this->meeting->id,
                'kind' => MeetingResolution::KIND_RECOMMENDATION,
                'title' => 'مخرج مرتبط بمشروع',
                'owner_id' => $this->user->id,
                'links' => [
                    [
                        'linkable_type' => ResolutionLink::TYPE_PROJECT,
                        'linkable_id' => $this->project->id,
                        'link_role' => ResolutionLink::ROLE_RELATED_TO,
                    ],
                ],
            ]);

        $response->assertStatus(201);
        $resolutionId = $response->json('resolution.id');

        $this->assertDatabaseHas('resolution_links', [
            'resolution_id' => $resolutionId,
            'linkable_type' => ResolutionLink::TYPE_PROJECT,
            'linkable_id' => $this->project->id,
            'link_role' => ResolutionLink::ROLE_RELATED_TO,
        ]);

        $links = $response->json('resolution.links');
        $this->assertIsArray($links);
        $this->assertCount(1, $links);
        $this->assertSame(ResolutionLink::TYPE_PROJECT, $links[0]['linkable_type']);
    }

    public function test_create_with_risk_link(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/meetings/{$this->meeting->id}/resolutions", [
                'meeting_id' => $this->meeting->id,
                'kind' => MeetingResolution::KIND_DECISION,
                'title' => 'مخرج مرتبط بخطر',
                'owner_id' => $this->user->id,
                'links' => [
                    [
                        'linkable_type' => ResolutionLink::TYPE_RISK,
                        'linkable_id' => $this->risk->id,
                        'link_role' => ResolutionLink::ROLE_RELATED_TO,
                    ],
                ],
            ]);

        $response->assertStatus(201);
        $resolutionId = $response->json('resolution.id');

        $this->assertDatabaseHas('resolution_links', [
            'resolution_id' => $resolutionId,
            'linkable_type' => ResolutionLink::TYPE_RISK,
            'linkable_id' => $this->risk->id,
            'link_role' => ResolutionLink::ROLE_RELATED_TO,
        ]);
    }

    public function test_create_with_implementation_scope_role(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/meetings/{$this->meeting->id}/resolutions", [
                'meeting_id' => $this->meeting->id,
                'kind' => MeetingResolution::KIND_DECISION,
                'title' => 'مخرج بنطاق تنفيذ',
                'owner_id' => $this->user->id,
                'links' => [
                    [
                        'linkable_type' => ResolutionLink::TYPE_PROJECT,
                        'linkable_id' => $this->project->id,
                        'link_role' => ResolutionLink::ROLE_IMPLEMENTATION_SCOPE,
                    ],
                ],
            ]);

        $response->assertStatus(201);
        $resolutionId = $response->json('resolution.id');

        $this->assertDatabaseHas('resolution_links', [
            'resolution_id' => $resolutionId,
            'linkable_type' => ResolutionLink::TYPE_PROJECT,
            'linkable_id' => $this->project->id,
            'link_role' => ResolutionLink::ROLE_IMPLEMENTATION_SCOPE,
        ]);
    }

    public function test_invalid_linkable_type_rejected(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/meetings/{$this->meeting->id}/resolutions", [
                'meeting_id' => $this->meeting->id,
                'kind' => MeetingResolution::KIND_RECOMMENDATION,
                'title' => 'نوع رابط غير صالح',
                'owner_id' => $this->user->id,
                'links' => [
                    [
                        'linkable_type' => 'foo',
                        'linkable_id' => $this->project->id,
                    ],
                ],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['links.0.linkable_type']);
    }

    public function test_invalid_link_role_rejected(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/meetings/{$this->meeting->id}/resolutions", [
                'meeting_id' => $this->meeting->id,
                'kind' => MeetingResolution::KIND_RECOMMENDATION,
                'title' => 'دور رابط غير صالح',
                'owner_id' => $this->user->id,
                'links' => [
                    [
                        'linkable_type' => ResolutionLink::TYPE_PROJECT,
                        'linkable_id' => $this->project->id,
                        'link_role' => 'foo',
                    ],
                ],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['links.0.link_role']);
    }

    public function test_links_replaced_on_update(): void
    {
        // Seed two target projects so we can swap the (type, id, role) on the
        // second link without tripping the unique combo constraint.
        $projectB = Project::factory()->create(['department_id' => $this->dept->id]);

        $resolution = MeetingResolution::create([
            'meeting_id' => $this->meeting->id,
            'organization_id' => $this->project->organization_id,
            'kind' => MeetingResolution::KIND_RECOMMENDATION,
            'title' => 'مخرج برابطين',
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

        $this->assertDatabaseCount('resolution_links', 1);

        // Update with two NEW links — syncLinks wipes the existing rows first,
        // then re-inserts. Use distinct roles to keep the (type,id,role) tuple
        // unique within the resolution.
        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/meeting-resolutions/{$resolution->id}", [
                'links' => [
                    [
                        'linkable_type' => ResolutionLink::TYPE_PROJECT,
                        'linkable_id' => $projectB->id,
                        'link_role' => ResolutionLink::ROLE_RELATED_TO,
                    ],
                    [
                        'linkable_type' => ResolutionLink::TYPE_PROJECT,
                        'linkable_id' => $this->project->id,
                        'link_role' => ResolutionLink::ROLE_IMPLEMENTATION_SCOPE,
                    ],
                ],
            ]);

        $response->assertStatus(200);

        $rows = ResolutionLink::where('resolution_id', $resolution->id)->get();
        $this->assertCount(2, $rows);

        $this->assertDatabaseMissing('resolution_links', [
            'resolution_id' => $resolution->id,
            'linkable_type' => ResolutionLink::TYPE_PROJECT,
            'linkable_id' => $this->project->id,
            'link_role' => ResolutionLink::ROLE_RELATED_TO,
        ]);

        $this->assertDatabaseHas('resolution_links', [
            'resolution_id' => $resolution->id,
            'linkable_type' => ResolutionLink::TYPE_PROJECT,
            'linkable_id' => $projectB->id,
            'link_role' => ResolutionLink::ROLE_RELATED_TO,
        ]);
        $this->assertDatabaseHas('resolution_links', [
            'resolution_id' => $resolution->id,
            'linkable_type' => ResolutionLink::TYPE_PROJECT,
            'linkable_id' => $this->project->id,
            'link_role' => ResolutionLink::ROLE_IMPLEMENTATION_SCOPE,
        ]);
    }
}
