<?php

namespace Tests\Unit\Policies;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Meetings\Models\Recommendation;
use App\Modules\Meetings\Policies\RecommendationPolicy;
use Database\Seeders\Meetings\MeetingsPermissionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * Unit tests for RecommendationPolicy.
 *
 * Mirrors MeetingPolicy structure. Key behaviors:
 *   - super_admin: all operations allowed
 *   - admin (has view-meetings + record-decisions): allowed with sameOrg
 *   - viewer: denied (no permissions)
 *   - Cross-org: denied even if permission exists
 */
class RecommendationPolicyTest extends TestCase
{
    use GrantsEngineCapability;
    use RefreshDatabase;

    private RecommendationPolicy $policy;

    private Organization $org;

    private Department $dept;

    private Recommendation $recommendation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(MeetingsPermissionsSeeder::class);
        $this->policy = new RecommendationPolicy;
        $this->org = Organization::factory()->create();
        $this->dept = Department::factory()->create(['organization_id' => $this->org->id]);

        $organizer = User::factory()->create(['organization_id' => $this->org->id]);
        $meeting = Meeting::factory()->create([
            'organization_id' => $this->org->id,
            'organizer_id' => $organizer->id,
        ]);
        $assignee = User::factory()->create(['organization_id' => $this->org->id]);

        // Direction B: the legacy Decision model was removed (rulings + action
        // items share the unified recommendations table). The fixture no longer
        // creates a Decision row or sets decision_id on the recommendation.
        $this->recommendation = Recommendation::factory()->create([
            'organization_id' => $this->org->id,
            'meeting_id' => $meeting->id,
            'assignee_id' => $assignee->id,
        ]);
    }

    private function makeUser(string $role, ?int $orgId = null): User
    {
        $user = User::factory()->create([
            'organization_id' => $orgId ?? $this->org->id,
            'department_id' => $this->dept->id,
            'is_active' => true,
        ]);
        $role === 'super_admin'
                ? $this->grantCanonicalSuperAdmin($user)
                : $this->assignCanonicalRole($user, $role);

        return $user;
    }

    // ========== super_admin ==========

    public function test_super_admin_can_view_any(): void
    {
        $sa = $this->makeUser('super_admin');

        $this->assertTrue($this->policy->viewAny($sa));
    }

    public function test_super_admin_can_view_recommendation(): void
    {
        $sa = $this->makeUser('super_admin');

        $this->assertTrue($this->policy->view($sa, $this->recommendation));
    }

    public function test_super_admin_can_create_recommendation(): void
    {
        $sa = $this->makeUser('super_admin');

        $this->assertTrue($this->policy->create($sa));
    }

    public function test_super_admin_can_update_recommendation(): void
    {
        $sa = $this->makeUser('super_admin');

        $this->assertTrue($this->policy->update($sa, $this->recommendation));
    }

    public function test_super_admin_can_delete_recommendation(): void
    {
        $sa = $this->makeUser('super_admin');

        $this->assertTrue($this->policy->delete($sa, $this->recommendation));
    }

    // ========== admin with record-decisions permission ==========

    public function test_admin_can_view_same_org_recommendation(): void
    {
        $admin = $this->makeUser('admin');

        $this->assertTrue($this->policy->view($admin, $this->recommendation));
    }

    public function test_admin_can_create_recommendation(): void
    {
        $admin = $this->makeUser('admin');

        $this->assertTrue($this->policy->create($admin));
    }

    public function test_admin_can_update_same_org_recommendation(): void
    {
        $admin = $this->makeUser('admin');

        $this->assertTrue($this->policy->update($admin, $this->recommendation));
    }

    public function test_admin_can_delete_same_org_recommendation(): void
    {
        $admin = $this->makeUser('admin');

        $this->assertTrue($this->policy->delete($admin, $this->recommendation));
    }

    // ========== viewer — no permissions ==========

    public function test_viewer_cannot_view_recommendation(): void
    {
        $viewer = $this->makeUser('viewer');

        $this->assertFalse($this->policy->view($viewer, $this->recommendation));
    }

    public function test_viewer_cannot_create_recommendation(): void
    {
        $viewer = $this->makeUser('viewer');

        $this->assertFalse($this->policy->create($viewer));
    }

    public function test_viewer_cannot_update_recommendation(): void
    {
        $viewer = $this->makeUser('viewer');

        $this->assertFalse($this->policy->update($viewer, $this->recommendation));
    }

    public function test_viewer_cannot_delete_recommendation(): void
    {
        $viewer = $this->makeUser('viewer');

        $this->assertFalse($this->policy->delete($viewer, $this->recommendation));
    }

    // ========== Cross-org isolation ==========

    public function test_admin_from_other_org_cannot_view_recommendation(): void
    {
        $orgB = Organization::factory()->create();
        $outsider = $this->makeUser('admin', $orgB->id);

        $this->assertFalse($this->policy->view($outsider, $this->recommendation));
    }

    public function test_admin_from_other_org_cannot_update_recommendation(): void
    {
        $orgB = Organization::factory()->create();
        $outsider = $this->makeUser('admin', $orgB->id);

        $this->assertFalse($this->policy->update($outsider, $this->recommendation));
    }

    public function test_admin_from_other_org_cannot_delete_recommendation(): void
    {
        $orgB = Organization::factory()->create();
        $outsider = $this->makeUser('admin', $orgB->id);

        $this->assertFalse($this->policy->delete($outsider, $this->recommendation));
    }

    // ========== Null-org user ==========

    public function test_null_org_admin_cannot_view_recommendation(): void
    {
        $user = User::factory()->create([
            'organization_id' => null,
            'is_active' => true,
        ]);
        $this->grantCanonicalAdmin($user);

        $this->assertFalse($this->policy->view($user, $this->recommendation));
    }

    // ========== Phase 5.B — before() super_admin hook ==========

    public function test_super_admin_before_hook_preserves_lifecycle_invariants(): void
    {
        $sa = $this->makeUser('super_admin');

        $this->assertTrue($this->policy->before($sa, 'viewAny'));
        $this->assertTrue($this->policy->before($sa, 'view'));
        $this->assertTrue($this->policy->before($sa, 'create'));
        $this->assertTrue($this->policy->before($sa, 'update'));
        $this->assertTrue($this->policy->before($sa, 'delete'));
        $this->assertNull($this->policy->before($sa, 'approve'));
        $this->assertNull($this->policy->before($sa, 'reject'));
        $this->assertNull($this->policy->before($sa, 'defer'));
        $this->assertNull($this->policy->before($sa, 'accept'));
        $this->assertNull($this->policy->before($sa, 'complete'));
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
        $this->grantCanonicalAdmin($user);

        $this->assertFalse($this->policy->viewAny($user));
    }

    public function test_null_org_user_cannot_create(): void
    {
        $user = User::factory()->create([
            'organization_id' => null,
            'is_active' => true,
        ]);
        $this->grantCanonicalAdmin($user);

        $this->assertFalse($this->policy->create($user));
    }

    // ========== Phase 5.B — precheck() cross-org deny (with engine grant) ==========

    public function test_cross_org_view_denied_via_precheck_even_with_engine_grant(): void
    {
        $orgB = Organization::factory()->create();
        $deptB = Department::factory()->create(['organization_id' => $orgB->id]);
        $outsider = User::factory()->create([
            'organization_id' => $orgB->id,
            'department_id' => $deptB->id,
            'is_active' => true,
        ]);
        $this->grantCanonicalAdmin($outsider);
        $this->grantEngineCapability($outsider, Capability::RECOMMENDATIONS_VIEW, 'organization', $orgB->id);

        $this->assertFalse(
            $this->policy->view($outsider, $this->recommendation),
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
        $this->grantCanonicalAdmin($outsider);
        $this->grantEngineCapability($outsider, Capability::RECOMMENDATIONS_EDIT, 'organization', $orgB->id);

        $this->assertFalse(
            $this->policy->update($outsider, $this->recommendation),
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
        $this->grantCanonicalAdmin($outsider);
        $this->grantEngineCapability($outsider, Capability::RECOMMENDATIONS_DELETE, 'organization', $orgB->id);

        $this->assertFalse(
            $this->policy->delete($outsider, $this->recommendation),
            'precheck() must floor cross-org delete even when the engine capability is granted.'
        );
    }

    public function test_cross_org_approve_denied_via_precheck_even_with_engine_grant(): void
    {
        $orgB = Organization::factory()->create();
        $deptB = Department::factory()->create(['organization_id' => $orgB->id]);
        $outsider = User::factory()->create([
            'organization_id' => $orgB->id,
            'department_id' => $deptB->id,
            'is_active' => true,
        ]);
        $this->grantCanonicalAdmin($outsider);
        $this->grantEngineCapability($outsider, Capability::RECOMMENDATIONS_APPROVE, 'organization', $orgB->id);

        $this->assertFalse(
            $this->policy->approve($outsider, $this->recommendation),
            'precheck() must floor cross-org approve even when the engine capability is granted.'
        );
    }

    public function test_cross_org_reject_denied_via_precheck_even_with_engine_grant(): void
    {
        $orgB = Organization::factory()->create();
        $deptB = Department::factory()->create(['organization_id' => $orgB->id]);
        $outsider = User::factory()->create([
            'organization_id' => $orgB->id,
            'department_id' => $deptB->id,
            'is_active' => true,
        ]);
        $this->grantCanonicalAdmin($outsider);
        $this->grantEngineCapability($outsider, Capability::RECOMMENDATIONS_REJECT, 'organization', $orgB->id);

        $this->assertFalse(
            $this->policy->reject($outsider, $this->recommendation),
            'precheck() must floor cross-org reject even when the engine capability is granted.'
        );
    }

    public function test_cross_org_defer_denied_via_precheck_even_with_engine_grant(): void
    {
        $orgB = Organization::factory()->create();
        $deptB = Department::factory()->create(['organization_id' => $orgB->id]);
        $outsider = User::factory()->create([
            'organization_id' => $orgB->id,
            'department_id' => $deptB->id,
            'is_active' => true,
        ]);
        $this->grantCanonicalAdmin($outsider);
        $this->grantEngineCapability($outsider, Capability::RECOMMENDATIONS_DEFER, 'organization', $orgB->id);

        $this->assertFalse(
            $this->policy->defer($outsider, $this->recommendation),
            'precheck() must floor cross-org defer even when the engine capability is granted.'
        );
    }

    // ========== Phase 5.B — same-org with engine capability granted ==========

    public function test_same_org_user_with_engine_capability_can_view(): void
    {
        $orgUser = $this->makeUser('admin');
        $this->grantEngineCapability($orgUser, Capability::RECOMMENDATIONS_VIEW);

        $this->assertTrue($this->policy->view($orgUser, $this->recommendation));
    }

    public function test_same_org_user_with_engine_capability_can_update(): void
    {
        $orgUser = $this->makeUser('admin');
        $this->grantEngineCapability($orgUser, Capability::RECOMMENDATIONS_EDIT);

        $this->assertTrue($this->policy->update($orgUser, $this->recommendation));
    }

    public function test_same_org_user_with_engine_capability_can_delete(): void
    {
        $orgUser = $this->makeUser('admin');
        $this->grantEngineCapability($orgUser, Capability::RECOMMENDATIONS_DELETE);

        $this->assertTrue($this->policy->delete($orgUser, $this->recommendation));
    }

    // ========== Self-approval block unchanged (ruling only) ==========

    public function test_self_approval_block_preserved_when_requested_by_is_actor(): void
    {
        // Build a ruling-kind recommendation requested by the actor himself.
        $actor = $this->makeUser('admin');
        $this->grantEngineCapability($actor, Capability::RECOMMENDATIONS_APPROVE);

        $selfRuling = Recommendation::factory()->ruling()->create([
            'organization_id' => $this->org->id,
            'meeting_id' => $this->recommendation->meeting_id,
            'requested_by' => $actor->id,
        ]);

        // Phase 5.B keeps the self-approval block: even with the capability,
        // a ruling requester cannot also be the approver.
        $this->assertFalse(
            $this->policy->approve($actor, $selfRuling),
            'Self-approval block must reject when requested_by == actor.id, even with engine capability.'
        );
    }
}
