<?php

namespace Tests\Feature\Meetings;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Meetings\Models\MeetingAgendaItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

class ClusterTreeAgendaWriteBoundaryTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    public function test_cluster_read_user_cannot_create_an_agenda_item_in_a_descendant_organization(): void
    {
        $cluster = Organization::factory()->cluster()->create();
        $hospital = Organization::factory()->hospital()->childOf($cluster)->create();

        $clusterReader = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($clusterReader, [
            Capability::MEETINGS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ], 'organization', $cluster->id);

        $organizer = User::factory()->create(['organization_id' => $hospital->id]);
        $meeting = Meeting::factory()->create([
            'organization_id' => $hospital->id,
            'organizer_id' => $organizer->id,
        ]);

        $this->actingAs($clusterReader, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda-items", [
                'title' => 'Cross-organization injection attempt',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('meeting_agenda_items', [
            'meeting_id' => $meeting->id,
            'title' => 'Cross-organization injection attempt',
        ]);
    }

    public function test_cluster_read_user_cannot_list_a_descendant_meetings_agenda_items(): void
    {
        $cluster = Organization::factory()->cluster()->create();
        $hospital = Organization::factory()->hospital()->childOf($cluster)->create();

        $clusterReader = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($clusterReader, [
            Capability::MEETINGS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ], 'organization', $cluster->id);

        $organizer = User::factory()->create(['organization_id' => $hospital->id]);
        $meeting = Meeting::factory()->create([
            'organization_id' => $hospital->id,
            'organizer_id' => $organizer->id,
        ]);
        $meeting->agendaItems()->create([
            'title' => 'Internal agenda item',
            'proposed_by_id' => $organizer->id,
            'status' => MeetingAgendaItem::STATUS_APPROVED,
            'organization_id' => $hospital->id,
        ]);

        $this->actingAs($clusterReader, 'sanctum')
            ->getJson("/api/meetings/{$meeting->id}/agenda-items")
            ->assertForbidden();
    }
}
