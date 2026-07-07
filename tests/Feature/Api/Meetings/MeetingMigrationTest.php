<?php

namespace Tests\Feature\Api\Meetings;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MeetingMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_meetings_table_has_expected_columns(): void
    {
        $expected = [
            'id', 'reference_number', 'title', 'description',
            'scheduled_at', 'duration_minutes', 'location', 'virtual_link',
            'agenda', 'minutes', 'status', 'organizer_id',
            'subject_type', 'subject_id', 'organization_id',
            'reminder_sent_at',
            'created_at', 'updated_at', 'deleted_at',
        ];
        foreach ($expected as $column) {
            $this->assertTrue(
                Schema::hasColumn('meetings', $column),
                "meetings table is missing column: {$column}"
            );
        }
    }

    public function test_meeting_attendees_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('meeting_attendees'));
        foreach (['meeting_id', 'user_id', 'role', 'attended', 'created_at', 'updated_at'] as $column) {
            $this->assertTrue(
                Schema::hasColumn('meeting_attendees', $column),
                "meeting_attendees is missing column: {$column}"
            );
        }
    }
}
