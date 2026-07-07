<?php

namespace Tests\Unit\HR\Concerns;

use App\Modules\Core\Models\ScopedRole;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * اختبارات HasDepartmentRoles Trait
 *
 * تتحقق من:
 * - استعلام الأدوار المباشرة
 * - الوراثة من الأقسام الأب (الفرع يأخذ دور الأصل عبر `orderByRaw` المُعامَل
 *   بالـ bindings بدلاً من string interpolation)
 */
class HasDepartmentRolesTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Department $parentDepartment;

    protected Department $childDepartment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->parentDepartment = Department::factory()->create([
            'parent_id' => null,
            'level' => Department::LEVEL_DEPARTMENT,
        ]);

        $this->childDepartment = Department::factory()->create([
            'parent_id' => $this->parentDepartment->id,
            'level' => Department::LEVEL_SECTION,
        ]);

        $this->user = User::factory()->create([
            'department_id' => $this->childDepartment->id,
            'is_active' => true,
        ]);
    }

    /**
     * دور مباشر على القسم — لا يمر بمسار الوراثة
     */
    public function test_role_in_department_returns_direct_role(): void
    {
        $this->user->assignDepartmentRole($this->childDepartment, ScopedRole::DEPARTMENT_SUPERVISOR);

        $role = $this->user->roleInDepartment($this->childDepartment);

        $this->assertEquals(ScopedRole::DEPARTMENT_SUPERVISOR, $role);
    }

    /**
     * وراثة الدور من القسم الأب — هذا الفرع يستدعي `orderByRaw`
     * بالـ bindings بعد إصلاح P3-F
     */
    public function test_role_in_department_inherits_from_ancestor_with_inherit_to_children(): void
    {
        $this->user->assignDepartmentRole(
            $this->parentDepartment,
            ScopedRole::DEPARTMENT_MANAGER,
            null,
            true
        );

        $role = $this->user->roleInDepartment($this->childDepartment);

        $this->assertEquals(ScopedRole::DEPARTMENT_MANAGER, $role);
    }

    /**
     * لا يوجد دور مباشر ولا وراثي
     */
    public function test_role_in_department_returns_null_when_no_role_anywhere(): void
    {
        $role = $this->user->roleInDepartment($this->childDepartment);

        $this->assertNull($role);
    }

    /**
     * `inherit_to_children = false` يمنع تسرّب الدور من الأب للابن
     */
    public function test_role_is_not_inherited_when_inherit_to_children_is_false(): void
    {
        $this->user->assignDepartmentRole(
            $this->parentDepartment,
            ScopedRole::DEPARTMENT_MANAGER,
            null,
            false
        );

        $role = $this->user->roleInDepartment($this->childDepartment);

        $this->assertNull($role);
    }

    /**
     * سلسلة أعمق (3 مستويات) — يستدعي `array_position` بأكثر من placeholder
     */
    public function test_role_in_department_inherits_through_deep_hierarchy(): void
    {
        $grandchild = Department::factory()->create([
            'parent_id' => $this->childDepartment->id,
            'level' => Department::LEVEL_UNIT,
        ]);

        $this->user->assignDepartmentRole(
            $this->parentDepartment,
            ScopedRole::DEPARTMENT_MANAGER,
            null,
            true
        );

        $role = $this->user->roleInDepartment($grandchild);

        $this->assertEquals(ScopedRole::DEPARTMENT_MANAGER, $role);
    }
}
