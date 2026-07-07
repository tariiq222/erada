<?php

namespace Tests\Feature\HR;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * ReconcileScheduleTest — Phase 6, Task 2.
 *
 * Asserts the auto-grant reconcile runs unattended nightly so scoped-role drift
 * from bulk HR edits self-heals without manual intervention.
 */
class ReconcileScheduleTest extends TestCase
{
    public function test_reconcile_is_scheduled_nightly(): void
    {
        $events = app(Schedule::class)->events();

        $event = collect($events)->first(
            fn ($e) => str_contains($e->command ?? '', 'roles:reconcile')
        );

        $this->assertNotNull($event, 'roles:reconcile must be registered in the schedule');
        $this->assertStringContainsString('0 2 * * *', $event->expression, 'roles:reconcile must run daily at 02:00');
    }
}
