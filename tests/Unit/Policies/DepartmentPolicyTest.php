<?php

namespace Tests\Unit\Policies;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Policies\DepartmentPolicy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Unit tests for DepartmentPolicy.
 *
 * The policy delegates to AccessDecision::can(User, Capability, ?Department).
 * Key behaviors:
 *   - before(): super_admin → true, others → null
 *   - admin (with org-scoped is_admin_role=true) → can view/create/update/delete
 *   - viewer (no department management permissions) → denied on update/delete/create
 *   - Cross-org isolation: user from org B cannot act on org A department
 */
class DepartmentPolicyTest extends TestCase
{
    use RefreshDatabase;

    private DepartmentPolicy $policy;

    private Organization $org;

    private Department $dept;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->policy = new DepartmentPolicy;
        $this->org = Organization::factory()->create();
        $this->dept = Department::factory()->create([
            'organization_id' => $this->org->id,
            'level' => 4,
        ]);

        Cache::flush();
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

    // ========== before() ==========

    public function test_super_admin_before_returns_true(): void
    {
        $sa = $this->makeUser('super_admin');

        $this->assertTrue($this->policy->before($sa, 'update'));
    }

    public function test_admin_before_returns_null(): void
    {
        $admin = $this->makeUser('admin');

        $this->assertNull($this->policy->before($admin, 'update'));
    }

    // ========== super_admin via Gate ==========

    public function test_super_admin_can_do_all_via_gate(): void
    {
        $sa = $this->makeUser('super_admin');

        $this->assertTrue(Gate::forUser($sa)->allows('view', $this->dept));
        $this->assertTrue(Gate::forUser($sa)->allows('update', $this->dept));
        $this->assertTrue(Gate::forUser($sa)->allows('delete', $this->dept));
        $this->assertTrue(Gate::forUser($sa)->allows('create', Department::class));
    }

    // ========== admin with org-scoped role ==========

    public function test_org_admin_can_view_department(): void
    {
        $admin = $this->makeUser('admin');

        $this->assertTrue($this->policy->view($admin, $this->dept));
    }

    public function test_org_admin_can_update_department(): void
    {
        $admin = $this->makeUser('admin');

        $this->assertTrue($this->policy->update($admin, $this->dept));
    }

    public function test_org_admin_can_delete_department(): void
    {
        $admin = $this->makeUser('admin');

        $this->assertTrue($this->policy->delete($admin, $this->dept));
    }

    public function test_org_admin_can_create_department(): void
    {
        $admin = $this->makeUser('admin');

        $this->assertTrue($this->policy->create($admin));
    }

    // ========== viewer — denied on write operations ==========

    public function test_viewer_cannot_create_department(): void
    {
        $viewer = $this->makeUser('viewer');

        $this->assertFalse($this->policy->create($viewer));
    }

    public function test_viewer_cannot_update_department(): void
    {
        $viewer = $this->makeUser('viewer');

        $this->assertFalse($this->policy->update($viewer, $this->dept));
    }

    public function test_viewer_cannot_delete_department(): void
    {
        $viewer = $this->makeUser('viewer');

        $this->assertFalse($this->policy->delete($viewer, $this->dept));
    }

    // ========== Cross-org isolation ==========

    public function test_admin_from_other_org_cannot_view_department(): void
    {
        $orgB = Organization::factory()->create();
        $outsider = $this->makeUser('admin', $orgB->id);

        $this->assertFalse($this->policy->view($outsider, $this->dept));
        $this->assertTrue(Gate::forUser($outsider)->denies('view', $this->dept));
    }

    public function test_admin_from_other_org_cannot_update_department(): void
    {
        $orgB = Organization::factory()->create();
        $outsider = $this->makeUser('admin', $orgB->id);

        $this->assertFalse($this->policy->update($outsider, $this->dept));
    }
}
