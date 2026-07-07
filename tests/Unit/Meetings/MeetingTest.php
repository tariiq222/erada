<?php

namespace Tests\Unit\Meetings;

use App\Modules\Meetings\Models\Meeting;
use PHPUnit\Framework\TestCase;

class MeetingTest extends TestCase
{
    public function test_status_values_returns_all_supported_statuses(): void
    {
        $this->assertSame(
            ['scheduled', 'in_progress', 'completed', 'cancelled'],
            Meeting::statusValues()
        );
    }

    public function test_can_transition_from_scheduled(): void
    {
        $m = new Meeting(['status' => Meeting::STATUS_SCHEDULED]);
        $this->assertTrue($m->canTransitionTo(Meeting::STATUS_IN_PROGRESS));
        $this->assertTrue($m->canTransitionTo(Meeting::STATUS_CANCELLED));
        $this->assertFalse($m->canTransitionTo(Meeting::STATUS_COMPLETED));
    }

    public function test_can_transition_from_in_progress(): void
    {
        $m = new Meeting(['status' => Meeting::STATUS_IN_PROGRESS]);
        $this->assertTrue($m->canTransitionTo(Meeting::STATUS_COMPLETED));
        $this->assertTrue($m->canTransitionTo(Meeting::STATUS_CANCELLED));
        $this->assertFalse($m->canTransitionTo(Meeting::STATUS_SCHEDULED));
    }

    public function test_completed_is_terminal(): void
    {
        $m = new Meeting(['status' => Meeting::STATUS_COMPLETED]);
        foreach (Meeting::statusValues() as $status) {
            $this->assertFalse(
                $m->canTransitionTo($status),
                "Completed must not transition to {$status}"
            );
        }
    }

    public function test_cancelled_is_terminal(): void
    {
        $m = new Meeting(['status' => Meeting::STATUS_CANCELLED]);
        foreach (Meeting::statusValues() as $status) {
            $this->assertFalse(
                $m->canTransitionTo($status),
                "Cancelled must not transition to {$status}"
            );
        }
    }

    public function test_status_label_returns_arabic_label(): void
    {
        $m = new Meeting(['status' => Meeting::STATUS_SCHEDULED]);
        $this->assertSame('مجدول', $m->status_label);

        $m2 = new Meeting(['status' => 'unknown']);
        $this->assertSame('غير محدد', $m2->status_label);
    }

    public function test_unique_ids_declares_reference_number(): void
    {
        $m = new Meeting;
        $this->assertSame(['reference_number'], $m->uniqueIds());
    }
}
