<?php

namespace Tests\Unit\Shared\Policies;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Shared\Models\ActivityLog;
use App\Modules\Shared\Policies\ActivityLogPolicy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivityLogPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_view_any_delegates_to_access_decision_for_super_admin(): void
    {
        $org = Organization::factory()->create();
        $dept = \App\Modules\HR\Models\Department::factory()->create(['organization_id' => $org->id]);
        $admin = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
        ]);
        $admin->assignRole('super_admin');

        $this->assertTrue((new ActivityLogPolicy)->viewAny($admin));
    }

    public function test_view_super_admin_returns_true_regardless_of_org(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        $log = new ActivityLog(['organization_id' => 999, 'action' => 'login']);
        $log->id = 1;

        $this->assertTrue((new ActivityLogPolicy)->view($admin, $log));
    }

    public function test_view_same_org_returns_true_for_normal_user(): void
    {
        $org = Organization::factory()->create();
        $dept = \App\Modules\HR\Models\Department::factory()->create(['organization_id' => $org->id]);
        $user = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
        ]);

        $log = new ActivityLog(['organization_id' => $org->id, 'action' => 'login']);
        $log->id = 1;

        $this->assertTrue((new ActivityLogPolicy)->view($user, $log));
    }

    public function test_view_other_org_returns_false_for_normal_user(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $dept = \App\Modules\HR\Models\Department::factory()->create(['organization_id' => $orgA->id]);
        $user = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $dept->id,
        ]);

        $log = new ActivityLog(['organization_id' => $orgB->id, 'action' => 'login']);
        $log->id = 1;

        $this->assertFalse((new ActivityLogPolicy)->view($user, $log));
    }

    public function test_view_org_null_returns_false_for_normal_user(): void
    {
        $org = Organization::factory()->create();
        $dept = \App\Modules\HR\Models\Department::factory()->create(['organization_id' => $org->id]);
        $user = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
        ]);

        $log = new ActivityLog(['organization_id' => null, 'action' => 'login_failed']);
        $log->id = 1;

        $this->assertFalse((new ActivityLogPolicy)->view($user, $log));
    }
}