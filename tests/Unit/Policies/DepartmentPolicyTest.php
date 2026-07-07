<?php

namespace Tests\Unit\Policies;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\ScopedRole;
use App\Modules\Core\Models\ScopeType;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Policies\DepartmentPolicy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
        $this->seedOrgScopeDefinitions();
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

    private function grantOrgAdminScopedRole(User $user): void
    {
        if ($user->organization_id === null) {
            return;
        }

        DB::table('model_has_scoped_roles')->insertOrIgnore([
            'user_id' => $user->id,
            'role' => 'admin',
            'scope_type' => ScopedRole::SCOPE_ORGANIZATION,
            'scope_id' => $user->organization_id,
            'inherit_to_children' => true,
            'granted_by' => null,
            'expires_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Cache::flush();
    }

    private function seedOrgScopeDefinitions(): void
    {
        $scopeType = ScopeType::firstOrCreate(
            ['key' => ScopedRole::SCOPE_ORGANIZATION],
            [
                'label_ar' => 'المؤسسة',
                'label_en' => 'Organization',
                'model_class' => 'App\\Modules\\Core\\Models\\Organization',
                'supports_hierarchy' => false,
                'supports_expiry' => false,
                'is_active' => true,
                'sort_order' => 1,
            ]
        );

        $exists = DB::table('scoped_role_definitions')
            ->where('scope_type', ScopedRole::SCOPE_ORGANIZATION)
            ->where('role_key', 'admin')
            ->exists();

        if (! $exists) {
            DB::table('scoped_role_definitions')->insert([
                'name' => 'organization_admin',
                'display_name' => 'Admin',
                'scope_type' => ScopedRole::SCOPE_ORGANIZATION,
                'level' => 1,
                'scope_type_id' => $scopeType->id,
                'role_key' => 'admin',
                'label_ar' => 'مدير إدارة',
                'label_en' => 'Admin',
                'is_admin_role' => true,
                'permissions' => null,
                'is_active' => true,
                'sort_order' => 10,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Cache::flush();
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
        $this->grantOrgAdminScopedRole($admin);

        $this->assertTrue($this->policy->view($admin, $this->dept));
    }

    public function test_org_admin_can_update_department(): void
    {
        $admin = $this->makeUser('admin');
        $this->grantOrgAdminScopedRole($admin);

        $this->assertTrue($this->policy->update($admin, $this->dept));
    }

    public function test_org_admin_can_delete_department(): void
    {
        $admin = $this->makeUser('admin');
        $this->grantOrgAdminScopedRole($admin);

        $this->assertTrue($this->policy->delete($admin, $this->dept));
    }

    public function test_org_admin_can_create_department(): void
    {
        $admin = $this->makeUser('admin');
        $this->grantOrgAdminScopedRole($admin);

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
        $this->grantOrgAdminScopedRole($outsider);

        $this->assertFalse($this->policy->view($outsider, $this->dept));
        $this->assertTrue(Gate::forUser($outsider)->denies('view', $this->dept));
    }

    public function test_admin_from_other_org_cannot_update_department(): void
    {
        $orgB = Organization::factory()->create();
        $outsider = $this->makeUser('admin', $orgB->id);
        $this->grantOrgAdminScopedRole($outsider);

        $this->assertFalse($this->policy->update($outsider, $this->dept));
    }
}
