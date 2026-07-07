<?php

namespace Tests\Unit\Policies;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Meetings\Policies\MeetingPolicy;
use Database\Seeders\Meetings\MeetingsPermissionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * Unit tests for MeetingPolicy.
 *
 * The policy uses Spatie permissions (view-meetings, manage-meetings) with an
 * org-isolation sameOrg() guard. Key behaviors:
 *   - super_admin: all operations allowed (explicit isSuperAdmin() check)
 *   - admin (has view-meetings + manage-meetings from seeder): allowed with sameOrg
 *   - viewer (no meeting permissions): denied on all operations
 *   - Cross-org: admin from org B denied on org A meeting
 *   - Null org: user with null organization_id → sameOrg() returns false
 */
class MeetingPolicyTest extends TestCase
{
    use GrantsEngineCapability;
    use RefreshDatabase;

    private MeetingPolicy $policy;

    private Organization $org;

    private Department $dept;

    private Meeting $meeting;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(MeetingsPermissionsSeeder::class);
        $this->policy = new MeetingPolicy;
        $this->org = Organization::factory()->create();
        $this->dept = Department::factory()->create(['organization_id' => $this->org->id]);
        $organizer = User::factory()->create(['organization_id' => $this->org->id]);
        $this->meeting = Meeting::factory()->create([
            'organization_id' => $this->org->id,
            'organizer_id' => $organizer->id,
        ]);
    }

    private function makeUser(string $role, ?int $orgId = null): User
    {
        $user = User::factory()->create([
            'organization_id' => $orgId ?? $this->org->id,
            'department_id' => $this->dept->id,
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    // ========== super_admin ==========

    public function test_super_admin_can_view_any(): void
    {
        $sa = $this->makeUser('super_admin');

        $this->assertTrue($this->policy->viewAny($sa));
    }

    public function test_super_admin_can_view_meeting(): void
    {
        $sa = $this->makeUser('super_admin');

        $this->assertTrue($this->policy->view($sa, $this->meeting));
    }

    public function test_super_admin_can_create_meeting(): void
    {
        $sa = $this->makeUser('super_admin');

        $this->assertTrue($this->policy->create($sa));
    }

    public function test_super_admin_can_update_meeting(): void
    {
        $sa = $this->makeUser('super_admin');

        $this->assertTrue($this->policy->update($sa, $this->meeting));
    }

    public function test_super_admin_can_delete_meeting(): void
    {
        $sa = $this->makeUser('super_admin');

        $this->assertTrue($this->policy->delete($sa, $this->meeting));
    }

    // ========== admin with view-meetings + manage-meetings permissions ==========

    public function test_admin_can_view_any(): void
    {
        $admin = $this->makeUser('admin');

        $this->assertTrue($this->policy->viewAny($admin));
    }

    public function test_admin_can_view_same_org_meeting(): void
    {
        $admin = $this->makeUser('admin');

        $this->assertTrue($this->policy->view($admin, $this->meeting));
    }

    public function test_admin_can_create_meeting(): void
    {
        $admin = $this->makeUser('admin');

        $this->assertTrue($this->policy->create($admin));
    }

    public function test_admin_can_update_same_org_meeting(): void
    {
        $admin = $this->makeUser('admin');

        $this->assertTrue($this->policy->update($admin, $this->meeting));
    }

    public function test_admin_can_delete_same_org_meeting(): void
    {
        $admin = $this->makeUser('admin');

        $this->assertTrue($this->policy->delete($admin, $this->meeting));
    }

    // ========== viewer — no meeting permissions ==========

    public function test_viewer_cannot_view_any(): void
    {
        $viewer = $this->makeUser('viewer');

        $this->assertFalse($this->policy->viewAny($viewer));
    }

    public function test_viewer_cannot_view_meeting(): void
    {
        $viewer = $this->makeUser('viewer');

        $this->assertFalse($this->policy->view($viewer, $this->meeting));
    }

    public function test_viewer_cannot_create_meeting(): void
    {
        $viewer = $this->makeUser('viewer');

        $this->assertFalse($this->policy->create($viewer));
    }

    public function test_viewer_cannot_update_meeting(): void
    {
        $viewer = $this->makeUser('viewer');

        $this->assertFalse($this->policy->update($viewer, $this->meeting));
    }

    public function test_viewer_cannot_delete_meeting(): void
    {
        $viewer = $this->makeUser('viewer');

        $this->assertFalse($this->policy->delete($viewer, $this->meeting));
    }

    // ========== Cross-org isolation ==========

    public function test_admin_from_other_org_cannot_view_meeting(): void
    {
        $orgB = Organization::factory()->create();
        $outsider = $this->makeUser('admin', $orgB->id);

        // org B admin has view-meetings permission but different org → sameOrg() fails
        $this->assertFalse($this->policy->view($outsider, $this->meeting));
    }

    public function test_admin_from_other_org_cannot_update_meeting(): void
    {
        $orgB = Organization::factory()->create();
        $outsider = $this->makeUser('admin', $orgB->id);

        $this->assertFalse($this->policy->update($outsider, $this->meeting));
    }

    public function test_admin_from_other_org_cannot_delete_meeting(): void
    {
        $orgB = Organization::factory()->create();
        $outsider = $this->makeUser('admin', $orgB->id);

        $this->assertFalse($this->policy->delete($outsider, $this->meeting));
    }

    // ========== Null-org user ==========

    public function test_null_org_admin_cannot_view_meeting(): void
    {
        $user = User::factory()->create([
            'organization_id' => null,
            'is_active' => true,
        ]);
        $user->assignRole('admin');

        $this->assertFalse($this->policy->view($user, $this->meeting));
    }

    public function test_null_org_admin_cannot_update_meeting(): void
    {
        $user = User::factory()->create([
            'organization_id' => null,
            'is_active' => true,
        ]);
        $user->assignRole('admin');

        $this->assertFalse($this->policy->update($user, $this->meeting));
    }

    // ========== Phase 5.B — before() super_admin hook ==========

    public function test_super_admin_before_hook_short_circuits_to_true(): void
    {
        $sa = $this->makeUser('super_admin');

        $this->assertTrue($this->policy->before($sa, 'viewAny'));
        $this->assertTrue($this->policy->before($sa, 'view'));
        $this->assertTrue($this->policy->before($sa, 'create'));
        $this->assertTrue($this->policy->before($sa, 'update'));
        $this->assertTrue($this->policy->before($sa, 'delete'));
    }

    public function test_non_super_admin_before_hook_returns_null(): void
    {
        $admin = $this->makeUser('admin');

        $this->assertNull($this->policy->before($admin, 'view'));
    }

    // ========== Phase 5.B — null-org floor in viewAny / create ==========

    public function test_null_org_user_cannot_view_any(): void
    {
        $user = User::factory()->create([
            'organization_id' => null,
            'is_active' => true,
        ]);
        $user->assignRole('admin');

        $this->assertFalse($this->policy->viewAny($user));
    }

    public function test_null_org_user_cannot_create(): void
    {
        $user = User::factory()->create([
            'organization_id' => null,
            'is_active' => true,
        ]);
        $user->assignRole('admin');

        $this->assertFalse($this->policy->create($user));
    }

    // ========== Phase 5.B — precheck() cross-org deny ==========

    public function test_cross_org_view_denied_via_precheck_even_with_engine_grant(): void
    {
        $orgB = Organization::factory()->create();
        $deptB = Department::factory()->create(['organization_id' => $orgB->id]);
        $outsider = User::factory()->create([
            'organization_id' => $orgB->id,
            'department_id' => $deptB->id,
            'is_active' => true,
        ]);
        $outsider->assignRole('admin');
        // Org B admin has MEETINGS_VIEW granted at the org level via the engine.
        // Phase 5.B precheck() must still floor it for an org A meeting.
        $this->grantEngineCapability($outsider, Capability::MEETINGS_VIEW, 'organization', $orgB->id);

        $this->assertFalse(
            $this->policy->view($outsider, $this->meeting),
            'precheck() must floor cross-org view even when the engine capability is granted.'
        );
    }

    public function test_cross_org_update_denied_via_precheck_even_with_engine_grant(): void
    {
        $orgB = Organization::factory()->create();
        $deptB = Department::factory()->create(['organization_id' => $orgB->id]);
        $outsider = User::factory()->create([
            'organization_id' => $orgB->id,
            'department_id' => $deptB->id,
            'is_active' => true,
        ]);
        $outsider->assignRole('admin');
        $this->grantEngineCapability($outsider, Capability::MEETINGS_EDIT, 'organization', $orgB->id);

        $this->assertFalse(
            $this->policy->update($outsider, $this->meeting),
            'precheck() must floor cross-org update even when the engine capability is granted.'
        );
    }

    public function test_cross_org_delete_denied_via_precheck_even_with_engine_grant(): void
    {
        $orgB = Organization::factory()->create();
        $deptB = Department::factory()->create(['organization_id' => $orgB->id]);
        $outsider = User::factory()->create([
            'organization_id' => $orgB->id,
            'department_id' => $deptB->id,
            'is_active' => true,
        ]);
        $outsider->assignRole('admin');
        $this->grantEngineCapability($outsider, Capability::MEETINGS_DELETE, 'organization', $orgB->id);

        $this->assertFalse(
            $this->policy->delete($outsider, $this->meeting),
            'precheck() must floor cross-org delete even when the engine capability is granted.'
        );
    }

    // ========== Phase 5.B — same-org with engine capability granted ==========

    public function test_same_org_user_with_engine_capability_can_view(): void
    {
        $orgUser = $this->makeUser('admin');
        $this->grantEngineCapability($orgUser, Capability::MEETINGS_VIEW);

        $this->assertTrue($this->policy->view($orgUser, $this->meeting));
    }

    public function test_same_org_user_with_engine_capability_can_update(): void
    {
        $orgUser = $this->makeUser('admin');
        $this->grantEngineCapability($orgUser, Capability::MEETINGS_EDIT);

        $this->assertTrue($this->policy->update($orgUser, $this->meeting));
    }

    public function test_same_org_user_with_engine_capability_can_delete(): void
    {
        $orgUser = $this->makeUser('admin');
        $this->grantEngineCapability($orgUser, Capability::MEETINGS_DELETE);

        $this->assertTrue($this->policy->delete($orgUser, $this->meeting));
    }
}
