<?php

namespace Tests\Unit\Strategy\Services;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Shared\Models\ActivityLog;
use App\Modules\Strategy\Models\Portfolio;
use App\Modules\Strategy\Models\Program;
use App\Modules\Strategy\Services\PortfolioDecisionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class PortfolioDecisionServiceTest extends TestCase
{
    use RefreshDatabase;

    protected PortfolioDecisionService $service;

    protected Organization $org;

    protected User $user;

    protected Portfolio $portfolio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->service = new PortfolioDecisionService;

        $this->org = Organization::factory()->create();
        $this->user = User::factory()->create([
            'organization_id' => $this->org->id,
        ]);
        $this->portfolio = Portfolio::factory()->create([
            'organization_id' => $this->org->id,
            'portfolio_status' => 'active',
        ]);
    }

    public function test_log_force_close_decision_writes_activity_log_with_full_context(): void
    {
        // Snap a request so request()->ip() inside the service returns something.
        request()->replace([]);
        $this->app->instance('request', Request::create('/test', 'POST'));

        Program::factory()->create([
            'portfolio_id' => $this->portfolio->id,
            'organization_id' => $this->org->id,
            'status' => 'draft',
            'name' => 'البرنامج أ',
        ]);
        Program::factory()->create([
            'portfolio_id' => $this->portfolio->id,
            'organization_id' => $this->org->id,
            'status' => 'in_progress',
            'name' => 'البرنامج ب',
        ]);

        $this->service->logForceCloseDecision(
            $this->portfolio,
            $this->user,
            'اعتُمد الإغلاق من قبل الإدارة العليا'
        );

        $log = ActivityLog::where('action', 'portfolio_force_closed')->latest('id')->first();

        $this->assertNotNull($log);
        $this->assertSame($this->user->id, $log->user_id);
        $this->assertSame(Portfolio::class, $log->loggable_type);
        // loggable_id is stored without an integer cast, so PG returns it as string.
        $this->assertEquals($this->portfolio->id, $log->loggable_id);

        // Old values should snapshot the previous strategic status and the
        // list of in-flight programs at the time of the close.
        $this->assertSame('active', $log->old_values['portfolio_status']);
        $this->assertCount(2, $log->old_values['active_programs']);

        $byName = collect($log->old_values['active_programs'])->keyBy('name');
        $this->assertSame('البرنامج أ', $byName['البرنامج أ']['name']);
        $this->assertSame('البرنامج ب', $byName['البرنامج ب']['name']);
        $this->assertSame('draft', $byName['البرنامج أ']['status']);
        $this->assertSame('in_progress', $byName['البرنامج ب']['status']);

        // New values capture the closed status, note, and force-close flag.
        $this->assertSame('closed_strategically', $log->new_values['portfolio_status']);
        $this->assertTrue($log->new_values['force_closed']);
        $this->assertSame('اعتُمد الإغلاق من قبل الإدارة العليا', $log->new_values['decision_note']);
        $this->assertNotEmpty($log->new_values['closed_at']);
    }

    public function test_log_force_close_decision_works_with_null_decision_note(): void
    {
        $this->app->instance('request', Request::create('/test', 'POST'));

        $this->service->logForceCloseDecision($this->portfolio, $this->user);

        $log = ActivityLog::where('action', 'portfolio_force_closed')->latest('id')->first();

        $this->assertNotNull($log);
        $this->assertNull($log->new_values['decision_note']);
    }

    public function test_log_force_close_decision_excludes_completed_and_cancelled_programs(): void
    {
        // The service's query specifically pulls only draft and in_progress;
        // completed/cancelled programs must NOT appear in active_programs.
        $this->app->instance('request', Request::create('/test', 'POST'));

        Program::factory()->create([
            'portfolio_id' => $this->portfolio->id,
            'organization_id' => $this->org->id,
            'status' => 'in_progress',
        ]);
        Program::factory()->create([
            'portfolio_id' => $this->portfolio->id,
            'organization_id' => $this->org->id,
            'status' => 'completed',
        ]);
        Program::factory()->create([
            'portfolio_id' => $this->portfolio->id,
            'organization_id' => $this->org->id,
            'status' => 'cancelled',
        ]);

        $this->service->logForceCloseDecision($this->portfolio, $this->user);

        $log = ActivityLog::where('action', 'portfolio_force_closed')->latest('id')->first();
        $this->assertCount(1, $log->old_values['active_programs']);
        $this->assertSame('in_progress', $log->old_values['active_programs'][0]['status']);
    }

    public function test_log_force_close_decision_returns_empty_active_programs_when_no_programs(): void
    {
        $this->app->instance('request', Request::create('/test', 'POST'));

        $this->service->logForceCloseDecision($this->portfolio, $this->user);

        $log = ActivityLog::where('action', 'portfolio_force_closed')->latest('id')->first();
        $this->assertSame([], $log->old_values['active_programs']);
    }

    public function test_log_strategic_status_change_writes_activity_log(): void
    {
        $this->app->instance('request', Request::create('/test', 'POST'));

        $this->service->logStrategicStatusChange(
            $this->portfolio,
            $this->user,
            'active',
            'frozen',
            'تجميد مؤقت'
        );

        $log = ActivityLog::where('action', 'portfolio_strategic_status_changed')->latest('id')->first();

        $this->assertNotNull($log);
        $this->assertSame($this->user->id, $log->user_id);
        $this->assertSame(Portfolio::class, $log->loggable_type);
        $this->assertEquals($this->portfolio->id, $log->loggable_id);
        $this->assertSame('active', $log->old_values['portfolio_status']);
        $this->assertSame('frozen', $log->new_values['portfolio_status']);
        $this->assertSame('تجميد مؤقت', $log->new_values['decision_note']);
        $this->assertNotEmpty($log->new_values['changed_at']);
    }

    public function test_log_strategic_status_change_with_null_note_stores_null(): void
    {
        $this->app->instance('request', Request::create('/test', 'POST'));

        $this->service->logStrategicStatusChange(
            $this->portfolio,
            $this->user,
            'active',
            'rebalancing'
        );

        $log = ActivityLog::where('action', 'portfolio_strategic_status_changed')->latest('id')->first();
        $this->assertNull($log->new_values['decision_note']);
        $this->assertSame('rebalancing', $log->new_values['portfolio_status']);
    }

    public function test_log_priority_change_persists_old_and_new_value_diffs(): void
    {
        $this->app->instance('request', Request::create('/test', 'POST'));

        // Use non-whole floats so PHP doesn't collapse them to int after the
        // json → array round-trip through old_values/new_values.
        $oldValues = ['priority_rank' => 3, 'weight' => 25.5];
        $newValues = ['priority_rank' => 7, 'weight' => 60.25];

        $this->service->logPriorityChange(
            $this->portfolio,
            $this->user,
            $oldValues,
            $newValues
        );

        $log = ActivityLog::where('action', 'portfolio_priority_changed')->latest('id')->first();

        $this->assertNotNull($log);
        $this->assertSame($this->user->id, $log->user_id);
        $this->assertSame(Portfolio::class, $log->loggable_type);
        $this->assertEquals($this->portfolio->id, $log->loggable_id);
        $this->assertSame($oldValues, $log->old_values);
        $this->assertSame($newValues, $log->new_values);
    }

    public function test_log_priority_change_does_not_invoke_strategic_or_force_close_actions(): void
    {
        $this->app->instance('request', Request::create('/test', 'POST'));

        $this->service->logPriorityChange(
            $this->portfolio,
            $this->user,
            ['priority_rank' => 1],
            ['priority_rank' => 2]
        );

        $this->assertSame(1, ActivityLog::where('action', 'portfolio_priority_changed')->count());
        $this->assertSame(0, ActivityLog::where('action', 'portfolio_strategic_status_changed')->count());
        $this->assertSame(0, ActivityLog::where('action', 'portfolio_force_closed')->count());
    }

    public function test_ip_address_is_pulled_from_current_request(): void
    {
        $request = Request::create('/test', 'POST', [], [], [], ['REMOTE_ADDR' => '203.0.113.42']);
        $this->app->instance('request', $request);

        $this->service->logStrategicStatusChange(
            $this->portfolio,
            $this->user,
            'active',
            'frozen'
        );

        $log = ActivityLog::where('action', 'portfolio_strategic_status_changed')->latest('id')->first();
        $this->assertSame('203.0.113.42', $log->ip_address);
    }
}
