<?php

namespace Tests\Feature\Meetings;

use App\Modules\Core\Models\Organization;
use App\Modules\Meetings\Models\Meeting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MeetingObserverTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_meeting_auto_assigns_reference_number(): void
    {
        $org = Organization::factory()->create();
        $m = Meeting::factory()->create([
            'organization_id' => $org->id,
            'reference_number' => null,
        ]);
        $this->assertNotNull($m->reference_number);
        $this->assertMatchesRegularExpression('/^MTG-\d{4}-\d{4}$/', $m->reference_number);
    }

    public function test_existing_reference_number_is_not_overwritten(): void
    {
        $org = Organization::factory()->create();
        $m = Meeting::factory()->create([
            'organization_id' => $org->id,
            'reference_number' => 'MTG-2099-9999',
        ]);
        $this->assertSame('MTG-2099-9999', $m->fresh()->reference_number);
    }
}
