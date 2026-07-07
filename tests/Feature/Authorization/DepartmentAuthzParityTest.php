<?php

namespace Tests\Feature\Authorization;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\ScopedRole;
use App\Modules\Core\Models\ScopedRoleDefinition;
use App\Modules\Core\Models\ScopeType;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * DepartmentAuthzParityTest — اختبار تكافؤ محرّك AuthZ الجديد مع السلوك الموروث
 *
 * يتحقق من أن AccessDecision::can() يُنتج نفس نتيجة الفحص الموروث
 * (hasPermissionTo + sharesOrganization) لكل سيناريو وكل قدرة:
 * DEPARTMENTS_CREATE, DEPARTMENTS_EDIT, DEPARTMENTS_DELETE.
 *
 * السيناريوهات المُختبَرة:
 *  1. super_admin → true على كلا المسارَين
 *  2. org_admin (له أذونات Spatie + دور محرّك على مستوى المؤسسة) → true على كلا المسارَين
 *  3. member (بلا أذونات، بلا أدوار) → false على كلا المسارَين
 *  4. cross_org_user (له أذونات، مؤسسة مختلفة) → false على كلا المسارَين لـ EDIT/DELETE
 *  5. null_org_user (له أذونات، organization_id=null) → false على كلا المسارَين
 *  6. dept_manager_via_inherit (دور إداري موروث على parent dept) → engine فقط
 */
class DepartmentAuthzParityTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Organization $otherOrg;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->org = Organization::factory()->create();
        $this->otherOrg = Organization::factory()->create();
    }

    // =========================================================
    // Scenario 1: super_admin
    // =========================================================

    #[Test]
    public function super_admin_returns_true_for_create_on_both_paths(): void
    {
        $superAdmin = User::factory()->create(['organization_id' => $this->org->id]);
        $superAdmin->assignRole('super_admin');

        // Legacy: super_admin has create_departments via Spatie role grant
        $legacyResult = $superAdmin->isSuperAdmin() || $superAdmin->hasPermissionTo('create_departments');

        // Engine path (flag ON): super_admin bypass
        $engineResult = AccessDecision::can($superAdmin, Capability::DEPARTMENTS_CREATE);

        $this->assertTrue($legacyResult, 'Legacy: super_admin should get true for CREATE');
        $this->assertTrue($engineResult, 'Engine: super_admin should get true for CREATE');
        $this->assertSame($legacyResult, $engineResult, 'Parity: both paths must agree');
    }

    #[Test]
    public function super_admin_returns_true_for_edit_on_both_paths(): void
    {
        $superAdmin = User::factory()->create(['organization_id' => $this->org->id]);
        $superAdmin->assignRole('super_admin');

        $department = Department::factory()->create(['organization_id' => $this->org->id]);

        // Legacy: super_admin has edit_departments
        $legacyResult = $superAdmin->isSuperAdmin() || $superAdmin->hasPermissionTo('edit_departments');

        // Engine path
        $engineResult = AccessDecision::can($superAdmin, Capability::DEPARTMENTS_EDIT, $department);

        $this->assertTrue($legacyResult, 'Legacy: super_admin should get true for EDIT');
        $this->assertTrue($engineResult, 'Engine: super_admin should get true for EDIT');
        $this->assertSame($legacyResult, $engineResult, 'Parity: both paths must agree');
    }

    #[Test]
    public function super_admin_returns_true_for_delete_on_both_paths(): void
    {
        $superAdmin = User::factory()->create(['organization_id' => $this->org->id]);
        $superAdmin->assignRole('super_admin');

        $department = Department::factory()->create(['organization_id' => $this->org->id]);

        // Legacy: super_admin has delete_departments
        $legacyResult = $superAdmin->isSuperAdmin() || $superAdmin->hasPermissionTo('delete_departments');

        // Engine path
        $engineResult = AccessDecision::can($superAdmin, Capability::DEPARTMENTS_DELETE, $department);

        $this->assertTrue($legacyResult, 'Legacy: super_admin should get true for DELETE');
        $this->assertTrue($engineResult, 'Engine: super_admin should get true for DELETE');
        $this->assertSame($legacyResult, $engineResult, 'Parity: both paths must agree');
    }

    // =========================================================
    // Scenario 2: org_admin with Spatie permissions + engine org-level role
    //
    // The legacy path checks Spatie permissions + org isolation.
    // The engine path checks scoped role definitions at the org level.
    // Both represent an "authorized admin" — parity = both true.
    // =========================================================

    #[Test]
    public function org_admin_with_permissions_returns_true_for_create_on_both_paths(): void
    {
        $orgAdmin = User::factory()->create(['organization_id' => $this->org->id]);
        $orgAdmin->givePermissionTo('create_departments');

        // Legacy: has permission → true
        $legacyResult = $orgAdmin->hasPermissionTo('create_departments');

        // Engine: org-level admin scoped role (scope_type='organization') with DEPARTMENTS_CREATE
        [$roleDefinition] = $this->createOrgLevelRoleDefinition(
            roleKey: 'org_dept_admin_create',
            permissions: [Capability::DEPARTMENTS_CREATE]
        );

        $orgAdmin->assignScopedRole(
            role: $roleDefinition->role_key,
            scopeType: ScopedRole::SCOPE_ORGANIZATION,
            scopeId: $this->org->id
        );

        Cache::flush();

        $engineResult = AccessDecision::can($orgAdmin, Capability::DEPARTMENTS_CREATE);

        $this->assertTrue($legacyResult, 'Legacy: org_admin should get true for CREATE');
        $this->assertTrue($engineResult, 'Engine: org_admin should get true for CREATE');
        $this->assertSame($legacyResult, $engineResult, 'Parity: both paths must agree');
    }

    #[Test]
    public function org_admin_with_permissions_returns_true_for_edit_on_both_paths(): void
    {
        $orgAdmin = User::factory()->create(['organization_id' => $this->org->id]);
        $orgAdmin->givePermissionTo('edit_departments');

        $department = Department::factory()->create(['organization_id' => $this->org->id]);

        // Legacy: has permission + same org → true
        $hasPermission = $orgAdmin->hasPermissionTo('edit_departments');
        $sharesOrg = (int) $orgAdmin->organization_id === (int) $department->organization_id;
        $legacyResult = $hasPermission && $sharesOrg;

        // Engine: org-level scoped role with can_edit=true
        [$roleDefinition] = $this->createOrgLevelRoleDefinition(
            roleKey: 'org_dept_admin_edit',
            canEdit: true
        );

        $orgAdmin->assignScopedRole(
            role: $roleDefinition->role_key,
            scopeType: ScopedRole::SCOPE_ORGANIZATION,
            scopeId: $this->org->id
        );

        Cache::flush();

        $engineResult = AccessDecision::can($orgAdmin, Capability::DEPARTMENTS_EDIT, $department);

        $this->assertTrue($legacyResult, 'Legacy: org_admin should get true for EDIT');
        $this->assertTrue($engineResult, 'Engine: org_admin should get true for EDIT');
        $this->assertSame($legacyResult, $engineResult, 'Parity: both paths must agree');
    }

    #[Test]
    public function org_admin_with_permissions_returns_true_for_delete_on_both_paths(): void
    {
        $orgAdmin = User::factory()->create(['organization_id' => $this->org->id]);
        $orgAdmin->givePermissionTo('delete_departments');

        $department = Department::factory()->create(['organization_id' => $this->org->id]);

        // Legacy: has permission + same org → true
        $hasPermission = $orgAdmin->hasPermissionTo('delete_departments');
        $sharesOrg = (int) $orgAdmin->organization_id === (int) $department->organization_id;
        $legacyResult = $hasPermission && $sharesOrg;

        // Engine: org-level scoped role with can_delete=true
        [$roleDefinition] = $this->createOrgLevelRoleDefinition(
            roleKey: 'org_dept_admin_delete',
            canDelete: true
        );

        $orgAdmin->assignScopedRole(
            role: $roleDefinition->role_key,
            scopeType: ScopedRole::SCOPE_ORGANIZATION,
            scopeId: $this->org->id
        );

        Cache::flush();

        $engineResult = AccessDecision::can($orgAdmin, Capability::DEPARTMENTS_DELETE, $department);

        $this->assertTrue($legacyResult, 'Legacy: org_admin should get true for DELETE');
        $this->assertTrue($engineResult, 'Engine: org_admin should get true for DELETE');
        $this->assertSame($legacyResult, $engineResult, 'Parity: both paths must agree');
    }

    // =========================================================
    // Scenario 3: member without permissions (same org)
    // =========================================================

    #[Test]
    public function member_without_permissions_returns_false_for_create_on_both_paths(): void
    {
        $member = User::factory()->create(['organization_id' => $this->org->id]);
        // No Spatie permissions, no scoped roles

        // Legacy: no permission → false
        $legacyResult = $member->hasPermissionTo('create_departments');

        // Engine: no scoped roles → false
        $engineResult = AccessDecision::can($member, Capability::DEPARTMENTS_CREATE);

        $this->assertFalse($legacyResult, 'Legacy: member should get false for CREATE');
        $this->assertFalse($engineResult, 'Engine: member should get false for CREATE');
        $this->assertSame($legacyResult, $engineResult, 'Parity: both paths must agree');
    }

    #[Test]
    public function member_without_permissions_returns_false_for_edit_on_both_paths(): void
    {
        $member = User::factory()->create(['organization_id' => $this->org->id]);

        $department = Department::factory()->create(['organization_id' => $this->org->id]);

        // Legacy: no permission → false
        $legacyResult = $member->hasPermissionTo('edit_departments');

        // Engine: no scoped roles → false
        $engineResult = AccessDecision::can($member, Capability::DEPARTMENTS_EDIT, $department);

        $this->assertFalse($legacyResult, 'Legacy: member should get false for EDIT');
        $this->assertFalse($engineResult, 'Engine: member should get false for EDIT');
        $this->assertSame($legacyResult, $engineResult, 'Parity: both paths must agree');
    }

    #[Test]
    public function member_without_permissions_returns_false_for_delete_on_both_paths(): void
    {
        $member = User::factory()->create(['organization_id' => $this->org->id]);

        $department = Department::factory()->create(['organization_id' => $this->org->id]);

        // Legacy: no permission → false
        $legacyResult = $member->hasPermissionTo('delete_departments');

        // Engine: no scoped roles → false
        $engineResult = AccessDecision::can($member, Capability::DEPARTMENTS_DELETE, $department);

        $this->assertFalse($legacyResult, 'Legacy: member should get false for DELETE');
        $this->assertFalse($engineResult, 'Engine: member should get false for DELETE');
        $this->assertSame($legacyResult, $engineResult, 'Parity: both paths must agree');
    }

    // =========================================================
    // Scenario 4: cross_org_user (has permissions, different org)
    // =========================================================

    #[Test]
    public function cross_org_user_returns_false_for_edit_on_both_paths(): void
    {
        $crossOrgUser = User::factory()->create(['organization_id' => $this->otherOrg->id]);
        $crossOrgUser->givePermissionTo('edit_departments');

        // Department belongs to $this->org, not $this->otherOrg
        $department = Department::factory()->create(['organization_id' => $this->org->id]);

        // Legacy: has permission BUT different org → false (org check fails)
        $hasPermission = $crossOrgUser->hasPermissionTo('edit_departments');
        $sharesOrg = (int) $crossOrgUser->organization_id === (int) $department->organization_id;
        $legacyResult = $hasPermission && $sharesOrg;

        // Engine: org isolation enforcement → false (sameOrganization fails)
        $engineResult = AccessDecision::can($crossOrgUser, Capability::DEPARTMENTS_EDIT, $department);

        $this->assertFalse($legacyResult, 'Legacy: cross_org_user should get false for EDIT');
        $this->assertFalse($engineResult, 'Engine: cross_org_user should get false for EDIT');
        $this->assertSame($legacyResult, $engineResult, 'Parity: both paths must agree');
    }

    #[Test]
    public function cross_org_user_returns_false_for_delete_on_both_paths(): void
    {
        $crossOrgUser = User::factory()->create(['organization_id' => $this->otherOrg->id]);
        $crossOrgUser->givePermissionTo('delete_departments');

        // Department belongs to $this->org, not $this->otherOrg
        $department = Department::factory()->create(['organization_id' => $this->org->id]);

        // Legacy: has permission BUT different org → false
        $hasPermission = $crossOrgUser->hasPermissionTo('delete_departments');
        $sharesOrg = (int) $crossOrgUser->organization_id === (int) $department->organization_id;
        $legacyResult = $hasPermission && $sharesOrg;

        // Engine: org isolation enforcement → false
        $engineResult = AccessDecision::can($crossOrgUser, Capability::DEPARTMENTS_DELETE, $department);

        $this->assertFalse($legacyResult, 'Legacy: cross_org_user should get false for DELETE');
        $this->assertFalse($engineResult, 'Engine: cross_org_user should get false for DELETE');
        $this->assertSame($legacyResult, $engineResult, 'Parity: both paths must agree');
    }

    // =========================================================
    // Scenario 5: null_org_user (has permissions, organization_id=null)
    // =========================================================

    #[Test]
    public function null_org_user_returns_false_for_edit_on_both_paths(): void
    {
        $nullOrgUser = User::factory()->create(['organization_id' => null]);
        $nullOrgUser->givePermissionTo('edit_departments');

        $department = Department::factory()->create(['organization_id' => $this->org->id]);

        // Legacy: has permission BUT null org → (int)null=0 !== org_id → false
        $hasPermission = $nullOrgUser->hasPermissionTo('edit_departments');
        $sharesOrg = (int) $nullOrgUser->organization_id === (int) $department->organization_id;
        $legacyResult = $hasPermission && $sharesOrg;

        // Engine: null org → sameOrganization returns false
        $engineResult = AccessDecision::can($nullOrgUser, Capability::DEPARTMENTS_EDIT, $department);

        $this->assertFalse($legacyResult, 'Legacy: null_org_user should get false for EDIT');
        $this->assertFalse($engineResult, 'Engine: null_org_user should get false for EDIT');
        $this->assertSame($legacyResult, $engineResult, 'Parity: both paths must agree');
    }

    #[Test]
    public function null_org_user_returns_false_for_delete_on_both_paths(): void
    {
        $nullOrgUser = User::factory()->create(['organization_id' => null]);
        $nullOrgUser->givePermissionTo('delete_departments');

        $department = Department::factory()->create(['organization_id' => $this->org->id]);

        // Legacy: has permission BUT null org → false
        $hasPermission = $nullOrgUser->hasPermissionTo('delete_departments');
        $sharesOrg = (int) $nullOrgUser->organization_id === (int) $department->organization_id;
        $legacyResult = $hasPermission && $sharesOrg;

        // Engine: null org → false
        $engineResult = AccessDecision::can($nullOrgUser, Capability::DEPARTMENTS_DELETE, $department);

        $this->assertFalse($legacyResult, 'Legacy: null_org_user should get false for DELETE');
        $this->assertFalse($engineResult, 'Engine: null_org_user should get false for DELETE');
        $this->assertSame($legacyResult, $engineResult, 'Parity: both paths must agree');
    }

    // =========================================================
    // Scenario 6: dept_manager_via_inherit (engine-only — no legacy parity)
    //
    // NOTE: The legacy auth path (Spatie permissions + org isolation) has no
    // concept of scoped role inheritance. These tests validate engine behavior
    // only (flag ON path). No parity assertion against legacy is made here
    // because the concepts are not equivalent.
    // =========================================================

    #[Test]
    public function dept_manager_via_inherited_role_can_edit_child_department(): void
    {
        // Create parent department and child department
        $parentDept = Department::factory()->create(['organization_id' => $this->org->id, 'parent_id' => null]);
        $childDept = Department::factory()->create(['organization_id' => $this->org->id, 'parent_id' => $parentDept->id]);

        $manager = User::factory()->create(['organization_id' => $this->org->id]);

        // Create a dept-scoped admin role definition with inherit support
        [$scopeType, $roleDefinition] = $this->createDeptScopedRoleDefinition(
            roleKey: 'dept_manager_inherit',
            isAdminRole: true
        );

        // Assign manager to parent dept with inherit_to_children=true
        $manager->assignScopedRole(
            role: $roleDefinition->role_key,
            scopeType: 'department',
            scopeId: $parentDept->id,
            inheritToChildren: true
        );

        Cache::flush();

        // Engine path (flag ON): inherited role should grant EDIT/DELETE on child dept
        $canEditChild = AccessDecision::can($manager, Capability::DEPARTMENTS_EDIT, $childDept);
        $canDeleteChild = AccessDecision::can($manager, Capability::DEPARTMENTS_DELETE, $childDept);

        $this->assertTrue($canEditChild, 'Engine: dept_manager with inherited role should be able to edit child dept');
        $this->assertTrue($canDeleteChild, 'Engine: dept_manager with inherited role should be able to delete child dept');
    }

    #[Test]
    public function dept_role_on_parent_grants_access_to_child_via_scope_chain(): void
    {
        // The engine walks the scope chain (child→parent→org) positionally.
        // A role on the parent dept grants access to the child dept
        // even without inherit_to_children, because the child's scope chain includes the parent.
        $parentDept = Department::factory()->create(['organization_id' => $this->org->id, 'parent_id' => null]);
        $childDept = Department::factory()->create(['organization_id' => $this->org->id, 'parent_id' => $parentDept->id]);

        $manager = User::factory()->create(['organization_id' => $this->org->id]);

        [$scopeType, $roleDefinition] = $this->createDeptScopedRoleDefinition(
            roleKey: 'dept_manager_positional',
            isAdminRole: true
        );

        // Assign WITHOUT inherit_to_children (default false for this call)
        $manager->assignScopedRole(
            role: $roleDefinition->role_key,
            scopeType: 'department',
            scopeId: $parentDept->id,
            inheritToChildren: false
        );

        Cache::flush();

        // positional check: child → parent dept (role found here) → org
        $canEditChild = AccessDecision::can($manager, Capability::DEPARTMENTS_EDIT, $childDept);

        // The engine's buildScopeChain walks child→parent positionally,
        // so role on parent grants access to child via positional traversal.
        $this->assertTrue($canEditChild, 'Engine: role on parent dept grants access to child via scope chain positional check');
    }

    // =========================================================
    // Helpers
    // =========================================================

    /**
     * Create a ScopeType ('organization') + ScopedRoleDefinition for org-level roles.
     * This matches the pattern used in AccessDecisionTest::createOrgWithOrgAdminRole().
     *
     * @return array{0: ScopedRoleDefinition}
     */
    private function createOrgLevelRoleDefinition(
        string $roleKey,
        bool $isAdminRole = false,
        bool $canEdit = false,
        bool $canDelete = false,
        bool $canViewAll = false,
        bool $canManageMembers = false,
        array $permissions = []
    ): array {
        $scopeType = ScopeType::firstOrCreate(
            ['key' => ScopedRole::SCOPE_ORGANIZATION],
            [
                'label_ar' => 'المؤسسة',
                'label_en' => 'Organization',
                'model_class' => Organization::class,
                'supports_hierarchy' => false,
                'is_active' => true,
                'sort_order' => 0,
            ]
        );

        $existingId = DB::table('scoped_role_definitions')
            ->where('scope_type_id', $scopeType->id)
            ->where('role_key', $roleKey)
            ->value('id');

        if (! $existingId) {
            $mergedPermissions = $this->expandFlags($permissions, [
                'can_edit' => $canEdit,
                'can_delete' => $canDelete,
                'can_view_all' => $canViewAll,
                'can_manage_members' => $canManageMembers,
            ]);
            $permissionsJson = ! empty($mergedPermissions) ? json_encode(array_values($mergedPermissions)) : null;

            $existingId = DB::table('scoped_role_definitions')->insertGetId([
                'scope_type_id' => $scopeType->id,
                'role_key' => $roleKey,
                'name' => $roleKey,
                'display_name' => $roleKey,
                'scope_type' => ScopedRole::SCOPE_ORGANIZATION,
                'label_ar' => $roleKey,
                'label_en' => $roleKey,
                'is_admin_role' => $isAdminRole,
                'permissions' => $permissionsJson,
                'is_active' => true,
                'sort_order' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $roleDefinition = ScopedRoleDefinition::find($existingId);

        Cache::flush();

        return [$roleDefinition];
    }

    /**
     * Create a ScopeType ('department') + ScopedRoleDefinition for department-level roles.
     *
     * @return array{0: ScopeType, 1: ScopedRoleDefinition}
     */
    private function createDeptScopedRoleDefinition(
        string $roleKey,
        bool $isAdminRole = false,
        bool $canEdit = false,
        bool $canDelete = false,
        bool $canViewAll = false,
        bool $canManageMembers = false,
        array $permissions = []
    ): array {
        $scopeType = ScopeType::firstOrCreate(
            ['key' => 'department'],
            [
                'label_ar' => 'القسم',
                'label_en' => 'Department',
                'model_class' => Department::class,
                'supports_hierarchy' => true,
                'is_active' => true,
                'sort_order' => 0,
            ]
        );

        $existingId = DB::table('scoped_role_definitions')
            ->where('scope_type_id', $scopeType->id)
            ->where('role_key', $roleKey)
            ->value('id');

        if (! $existingId) {
            $mergedPermissions = $this->expandFlags($permissions, [
                'can_edit' => $canEdit,
                'can_delete' => $canDelete,
                'can_view_all' => $canViewAll,
                'can_manage_members' => $canManageMembers,
            ]);
            $permissionsJson = ! empty($mergedPermissions) ? json_encode(array_values($mergedPermissions)) : null;

            $existingId = DB::table('scoped_role_definitions')->insertGetId([
                'scope_type_id' => $scopeType->id,
                'role_key' => $roleKey,
                'name' => $roleKey,
                'display_name' => $roleKey,
                'scope_type' => 'department',
                'label_ar' => $roleKey,
                'label_en' => $roleKey,
                'is_admin_role' => $isAdminRole,
                'permissions' => $permissionsJson,
                'is_active' => true,
                'sort_order' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $roleDefinition = ScopedRoleDefinition::find($existingId);

        Cache::flush();

        return [$scopeType, $roleDefinition];
    }

    private function expandFlags(array $permissions, array $flags): array
    {
        $byAction = fn (array $actions): array => array_values(array_filter(
            Capability::all(),
            function (string $c) use ($actions) {
                $a = str_contains($c, '.') ? substr($c, strrpos($c, '.') + 1) : $c;

                return in_array($a, $actions, true);
            }
        ));
        if (! empty($flags['can_edit'])) {
            $permissions = array_merge($permissions, $byAction(['edit', 'update']));
        }
        if (! empty($flags['can_delete'])) {
            $permissions = array_merge($permissions, $byAction(['delete', 'remove']));
        }
        if (! empty($flags['can_view_all'])) {
            $permissions = array_merge($permissions, $byAction(['view', 'view_all', 'view_reports']));
        }
        if (! empty($flags['can_manage_members'])) {
            $permissions = array_merge($permissions, $byAction(['manage_members', 'assign_roles']));
        }
        if (! empty($flags['can_view_confidential'])) {
            $permissions[] = Capability::OVR_VIEW_CONFIDENTIAL;
        }

        return array_values(array_unique($permissions));
    }
}
