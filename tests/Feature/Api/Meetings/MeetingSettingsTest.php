<?php

namespace Tests\Feature\Api\Meetings;

use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Meetings\Models\MeetingSettings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MeetingSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected User $manager;

    protected User $viewer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $dept = Department::factory()->create();

        $this->manager = User::factory()->create(['department_id' => $dept->id, 'is_active' => true]);
        $this->grantCanonicalSuperAdmin($this->manager);

        $this->viewer = User::factory()->create([
            'department_id' => $dept->id,
            'is_active' => true,
            'organization_id' => $this->manager->organization_id,
        ]);
        $this->assignCanonicalRole($this->viewer, 'viewer');
    }

    public function test_show_creates_defaults_on_first_read(): void
    {
        $this->assertDatabaseCount('meeting_settings', 0);

        $this->actingAs($this->manager)
            ->getJson('/api/meeting-settings')
            ->assertOk()
            ->assertJsonPath('data.default_duration_minutes', 60)
            ->assertJsonPath('data.attendee_roles', MeetingSettings::DEFAULT_ATTENDEE_ROLES);

        $this->assertDatabaseCount('meeting_settings', 1);
    }

    public function test_update_persists_and_clears_cache(): void
    {
        $this->actingAs($this->manager)->getJson('/api/meeting-settings');

        $this->actingAs($this->manager)
            ->putJson('/api/meeting-settings', [
                'default_duration_minutes' => 90,
                'reminder_window_hours' => 12,
                'attendee_roles' => ['chair', 'attendee'],
                'default_category_id' => null,
                'agenda_request_enabled' => false,
                'agenda_request_lead_hours' => 24,
                'decision_pending_expiry_days' => 15,
                'recommendation_overdue_grace_days' => 3,
            ])
            ->assertOk()
            ->assertJsonPath('data.default_duration_minutes', 90);

        $this->assertSame(
            90,
            MeetingSettings::forOrganization($this->manager->organization_id)->default_duration_minutes,
        );
    }

    public function test_update_validates_required_attendee_roles(): void
    {
        $this->actingAs($this->manager)
            ->putJson('/api/meeting-settings', [
                'default_duration_minutes' => 60,
                'reminder_window_hours' => 24,
                'attendee_roles' => [],
                'agenda_request_enabled' => true,
                'agenda_request_lead_hours' => 48,
                'decision_pending_expiry_days' => 30,
                'recommendation_overdue_grace_days' => 0,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('attendee_roles');
    }

    public function test_viewer_cannot_update_settings(): void
    {
        $this->actingAs($this->viewer)
            ->putJson('/api/meeting-settings', [
                'default_duration_minutes' => 90,
                'reminder_window_hours' => 12,
                'attendee_roles' => ['chair'],
                'agenda_request_enabled' => false,
                'agenda_request_lead_hours' => 24,
                'decision_pending_expiry_days' => 15,
                'recommendation_overdue_grace_days' => 3,
            ])
            ->assertForbidden();
    }
}
