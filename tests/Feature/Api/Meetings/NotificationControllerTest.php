<?php

namespace Tests\Feature\Api\Meetings;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Meetings\Notifications\MeetingReminderNotification;
use App\Modules\Meetings\Notifications\MeetingScheduledNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(Organization $org, string $role = 'admin'): User
    {
        $user = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $this->assignCanonicalRole($user, $role);

        return $user;
    }

    public function test_index_returns_authenticated_users_notifications(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $org = Organization::factory()->create();
        $user = $this->makeUser($org);
        $meeting = Meeting::factory()->create(['organization_id' => $org->id, 'organizer_id' => $user->id]);
        $user->notify(new MeetingScheduledNotification($meeting));

        $response = $this->actingAs($user)->getJson('/api/notifications');
        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_unread_count_returns_correct_count(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $org = Organization::factory()->create();
        $user = $this->makeUser($org);
        $meeting = Meeting::factory()->create(['organization_id' => $org->id, 'organizer_id' => $user->id]);
        $user->notify(new MeetingScheduledNotification($meeting));
        $user->notify(new MeetingScheduledNotification($meeting));

        $this->actingAs($user)
            ->getJson('/api/notifications/unread-count')
            ->assertOk()
            ->assertJson(['unread' => 2]);
    }

    public function test_mark_read_marks_single_notification(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $org = Organization::factory()->create();
        $user = $this->makeUser($org);
        $meeting = Meeting::factory()->create(['organization_id' => $org->id, 'organizer_id' => $user->id]);
        $user->notify(new MeetingScheduledNotification($meeting));
        $notifId = $user->notifications()->first()->id;

        $this->actingAs($user)
            ->postJson("/api/notifications/{$notifId}/read")
            ->assertOk()
            ->assertJson(['message' => 'تم وضع علامة مقروء']);

        $this->assertNotNull($user->notifications()->find($notifId)->read_at);
    }

    public function test_mark_all_read_marks_all_unread_notifications(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $org = Organization::factory()->create();
        $user = $this->makeUser($org);
        $meeting = Meeting::factory()->create(['organization_id' => $org->id, 'organizer_id' => $user->id]);
        $user->notify(new MeetingScheduledNotification($meeting));
        $user->notify(new MeetingScheduledNotification($meeting));

        $this->actingAs($user)
            ->postJson('/api/notifications/read-all')
            ->assertOk()
            ->assertJson(['message' => 'تم وضع علامة مقروء على الكل']);

        $this->assertSame(0, $user->fresh()->unreadNotifications()->count());
    }

    public function test_user_cannot_mark_another_users_notification(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $org = Organization::factory()->create();
        $alice = $this->makeUser($org);
        $bob = $this->makeUser($org);
        $meeting = Meeting::factory()->create(['organization_id' => $org->id, 'organizer_id' => $alice->id]);
        $alice->notify(new MeetingScheduledNotification($meeting));
        $notifId = $alice->notifications()->first()->id;

        $this->actingAs($bob)
            ->postJson("/api/notifications/{$notifId}/read")
            ->assertNotFound();
    }

    public function test_index_unread_only_filter(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $org = Organization::factory()->create();
        $user = $this->makeUser($org);
        $meeting = Meeting::factory()->create(['organization_id' => $org->id, 'organizer_id' => $user->id]);
        $user->notify(new MeetingScheduledNotification($meeting));
        $first = $user->notifications()->first();
        $first->markAsRead();
        $user->notify(new MeetingScheduledNotification($meeting));

        $response = $this->actingAs($user)
            ->getJson('/api/notifications?unread_only=1');
        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_index_rejects_unknown_type_with_422(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $org = Organization::factory()->create();
        $user = $this->makeUser($org);

        $this->actingAs($user)
            ->getJson('/api/notifications?type=App\\Some\\Internal\\PrivateClass')
            ->assertUnprocessable();

        $this->actingAs($user)
            ->getJson('/api/notifications?type=../../../etc/passwd')
            ->assertUnprocessable();
    }

    public function test_index_valid_type_filter_returns_matching_notifications(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $org = Organization::factory()->create();
        $user = $this->makeUser($org);
        $meeting = Meeting::factory()->create(['organization_id' => $org->id, 'organizer_id' => $user->id]);

        $user->notify(new MeetingScheduledNotification($meeting));
        $user->notify(new MeetingReminderNotification($meeting));

        $response = $this->actingAs($user)
            ->getJson('/api/notifications?type=MeetingScheduledNotification');
        $response->assertOk();
        $this->assertCount(1, $response->json('data'));

        $response = $this->actingAs($user)
            ->getJson('/api/notifications?type=MeetingReminderNotification');
        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }
}
