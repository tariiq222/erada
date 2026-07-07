<?php

namespace Tests\Feature\Api\Meetings;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Meetings\Notifications\MeetingReminderNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MeetingReminderNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_meeting_reminder_has_correct_shape(): void
    {
        $org = Organization::factory()->create();
        $organizer = User::factory()->create(['organization_id' => $org->id]);

        $meeting = Meeting::factory()->create([
            'organization_id' => $org->id,
            'organizer_id' => $organizer->id,
            'title' => 'اجتماع مراجعة البرنامج',
            'reference_number' => 'MTG-2026-0042',
            'scheduled_at' => now()->addHours(20),
        ]);

        $n = new MeetingReminderNotification($meeting);

        $this->assertInstanceOf(ShouldQueue::class, $n);
        $this->assertSame(['mail', 'database'], $n->via($organizer));

        $payload = $n->toArray($organizer);
        $this->assertSame('meeting_reminder', $payload['type']);
        $this->assertSame($meeting->id, $payload['meeting_id']);
        $this->assertSame('MTG-2026-0042', $payload['reference_number']);
        $this->assertSame('اجتماع مراجعة البرنامج', $payload['title']);
        $this->assertArrayHasKey('scheduled_at', $payload);
        $this->assertStringContainsString('تذكير', $payload['message']);
    }
}
