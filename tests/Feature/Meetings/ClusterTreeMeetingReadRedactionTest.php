<?php

namespace Tests\Feature\Meetings;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Strategy\Models\Portfolio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

class ClusterTreeMeetingReadRedactionTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    public function test_cluster_reader_receives_redacted_meeting_details_and_attendee_pii(): void
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
        ], 'organization', $cluster->id, null, ['inherit_to_children' => true]);

        $organizer = User::factory()->create(['organization_id' => $hospital->id]);
        $attendee = User::factory()->create([
            'organization_id' => $hospital->id,
            'email' => 'attendee-private@example.test',
            'phone' => '+966500000000',
        ]);
        $portfolio = Portfolio::factory()->create([
            'organization_id' => $hospital->id,
            'created_by' => $organizer->id,
        ]);
        $meeting = Meeting::factory()->create([
            'organization_id' => $hospital->id,
            'organizer_id' => $organizer->id,
            'agenda' => 'Internal agenda details',
            'minutes' => 'Confidential meeting minutes',
            'subject_type' => Portfolio::class,
            'subject_id' => $portfolio->id,
            'virtual_link' => 'https://meet.example.test/private-link',
        ]);
        $meeting->attendees()->attach($attendee->id, ['role' => 'attendee']);

        $indexResponse = $this->actingAs($clusterReader, 'sanctum')
            ->getJson('/api/meetings');

        $indexResponse->assertOk()
            ->assertJsonStructure(['current_page', 'data'])
            ->assertJsonPath('data.0.agenda', null)
            ->assertJsonPath('data.0.minutes', null)
            ->assertJsonPath('data.0.virtual_link', null)
            ->assertJsonPath('data.0.subject.id', $portfolio->id)
            ->assertJsonPath('data.0.subject.name', $portfolio->name)
            ->assertJsonMissingPath('data.0.subject.organization_id')
            ->assertJsonMissingPath('data.0.subject.description');

        $showResponse = $this->actingAs($clusterReader, 'sanctum')
            ->getJson("/api/meetings/{$meeting->id}");

        $showResponse->assertOk()
            ->assertJsonPath('agenda', null)
            ->assertJsonPath('minutes', null)
            ->assertJsonPath('virtual_link', null)
            ->assertJsonPath('subject.id', $portfolio->id)
            ->assertJsonPath('subject.name', $portfolio->name)
            ->assertJsonMissingPath('subject.organization_id')
            ->assertJsonMissingPath('subject.description');

        $attendeesResponse = $this->actingAs($clusterReader, 'sanctum')
            ->getJson("/api/meetings/{$meeting->id}/attendees");

        $attendeesResponse->assertOk();
        $attendeePayload = $attendeesResponse->json('data.0');
        $this->assertArrayNotHasKey('email', $attendeePayload);
        $this->assertArrayNotHasKey('phone', $attendeePayload);
    }
}
