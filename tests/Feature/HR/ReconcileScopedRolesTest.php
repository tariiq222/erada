<?php

namespace Tests\Feature\HR;

use App\Modules\Core\Models\ScopedRole;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\DepartmentCapacityRole;
use Database\Seeders\ScopedDepartmentRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The roles:reconcile command recomputes all source='auto' scoped roles from HR
 * facts and converges drift introduced by updateQuietly()/bulk imports that bypass
 * observers. It must be idempotent.
 */
class ReconcileScopedRolesTest extends TestCase
{
    use RefreshDatabase;

    public function test_reconcile_repairs_drift_from_quiet_updates(): void
    {
        $this->seed(ScopedDepartmentRolesSeeder::class);
        $dept = Department::factory()->create();
        DepartmentCapacityRole::create(['department_id' => $dept->id, 'capacity' => 'member', 'role_key' => 'dept_member']);

        $user = User::factory()->create();
        // updateQuietly bypasses the observer -> no auto role created (drift)
        $user->updateQuietly(['department_id' => $dept->id]);
        $this->assertDatabaseMissing('model_has_scoped_roles', [
            'user_id' => $user->id, 'role' => 'dept_member', 'scope_id' => $dept->id,
        ]);

        $this->artisan('roles:reconcile')->assertExitCode(0);

        $this->assertDatabaseHas('model_has_scoped_roles', [
            'user_id' => $user->id, 'role' => 'dept_member',
            'scope_type' => 'department', 'scope_id' => $dept->id, 'source' => 'auto',
        ]);

        // idempotent: a second run changes nothing
        $before = ScopedRole::where('source', 'auto')->count();
        $this->artisan('roles:reconcile')->assertExitCode(0);
        $this->assertSame($before, ScopedRole::where('source', 'auto')->count());
    }
}
