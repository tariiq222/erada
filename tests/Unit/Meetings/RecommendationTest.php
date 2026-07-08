<?php

namespace Tests\Unit\Meetings;

use App\Modules\Meetings\Models\Recommendation;
use PHPUnit\Framework\TestCase;

class RecommendationTest extends TestCase
{
    public function test_status_values_lists_all_supported(): void
    {
        // Direction B (merged on main @ 3435e05) extended Recommendation with
        // ruling-side statuses (`pending` -> approved/rejected/deferred) in
        // addition to the action-item side. The model exposes both shapes
        // (one Recommendation can be either a `ruling` or an `action_item`),
        // so the canonical statusValues() list is the union of both lifecycles.
        $this->assertSame(
            ['proposed', 'accepted', 'pending', 'approved', 'rejected', 'deferred', 'completed'],
            Recommendation::statusValues()
        );
    }

    public function test_priority_values_lists_all_supported(): void
    {
        $this->assertSame(
            ['low', 'medium', 'high', 'critical'],
            Recommendation::priorityValues()
        );
    }

    public function test_can_transition_from_proposed(): void
    {
        // Action-item lifecycle: proposed -> {accepted, rejected, deferred}.
        // The model branches on `kind` (ruling vs action_item), so the test
        // must set the kind explicitly to exercise the action-item branch.
        $r = new Recommendation([
            'kind' => Recommendation::KIND_ACTION_ITEM,
            'status' => Recommendation::STATUS_PROPOSED,
        ]);
        $this->assertTrue($r->canTransitionTo(Recommendation::STATUS_ACCEPTED));
        $this->assertTrue($r->canTransitionTo(Recommendation::STATUS_REJECTED));
        $this->assertTrue($r->canTransitionTo(Recommendation::STATUS_DEFERRED));
        $this->assertFalse($r->canTransitionTo(Recommendation::STATUS_COMPLETED));
    }

    public function test_can_transition_from_accepted(): void
    {
        $r = new Recommendation([
            'kind' => Recommendation::KIND_ACTION_ITEM,
            'status' => Recommendation::STATUS_ACCEPTED,
        ]);
        $this->assertTrue($r->canTransitionTo(Recommendation::STATUS_COMPLETED));
        $this->assertTrue($r->canTransitionTo(Recommendation::STATUS_DEFERRED));
        $this->assertFalse($r->canTransitionTo(Recommendation::STATUS_REJECTED));
    }

    public function test_rejected_is_terminal(): void
    {
        $r = new Recommendation(['status' => Recommendation::STATUS_REJECTED]);
        foreach (Recommendation::statusValues() as $s) {
            $this->assertFalse($r->canTransitionTo($s), "Rejected must not transition to {$s}");
        }
    }

    public function test_completed_is_terminal(): void
    {
        $r = new Recommendation(['status' => Recommendation::STATUS_COMPLETED]);
        foreach (Recommendation::statusValues() as $s) {
            $this->assertFalse($r->canTransitionTo($s), "Completed must not transition to {$s}");
        }
    }

    public function test_is_overdue_when_status_not_completed_and_due_date_past(): void
    {
        $r = new Recommendation(['status' => Recommendation::STATUS_ACCEPTED, 'due_date' => now()->subDay()]);
        $this->assertTrue($r->is_overdue);
    }

    public function test_not_overdue_when_status_completed(): void
    {
        $r = new Recommendation(['status' => Recommendation::STATUS_COMPLETED, 'due_date' => now()->subDay()]);
        $this->assertFalse($r->is_overdue);
    }

    public function test_status_label_returns_arabic(): void
    {
        $r = new Recommendation(['status' => Recommendation::STATUS_PROPOSED]);
        $this->assertSame('مقترح', $r->status_label);
        $r2 = new Recommendation(['status' => 'unknown']);
        $this->assertSame('غير محدد', $r2->status_label);
    }

    public function test_priority_label_returns_arabic(): void
    {
        $r = new Recommendation(['priority' => Recommendation::PRIORITY_HIGH]);
        $this->assertSame('عالية', $r->priority_label);
        $r2 = new Recommendation(['priority' => 'unknown']);
        $this->assertSame('غير محدد', $r2->priority_label);
    }

    public function test_unique_ids_declares_reference_number(): void
    {
        $r = new Recommendation;
        $this->assertSame(['reference_number'], $r->uniqueIds());
    }
}
