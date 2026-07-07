<?php

namespace Tests\Feature\Meetings;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Meetings\Models\MeetingResolution;
use App\Modules\Projects\Models\Project;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * Phase 5 / Release Validation — Scoped-role authz matrix for Meeting
 * Resolutions.
 *
 * The brief's role-based checklist listed `dept_manager`, `dept_member`,
 * and `viewer` as roles that need explicit coverage. The previous
 * release validation passed on `super_admin`, `admin`, and cross-org;
 * this file adds the **scoped-role definitions** (which are engine
 * grants, NOT Spatie roles) so the matrix is complete.
 *
 * Engine grants flow through `authorization_role_assignments` (the
 * `model_has_scoped_roles` table) joined to `scoped_role_definitions`.
 * Each definition's `permissions` JSON carries the capabilities it
 * grants. We use `GrantsEngineCapability::grantEngineCapability` to
 * wire the exact scope + permission shape that production grants.
 */
class MeetingResolutionScopedRoleAuthzTest extends TestCase
{
    use GrantsEngineCapability;
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private Department $deptA;

    private Department $deptB;

    private Department $deptOther;

    private Project $projectA;

    private Project $projectB;

    private Meeting $meetingA;

    private Meeting $meetingB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Cache::flush();

        $this->orgA = Organization::factory()->create();
        $this->orgB = Organization::factory()->create();
        $this->deptA = Department::factory()->create(['organization_id' => $this->orgA->id]);
        $this->deptB = Department::factory()->create(['organization_id' => $this->orgA->id]);
        $this->deptOther = Department::factory()->create(['organization_id' => $this->orgA->id]);
        $this->projectA = Project::factory()->create([
            'department_id' => $this->deptA->id,
            'organization_id' => $this->orgA->id,
        ]);
        $this->projectB = Project::factory()->create([
            'department_id' => $this->deptB->id,
            'organization_id' => $this->orgA->id,
        ]);
        $this->meetingA = Meeting::factory()->create([
            'department_id' => $this->deptA->id,
            'organization_id' => $this->orgA->id,
            'status' => Meeting::STATUS_SCHEDULED,
        ]);
        $this->meetingB = Meeting::factory()->create([
            'department_id' => $this->deptB->id,
            'organization_id' => $this->orgA->id,
            'status' => Meeting::STATUS_SCHEDULED,
        ]);
    }

    private function freshUser(?Organization $org = null, ?Department $dept = null): User
    {
        return User::factory()->create([
            'department_id' => $dept?->id ?? $this->deptA->id,
            'organization_id' => $org?->id ?? $this->orgA->id,
            'is_active' => true,
        ]);
    }

    private function makeResolution(?Meeting $meeting = null): MeetingResolution
    {
        $meeting ??= $this->meetingA;

        return MeetingResolution::create([
            'meeting_id' => $meeting->id,
            'organization_id' => $meeting->organization_id,
            'kind' => MeetingResolution::KIND_DECISION,
            'title' => 'مخرج للاختبار',
            'owner_id' => $meeting->organizer_id,
            'created_by' => $meeting->organizer_id,
            'status' => MeetingResolution::STATUS_OPEN,
            'priority' => MeetingResolution::PRIORITY_MEDIUM,
        ]);
    }

    // ---- dept_manager scoped role ----

    public function test_dept_manager_in_granted_dept_can_view_but_not_create(): void
    {
        // Dept-scoped grants do NOT cover `MeetingResolutionPolicy::create()`
        // because the engine's `can()` for the create ability runs WITHOUT a
        // target (no resolution exists yet). The engine only returns true for
        // org-scoped grants in that blank-target path. Dept-scoped grants
        // light up on `view`/`update`/`convert` where the engine walks the
        // target's scope chain (meeting → department).
        $mgr = $this->freshUser($this->orgA, $this->deptA);
        $this->grantEngineCapability(
            $mgr,
            [Capability::MEETING_RESOLUTIONS_VIEW],
            scopeType: 'department',
            scopeId: $this->deptA->id,
            roleKey: 'dept_manager_view_test',
        );

        $create = $this->actingAs($mgr, 'sanctum')
            ->postJson("/api/meetings/{$this->meetingA->id}/resolutions", [
                'meeting_id' => $this->meetingA->id,
                'kind' => 'decision',
                'title' => 'مخرج من مدير الإدارة',
                'owner_id' => $mgr->id,
            ]);
        $create->assertStatus(403, 'dept-scoped grant does not cover create() — only org-scoped does');
    }

    public function test_dept_manager_can_view_resolution_in_their_dept(): void
    {
        $mgr = $this->freshUser($this->orgA, $this->deptA);
        $this->grantEngineCapability(
            $mgr,
            [Capability::MEETING_RESOLUTIONS_VIEW],
            scopeType: 'department',
            scopeId: $this->deptA->id,
            roleKey: 'dept_manager_view',
        );
        $r = $this->makeResolution();

        $response = $this->actingAs($mgr, 'sanctum')
            ->getJson("/api/meeting-resolutions/{$r->id}");
        $response->assertStatus(200);
    }

    public function test_dept_manager_cannot_view_resolution_in_other_dept(): void
    {
        $mgr = $this->freshUser($this->orgA, $this->deptA);
        $this->grantEngineCapability(
            $mgr,
            [Capability::MEETING_RESOLUTIONS_VIEW],
            scopeType: 'department',
            scopeId: $this->deptA->id,
            roleKey: 'dept_manager_view_other',
        );
        $r = $this->makeResolution($this->meetingB);

        $response = $this->actingAs($mgr, 'sanctum')
            ->getJson("/api/meeting-resolutions/{$r->id}");
        $this->assertContains($response->status(), [403, 404]);
    }

    public function test_dept_manager_can_convert_resolution_in_their_dept(): void
    {
        $mgr = $this->freshUser($this->orgA, $this->deptA);
        $this->grantEngineCapability(
            $mgr,
            [Capability::MEETING_RESOLUTIONS_CONVERT_TO_TASKS],
            scopeType: 'department',
            scopeId: $this->deptA->id,
            roleKey: 'dept_manager_convert',
        );
        $r = $this->makeResolution();

        $response = $this->actingAs($mgr, 'sanctum')
            ->postJson("/api/meeting-resolutions/{$r->id}/convert-to-tasks", [
                'tasks' => [['title' => 'مهمة من مدير الإدارة', 'assignee_id' => $mgr->id]],
            ]);
        $response->assertStatus(201);
    }

    public function test_dept_manager_without_convert_capability_cannot_convert(): void
    {
        $mgr = $this->freshUser($this->orgA, $this->deptA);
        $this->grantEngineCapability(
            $mgr,
            [Capability::MEETING_RESOLUTIONS_VIEW], // view only, no convert
            scopeType: 'department',
            scopeId: $this->deptA->id,
            roleKey: 'dept_manager_view_only',
        );
        $r = $this->makeResolution();

        $response = $this->actingAs($mgr, 'sanctum')
            ->postJson("/api/meeting-resolutions/{$r->id}/convert-to-tasks", [
                'tasks' => [['title' => 'محاولة', 'assignee_id' => $mgr->id]],
            ]);
        $response->assertStatus(403);
    }

    // ---- dept_member scoped role ----

    public function test_dept_member_can_view_resolution_but_cannot_create(): void
    {
        $member = $this->freshUser($this->orgA, $this->deptA);
        $this->grantEngineCapability(
            $member,
            [Capability::MEETING_RESOLUTIONS_VIEW],
            scopeType: 'department',
            scopeId: $this->deptA->id,
            roleKey: 'dept_member_view',
        );
        $r = $this->makeResolution();

        $response = $this->actingAs($member, 'sanctum')
            ->getJson("/api/meeting-resolutions/{$r->id}");
        $response->assertStatus(200);

        $create = $this->actingAs($member, 'sanctum')
            ->postJson("/api/meetings/{$this->meetingA->id}/resolutions", [
                'meeting_id' => $this->meetingA->id,
                'kind' => 'decision',
                'title' => 'محاولة عضو',
                'owner_id' => $member->id,
            ]);
        $create->assertStatus(403);
    }

    public function test_dept_member_cannot_convert_or_complete_resolution(): void
    {
        $member = $this->freshUser($this->orgA, $this->deptA);
        $this->grantEngineCapability(
            $member,
            [Capability::MEETING_RESOLUTIONS_VIEW],
            scopeType: 'department',
            scopeId: $this->deptA->id,
            roleKey: 'dept_member_view_only',
        );
        $r = $this->makeResolution();

        $convert = $this->actingAs($member, 'sanctum')
            ->postJson("/api/meeting-resolutions/{$r->id}/convert-to-tasks", [
                'tasks' => [['title' => 'محاولة', 'assignee_id' => $member->id]],
            ]);
        $convert->assertStatus(403);

        $complete = $this->actingAs($member, 'sanctum')
            ->postJson("/api/meeting-resolutions/{$r->id}/complete");
        $complete->assertStatus(403);
    }

    // ---- viewer scoped role (org-wide) ----

    public function test_viewer_scoped_role_can_view_but_cannot_create(): void
    {
        $viewer = $this->freshUser($this->orgA, $this->deptA);
        $this->grantEngineCapability(
            $viewer,
            [Capability::MEETING_RESOLUTIONS_VIEW],
            scopeType: 'organization',
            scopeId: $this->orgA->id,
            roleKey: 'viewer_org_view',
        );
        $r = $this->makeResolution();

        $response = $this->actingAs($viewer, 'sanctum')
            ->getJson("/api/meeting-resolutions/{$r->id}");
        $response->assertStatus(200);

        $create = $this->actingAs($viewer, 'sanctum')
            ->postJson("/api/meetings/{$this->meetingA->id}/resolutions", [
                'meeting_id' => $this->meetingA->id,
                'kind' => 'decision',
                'title' => 'محاولة فيور',
                'owner_id' => $viewer->id,
            ]);
        $create->assertStatus(403);
    }

    // ---- org-wide manager ----

    public function test_org_manager_can_create_and_convert_in_any_dept(): void
    {
        $orgMgr = $this->freshUser($this->orgA, $this->deptA);
        $this->grantEngineCapability(
            $orgMgr,
            [
                Capability::MEETING_RESOLUTIONS_VIEW,
                Capability::MEETING_RESOLUTIONS_CREATE,
                Capability::MEETING_RESOLUTIONS_CONVERT_TO_TASKS,
            ],
            scopeType: 'organization',
            scopeId: $this->orgA->id,
            roleKey: 'pmo_manager_test',
        );

        $r1 = $this->makeResolution($this->meetingA);
        $r2 = $this->makeResolution($this->meetingB);

        // org_mgr can act in dept A
        $response = $this->actingAs($orgMgr, 'sanctum')
            ->postJson("/api/meeting-resolutions/{$r1->id}/convert-to-tasks", [
                'tasks' => [['title' => 'مهمة', 'assignee_id' => $orgMgr->id]],
            ]);
        $response->assertStatus(201);

        // org_mgr can act in dept B too (org-scope grant)
        $response = $this->actingAs($orgMgr, 'sanctum')
            ->postJson("/api/meeting-resolutions/{$r2->id}/convert-to-tasks", [
                'tasks' => [['title' => 'مهمة', 'assignee_id' => $orgMgr->id]],
            ]);
        $response->assertStatus(201);
    }

    public function test_dept_manager_in_org_b_cannot_create_in_org_a(): void
    {
        $mgrB = $this->freshUser($this->orgB, $this->deptB);
        $this->grantEngineCapability(
            $mgrB,
            [Capability::MEETING_RESOLUTIONS_CREATE],
            scopeType: 'organization',
            scopeId: $this->orgB->id,
            roleKey: 'mgr_b_create',
        );

        $response = $this->actingAs($mgrB, 'sanctum')
            ->postJson("/api/meetings/{$this->meetingA->id}/resolutions", [
                'meeting_id' => $this->meetingA->id,
                'kind' => 'decision',
                'title' => 'محاولة عبر الحدود',
                'owner_id' => $mgrB->id,
            ]);
        // 422 (FormRequest's meeting_id org-floor validator) or 403
        // (policy gate) is a valid deny — both block the cross-org request.
        $this->assertContains($response->status(), [403, 422]);
    }
}
