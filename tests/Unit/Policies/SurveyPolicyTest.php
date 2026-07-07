<?php

namespace Tests\Unit\Policies;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Surveys\Models\Survey;
use App\Modules\Surveys\Policies\SurveyPolicy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * Unit tests for SurveyPolicy (Phase 6).
 *
 * SurveyPolicy is engine-only: every gate is decided by AccessDecision::can()
 * against Capability::SURVEYS_* constants. Spatie hasPermissionTo() is NOT used.
 * Record-scoped checks add a same-organization floor so a user from org A cannot
 * reach a survey that lives in org B even if their scoped roles grant the
 * underlying capability.
 *
 *   - super_admin (before()): all operations allowed
 *   - org-admin with engine capability: allowed on same-org survey
 *   - org-admin without engine capability: denied
 *   - cross-org admin: denied even with engine capability (org-floor)
 *   - null-org user: denied on viewAny; denied on record-scoped methods
 */
class SurveyPolicyTest extends TestCase
{
    use GrantsEngineCapability;
    use RefreshDatabase;

    private SurveyPolicy $policy;

    private Organization $org;

    private Department $dept;

    private Survey $survey;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->policy = new SurveyPolicy;
        $this->org = Organization::factory()->create();
        $this->dept = Department::factory()->create(['organization_id' => $this->org->id]);
        $this->survey = Survey::factory()->create([
            'organization_id' => $this->org->id,
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

    // ========== before() — super_admin short-circuit ==========

    public function test_super_admin_before_hook_short_circuits_to_true(): void
    {
        $sa = $this->makeUser('super_admin');

        $this->assertTrue($this->policy->before($sa, 'viewAny'));
        $this->assertTrue($this->policy->before($sa, 'view'));
        $this->assertTrue($this->policy->before($sa, 'create'));
        $this->assertTrue($this->policy->before($sa, 'update'));
        $this->assertTrue($this->policy->before($sa, 'delete'));
        $this->assertTrue($this->policy->before($sa, 'review'));
    }

    public function test_non_super_admin_before_hook_returns_null(): void
    {
        $admin = $this->makeUser('admin');

        $this->assertNull($this->policy->before($admin, 'view'));
    }

    // ========== super_admin sees everything ==========

    public function test_super_admin_can_view_any(): void
    {
        $sa = $this->makeUser('super_admin');

        $this->assertTrue($this->policy->viewAny($sa));
    }

    public function test_super_admin_can_view_survey(): void
    {
        $sa = $this->makeUser('super_admin');

        $this->assertTrue($this->policy->view($sa, $this->survey));
    }

    public function test_super_admin_can_create_survey(): void
    {
        $sa = $this->makeUser('super_admin');

        $this->assertTrue($this->policy->create($sa));
    }

    public function test_super_admin_can_update_survey(): void
    {
        $sa = $this->makeUser('super_admin');

        $this->assertTrue($this->policy->update($sa, $this->survey));
    }

    public function test_super_admin_can_delete_survey(): void
    {
        $sa = $this->makeUser('super_admin');

        $this->assertTrue($this->policy->delete($sa, $this->survey));
    }

    public function test_super_admin_can_review_survey(): void
    {
        $sa = $this->makeUser('super_admin');

        $this->assertTrue($this->policy->review($sa, $this->survey));
    }

    // ========== viewAny — engine capability + non-null org ==========

    public function test_org_admin_with_surveys_view_can_view_any(): void
    {
        $admin = $this->makeUser('admin');
        $this->grantEngineCapability($admin, Capability::SURVEYS_VIEW);

        $this->assertTrue($this->policy->viewAny($admin));
    }

    public function test_org_viewer_without_surveys_view_cannot_view_any(): void
    {
        // NOTE: admin role has is_admin_role=true on its ScopedRoleDefinition,
        // which short-circuits every capability to true (engine shortcut). The
        // "without capability" floor must be exercised with viewer (a role that
        // lacks the surveys.* permissions in scoped_role_definitions.permissions).
        $viewer = $this->makeUser('viewer');

        $this->assertFalse($this->policy->viewAny($viewer));
    }

    public function test_null_org_user_cannot_view_any(): void
    {
        $user = User::factory()->create([
            'organization_id' => null,
            'is_active' => true,
        ]);
        $user->assignRole('admin');
        $this->grantEngineCapability($user, Capability::SURVEYS_VIEW);

        // null-org fail-closed floor (matching the controller's abort_if).
        $this->assertFalse($this->policy->viewAny($user));
    }

    // ========== view — engine + same-org ==========

    public function test_same_org_admin_with_surveys_view_can_view(): void
    {
        $admin = $this->makeUser('admin');
        $this->grantEngineCapability($admin, Capability::SURVEYS_VIEW);

        $this->assertTrue($this->policy->view($admin, $this->survey));
    }

    public function test_same_org_viewer_without_capability_cannot_view(): void
    {
        // viewer (no surveys.view) ⇒ engine denies.
        $viewer = $this->makeUser('viewer');

        $this->assertFalse($this->policy->view($viewer, $this->survey));
    }

    public function test_cross_org_admin_cannot_view_even_with_capability(): void
    {
        $orgB = Organization::factory()->create();
        $deptB = Department::factory()->create(['organization_id' => $orgB->id]);
        $outsider = User::factory()->create([
            'organization_id' => $orgB->id,
            'department_id' => $deptB->id,
            'is_active' => true,
        ]);
        $outsider->assignRole('admin');
        $this->grantEngineCapability($outsider, Capability::SURVEYS_VIEW);

        // Org B admin has SURVEYS_VIEW but the survey lives in org A → floor.
        $this->assertFalse($this->policy->view($outsider, $this->survey));
    }

    // ========== update — engine + same-org ==========

    public function test_same_org_admin_with_surveys_edit_can_update(): void
    {
        $admin = $this->makeUser('admin');
        $this->grantEngineCapability($admin, Capability::SURVEYS_EDIT);

        $this->assertTrue($this->policy->update($admin, $this->survey));
    }

    public function test_same_org_viewer_without_surveys_edit_cannot_update(): void
    {
        // viewer has view-only permissions, no surveys.edit.
        $viewer = $this->makeUser('viewer');

        $this->assertFalse($this->policy->update($viewer, $this->survey));
    }

    public function test_cross_org_admin_cannot_update_even_with_capability(): void
    {
        $orgB = Organization::factory()->create();
        $deptB = Department::factory()->create(['organization_id' => $orgB->id]);
        $outsider = User::factory()->create([
            'organization_id' => $orgB->id,
            'department_id' => $deptB->id,
            'is_active' => true,
        ]);
        $outsider->assignRole('admin');
        $this->grantEngineCapability($outsider, Capability::SURVEYS_EDIT);

        $this->assertFalse($this->policy->update($outsider, $this->survey));
    }

    // ========== delete — engine + same-org ==========

    public function test_same_org_admin_with_surveys_delete_can_delete(): void
    {
        $admin = $this->makeUser('admin');
        $this->grantEngineCapability($admin, Capability::SURVEYS_DELETE);

        $this->assertTrue($this->policy->delete($admin, $this->survey));
    }

    public function test_same_org_viewer_without_surveys_delete_cannot_delete(): void
    {
        $viewer = $this->makeUser('viewer');

        $this->assertFalse($this->policy->delete($viewer, $this->survey));
    }

    public function test_cross_org_admin_cannot_delete_even_with_capability(): void
    {
        $orgB = Organization::factory()->create();
        $deptB = Department::factory()->create(['organization_id' => $orgB->id]);
        $outsider = User::factory()->create([
            'organization_id' => $orgB->id,
            'department_id' => $deptB->id,
            'is_active' => true,
        ]);
        $outsider->assignRole('admin');
        $this->grantEngineCapability($outsider, Capability::SURVEYS_DELETE);

        $this->assertFalse($this->policy->delete($outsider, $this->survey));
    }

    // ========== review — engine + same-org ==========

    public function test_same_org_admin_with_surveys_review_can_review(): void
    {
        $admin = $this->makeUser('admin');
        $this->grantEngineCapability($admin, Capability::SURVEYS_REVIEW_RESPONSES);

        $this->assertTrue($this->policy->review($admin, $this->survey));
    }

    public function test_same_org_viewer_without_surveys_review_cannot_review(): void
    {
        $viewer = $this->makeUser('viewer');

        $this->assertFalse($this->policy->review($viewer, $this->survey));
    }

    public function test_cross_org_admin_cannot_review_even_with_capability(): void
    {
        $orgB = Organization::factory()->create();
        $deptB = Department::factory()->create(['organization_id' => $orgB->id]);
        $outsider = User::factory()->create([
            'organization_id' => $orgB->id,
            'department_id' => $deptB->id,
            'is_active' => true,
        ]);
        $outsider->assignRole('admin');
        $this->grantEngineCapability($outsider, Capability::SURVEYS_REVIEW_RESPONSES);

        $this->assertFalse($this->policy->review($outsider, $this->survey));
    }

    // ========== Null-actor / null-target floors ==========

    public function test_null_org_admin_with_view_capability_cannot_view_survey(): void
    {
        $user = User::factory()->create([
            'organization_id' => null,
            'is_active' => true,
        ]);
        $user->assignRole('admin');
        $this->grantEngineCapability($user, Capability::SURVEYS_VIEW);

        // Same null-org floor as viewAny: no tenancy anchor, denied.
        $this->assertFalse($this->policy->view($user, $this->survey));
    }

    public function test_null_org_admin_with_edit_capability_cannot_update_survey(): void
    {
        $user = User::factory()->create([
            'organization_id' => null,
            'is_active' => true,
        ]);
        $user->assignRole('admin');
        $this->grantEngineCapability($user, Capability::SURVEYS_EDIT);

        $this->assertFalse($this->policy->update($user, $this->survey));
    }
}
