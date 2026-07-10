<?php

namespace Tests\Unit\Shared\Policies;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Shared\Models\ActivityLog;
use App\Modules\Shared\Policies\ActivityLogPolicy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

class ActivityLogPolicyTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_view_any_delegates_to_access_decision_for_super_admin(): void
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
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

    public function test_view_same_org_user_without_audit_view_is_denied(): void
    {
        // Phase 1A — AUDIT_VIEW gate on same-org show. A normal user in
        // the same organization as the log row, but holding NO
        // AUDIT_VIEW capability on actor.org, must be denied. The same-org
        // path can no longer be reached by anyone who is not also an
        // authorized auditor. (Pre-Phase-1 code returned true here; that
        // contract closed a cross-tenant disclosure path.)
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $user = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
        ]);

        $log = new ActivityLog(['organization_id' => $org->id, 'action' => 'login']);
        $log->id = 1;

        $this->assertFalse((new ActivityLogPolicy)->view($user, $log));
    }

    public function test_view_same_org_user_with_audit_view_returns_true(): void
    {
        // Same-org path is reachable ONLY when AccessDecision::can(AUDIT_VIEW)
        // also returns true on actor.org. The strict-equality org match is
        // necessary but not sufficient.
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $user = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
        ]);
        $this->grantEngineCapability($user, Capability::AUDIT_VIEW, 'organization', $org->id);

        $log = new ActivityLog(['organization_id' => $org->id, 'action' => 'login']);
        $log->id = 1;

        $this->assertTrue((new ActivityLogPolicy)->view($user, $log));
    }

    public function test_view_same_org_user_with_audit_export_but_no_audit_view_is_denied(): void
    {
        // AUDIT_EXPORT alone does NOT widen the read path. A user whose
        // sole audit grant is AUDIT_EXPORT must still be denied show().
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $user = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
        ]);
        $this->grantEngineCapability($user, Capability::AUDIT_EXPORT, 'organization', $org->id);

        $log = new ActivityLog(['organization_id' => $org->id, 'action' => 'login']);
        $log->id = 1;

        $this->assertFalse((new ActivityLogPolicy)->view($user, $log));
    }

    public function test_view_other_org_returns_false_for_normal_user(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $orgA->id]);
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
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $user = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
        ]);

        $log = new ActivityLog(['organization_id' => null, 'action' => 'login_failed']);
        $log->id = 1;

        $this->assertFalse((new ActivityLogPolicy)->view($user, $log));
    }
}
