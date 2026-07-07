<?php

namespace Tests\Feature\HR;

use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Shared\Models\ActivityLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * StructureChangeAuditTest — Phase 6, Task 5.
 *
 * Re-parenting or changing a department's manager silently shifts visibility for a
 * whole subtree, so it is treated as a privileged operation: the observer writes an
 * ActivityLog ('department_restructured') capturing old/new values + the actor.
 */
class StructureChangeAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_changing_department_parent_writes_audit_log(): void
    {
        $a = Department::factory()->create(['parent_id' => null]);
        $b = Department::factory()->create(['parent_id' => null]);
        $dept = Department::factory()->create(['parent_id' => $a->id]);

        $dept->update(['parent_id' => $b->id]);

        $this->assertDatabaseHas('activity_logs', [
            'loggable_type' => Department::class,
            'loggable_id' => $dept->id,
            'action' => 'department_restructured',
        ]);

        $log = ActivityLog::where('action', 'department_restructured')
            ->where('loggable_id', $dept->id)->latest('id')->first();

        $this->assertSame($a->id, $log->old_values['parent_id']);
        $this->assertSame($b->id, $log->new_values['parent_id']);
    }

    public function test_changing_department_manager_writes_audit_log_with_actor(): void
    {
        $actor = User::factory()->create();
        $this->actingAs($actor);

        $manager = User::factory()->create();
        $dept = Department::factory()->create(['parent_id' => null, 'manager_id' => null]);

        $dept->update(['manager_id' => $manager->id]);

        $log = ActivityLog::where('action', 'department_restructured')
            ->where('loggable_id', $dept->id)->latest('id')->first();

        $this->assertNotNull($log);
        $this->assertSame($actor->id, $log->user_id);
        $this->assertNull($log->old_values['manager_id']);
        $this->assertSame($manager->id, $log->new_values['manager_id']);
    }

    public function test_non_structural_update_does_not_write_restructure_log(): void
    {
        $dept = Department::factory()->create(['parent_id' => null]);

        $dept->update(['name' => 'Renamed only']);

        $this->assertDatabaseMissing('activity_logs', [
            'loggable_id' => $dept->id,
            'action' => 'department_restructured',
        ]);
    }
}
