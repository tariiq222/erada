<?php

namespace Tests\Unit\Shared;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use App\Modules\Shared\Models\ActivityLog;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivityLogsBackfillOrganizationCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_dry_run_does_not_persist(): void
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $project = Project::factory()->create(['department_id' => $dept->id]);

        $log = ActivityLog::query()->create([
            'action' => ActivityLog::ACTION_CREATED,
            'description' => 'backfill-dry-run-test',
            'loggable_type' => Project::class,
            'loggable_id' => $project->id,
            'organization_id' => null,
        ]);

        $this->artisan('activity-logs:backfill-organization', ['--dry-run' => true])
            ->assertExitCode(0);

        $log->refresh();
        $this->assertNull($log->organization_id);
    }

    public function test_fills_organization_id_for_existing_null_rows(): void
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $project = Project::factory()->create(['department_id' => $dept->id]);

        $log = ActivityLog::query()->create([
            'action' => ActivityLog::ACTION_CREATED,
            'description' => 'backfill-fill-test',
            'loggable_type' => Project::class,
            'loggable_id' => $project->id,
            'organization_id' => null,
        ]);

        $this->artisan('activity-logs:backfill-organization')
            ->assertExitCode(0);

        $log->refresh();
        $this->assertEquals($org->id, $log->organization_id);
    }

    public function test_idempotent_on_second_run(): void
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $project = Project::factory()->create(['department_id' => $dept->id]);

        $log = ActivityLog::query()->create([
            'action' => ActivityLog::ACTION_CREATED,
            'description' => 'backfill-idempotent-test',
            'loggable_type' => Project::class,
            'loggable_id' => $project->id,
            'organization_id' => null,
        ]);

        $this->artisan('activity-logs:backfill-organization')->assertExitCode(0);
        $this->artisan('activity-logs:backfill-organization')->assertExitCode(0);

        $log->refresh();
        $this->assertEquals($org->id, $log->organization_id);
    }

    public function test_only_null_false_overwrites_existing(): void
    {
        $orgCorrect = Organization::factory()->create();
        $orgWrong = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $orgCorrect->id]);
        $project = Project::factory()->create(['department_id' => $dept->id]);

        // صف organization_id = orgWrong (legacy خاطئ)، والـ backfill يجب أن يصححه
        // إلى orgCorrect بناءً على سلسلة scope الخاصة بالمشروع.
        $log = ActivityLog::query()->create([
            'action' => ActivityLog::ACTION_CREATED,
            'description' => 'backfill-overwrite-test',
            'loggable_type' => Project::class,
            'loggable_id' => $project->id,
            'organization_id' => $orgWrong->id,
        ]);

        $this->artisan('activity-logs:backfill-organization', ['--only-null' => false])
            ->assertExitCode(0);

        $log->refresh();
        $this->assertEquals($orgCorrect->id, $log->organization_id);
    }
}