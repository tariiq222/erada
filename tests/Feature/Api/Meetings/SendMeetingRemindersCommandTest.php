<?php

namespace Tests\Feature\Api\Meetings;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Meetings\Notifications\MeetingReminderNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SendMeetingRemindersCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_sends_reminders_for_upcoming_meetings(): void
    {
        Notification::fake();
        $this->seed(RolesAndPermissionsSeeder::class);

        $org = Organization::factory()->create();
        $organizer = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $attendee = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);

        $meeting = Meeting::factory()->create([
            'organization_id' => $org->id,
            'organizer_id' => $organizer->id,
            'status' => Meeting::STATUS_SCHEDULED,
            'scheduled_at' => now()->addHours(20),
            'reminder_sent_at' => null,
        ]);
        $meeting->attendees()->attach($attendee->id);

        $this->artisan('meetings:send-reminders')->assertSuccessful();

        Notification::assertSentTo($attendee, MeetingReminderNotification::class);
        Notification::assertSentTo($organizer, MeetingReminderNotification::class);
        $this->assertNotNull($meeting->fresh()->reminder_sent_at);
    }

    public function test_skips_meetings_outside_window(): void
    {
        Notification::fake();
        $this->seed(RolesAndPermissionsSeeder::class);

        $org = Organization::factory()->create();
        $organizer = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);

        // Meeting in 48h is outside the default 24h window
        Meeting::factory()->create([
            'organization_id' => $org->id,
            'organizer_id' => $organizer->id,
            'status' => Meeting::STATUS_SCHEDULED,
            'scheduled_at' => now()->addHours(48),
            'reminder_sent_at' => null,
        ]);

        $this->artisan('meetings:send-reminders')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_is_idempotent_on_re_run(): void
    {
        Notification::fake();
        $this->seed(RolesAndPermissionsSeeder::class);

        $org = Organization::factory()->create();
        $organizer = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $meeting = Meeting::factory()->create([
            'organization_id' => $org->id,
            'organizer_id' => $organizer->id,
            'status' => Meeting::STATUS_SCHEDULED,
            'scheduled_at' => now()->addHours(20),
            'reminder_sent_at' => null,
        ]);
        $meeting->attendees()->attach($organizer->id);

        $this->artisan('meetings:send-reminders')->assertSuccessful();
        $this->artisan('meetings:send-reminders')->assertSuccessful();

        Notification::assertSentToTimes($organizer, MeetingReminderNotification::class, 1);
    }

    public function test_skips_meetings_already_reminded(): void
    {
        Notification::fake();
        $this->seed(RolesAndPermissionsSeeder::class);

        $org = Organization::factory()->create();
        $organizer = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        Meeting::factory()->create([
            'organization_id' => $org->id,
            'organizer_id' => $organizer->id,
            'status' => Meeting::STATUS_SCHEDULED,
            'scheduled_at' => now()->addHours(20),
            'reminder_sent_at' => now()->subHour(),
        ]);

        $this->artisan('meetings:send-reminders')->assertSuccessful();

        Notification::assertNothingSent();
    }
}
