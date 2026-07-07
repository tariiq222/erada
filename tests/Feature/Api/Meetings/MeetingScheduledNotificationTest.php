<?php

namespace Tests\Feature\Api\Meetings;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Meetings\Notifications\MeetingScheduledNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class MeetingScheduledNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_meeting_scheduled_notification_sent_to_organizer_and_attendees(): void
    {
        Notification::fake();
        $this->seed(RolesAndPermissionsSeeder::class);

        $org = Organization::factory()->create();
        $organizer = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $organizer->assignRole('admin');
        $attendee1 = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $attendee2 = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);

        $meeting = Meeting::factory()->create([
            'organization_id' => $org->id,
            'organizer_id' => $organizer->id,
            'title' => 'اجتماع مراجعة البرنامج',
        ]);
        $meeting->attendees()->attach([$attendee1->id, $attendee2->id]);

        $users = $meeting->attendees()->get();
        if (! $users->contains($organizer->id)) {
            $users->push($organizer);
        }
        Notification::send($users, new MeetingScheduledNotification($meeting));

        Notification::assertSentTo($organizer, MeetingScheduledNotification::class);
        Notification::assertSentTo($attendee1, MeetingScheduledNotification::class);
        Notification::assertSentTo($attendee2, MeetingScheduledNotification::class);
    }

    public function test_notification_implements_should_queue_and_uses_mail_and_database(): void
    {
        $n = new MeetingScheduledNotification(Meeting::factory()->make([
            'title' => 'x',
            'scheduled_at' => now(),
        ]));
        $this->assertInstanceOf(ShouldQueue::class, $n);
        $this->assertSame(['mail', 'database'], $n->via(new User));
    }

    public function test_database_payload_contains_required_fields(): void
    {
        $org = Organization::factory()->create();
        $organizer = User::factory()->create(['organization_id' => $org->id]);
        $meeting = Meeting::factory()->create([
            'organization_id' => $org->id,
            'organizer_id' => $organizer->id,
            'title' => 'اجتماع مراجعة البرنامج',
            'reference_number' => 'MTG-2026-0042',
            'scheduled_at' => now()->addDay(),
        ]);

        $payload = (new MeetingScheduledNotification($meeting))->toArray($organizer);

        $this->assertSame('meeting_scheduled', $payload['type']);
        $this->assertSame($meeting->id, $payload['meeting_id']);
        $this->assertSame('MTG-2026-0042', $payload['reference_number']);
        $this->assertSame('اجتماع مراجعة البرنامج', $payload['title']);
        $this->assertArrayHasKey('scheduled_at', $payload);
        $this->assertStringContainsString('اجتماع', $payload['message']);
    }
}
