<?php

namespace Tests\Feature;

use Illuminate\Bus\Queueable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class QueueSchedulerHardeningTest extends TestCase
{
    public function test_async_queue_connections_are_configured_after_commit(): void
    {
        foreach (['database', 'beanstalkd', 'sqs', 'redis'] as $connection) {
            $this->assertTrue(
                config("queue.connections.{$connection}.after_commit"),
                "Queue connection [{$connection}] must defer queued work until after database commit."
            );
        }
    }

    public function test_after_commit_queued_work_is_not_pushed_when_transaction_rolls_back(): void
    {
        Queue::fake();

        try {
            DB::transaction(function (): void {
                DB::afterCommit(fn () => Queue::push(new QueueSchedulerHardeningProbeJob));

                throw new RuntimeException('force rollback');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('force rollback', $exception->getMessage());
        }

        Queue::assertNothingPushed();
    }

    public function test_after_commit_queued_work_is_pushed_only_after_transaction_commits(): void
    {
        Queue::fake();

        DB::transaction(function (): void {
            DB::afterCommit(fn () => Queue::push(new QueueSchedulerHardeningProbeJob));

            Queue::assertNothingPushed();
        });

        Queue::assertPushed(QueueSchedulerHardeningProbeJob::class, 1);
    }

    public function test_known_transaction_bound_dispatch_sites_have_after_commit_boundaries(): void
    {
        $responseService = file_get_contents(base_path('app/Modules/Surveys/Services/ResponseService.php'));
        $this->assertSame(2, substr_count($responseService, 'DB::afterCommit(fn () => $survey->creator?->notify(new NewSurveyResponseNotification'));

        $dataMappingService = file_get_contents(base_path('app/Modules/Surveys/Services/DataMappingService.php'));
        $this->assertStringContainsString('DB::afterCommit(fn () => $this->notifyAdminsAboutPendingImport($request))', $dataMappingService);

        $incidentReportController = file_get_contents(base_path('app/Modules/OVR/Http/Controllers/IncidentReportController.php'));
        $this->assertTrue(
            strpos($incidentReportController, 'DB::transaction(function () use ($report, $updates') < strpos($incidentReportController, '// Notify the reporter of the status change.'),
            'OVR status-change notifications must remain after the status transaction returns.'
        );
        $this->assertTrue(
            strpos($incidentReportController, 'DB::transaction(function () use ($report, $oldStatus') < strpos($incidentReportController, '// Notify reviewers'),
            'OVR submission notifications must remain after the submission transaction returns.'
        );

        $riskLifecycleService = file_get_contents(base_path('app/Modules/RiskManagement/Services/RiskLifecycleService.php'));
        $this->assertStringContainsString('DB::afterCommit(fn () => $risk->owner->notify(new RiskLevelEscalatedNotification', $riskLifecycleService);
    }

    public function test_state_mutating_scheduled_commands_have_overlap_and_single_server_locks_without_frequency_changes(): void
    {
        $expected = [
            'surveys:expire' => '0 * * * *',
            'ovr:archive-closed' => '0 0 * * *',
            'ovr:notify-sla-due' => '0 * * * *',
            'ovr:notify-pending-timeout' => '0 0 * * *',
            'risks:check-due-evaluations' => '0 7 * * *',
            'risks:notify-overdue-actions' => '0 7 * * *',
        ];

        foreach ($expected as $command => $expression) {
            $event = $this->scheduledEventFor($command);

            $this->assertNotNull($event, "Scheduled command [{$command}] was not registered.");
            $this->assertSame($expression, $this->eventProperty($event, 'expression'), "Scheduled command [{$command}] frequency changed.");
            $this->assertTrue($this->eventProperty($event, 'withoutOverlapping'), "Scheduled command [{$command}] must use withoutOverlapping().");
            $this->assertTrue($this->eventProperty($event, 'onOneServer'), "Scheduled command [{$command}] must use onOneServer().");
        }
    }

    private function scheduledEventFor(string $command): ?Event
    {
        return collect(app(Schedule::class)->events())
            ->first(fn (Event $event) => str_contains((string) $this->eventProperty($event, 'command'), $command));
    }

    private function eventProperty(Event $event, string $property): mixed
    {
        return (fn () => $this->{$property} ?? null)->call($event);
    }
}

class QueueSchedulerHardeningProbeJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public function handle(): void
    {
        // No-op probe job for queue transaction boundary regression tests.
    }
}
