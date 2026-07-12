<?php

namespace Tests\Unit\Authorization;

use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Authorization\Models\AuthorizationRoleAssignment;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CanonicalDepartmentAssignmentsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Department $parentDepartment;

    private Department $childDepartment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parentDepartment = Department::factory()->create([
            'parent_id' => null,
            'level' => Department::LEVEL_DEPARTMENT,
        ]);
        $this->childDepartment = Department::factory()->create([
            'organization_id' => $this->parentDepartment->organization_id,
            'parent_id' => $this->parentDepartment->id,
            'level' => Department::LEVEL_SECTION,
        ]);
        $this->user = User::factory()->create([
            'organization_id' => $this->parentDepartment->organization_id,
            'department_id' => $this->childDepartment->id,
            'is_active' => true,
        ]);
    }

    public function test_role_in_department_returns_direct_canonical_role(): void
    {
        $this->createCanonicalDepartmentAssignment($this->childDepartment, 'dept_supervisor');

        $this->assertSame('dept_supervisor', $this->user->roleInDepartment($this->childDepartment));
    }

    public function test_role_in_department_inherits_from_ancestor(): void
    {
        $this->createCanonicalDepartmentAssignment($this->parentDepartment, 'dept_manager', true);

        $this->assertSame('dept_manager', $this->user->roleInDepartment($this->childDepartment));
    }

    public function test_role_is_not_inherited_without_inherit_to_children(): void
    {
        $this->createCanonicalDepartmentAssignment($this->parentDepartment, 'dept_manager');

        $this->assertNull($this->user->roleInDepartment($this->childDepartment));
    }

    public function test_expired_department_assignment_is_not_read(): void
    {
        $this->createCanonicalDepartmentAssignment($this->childDepartment, 'dept_manager', expiresAt: now()->subMinute());

        $this->assertNull($this->user->roleInDepartment($this->childDepartment));
    }

    private function createCanonicalDepartmentAssignment(
        Department $department,
        string $roleName,
        bool $inheritToChildren = false,
        mixed $expiresAt = null,
    ): AuthorizationRoleAssignment {
        $role = AuthorizationRole::query()->firstOrCreate(
            ['name' => $roleName],
            ['label' => $roleName, 'is_active' => true],
        );

        return AuthorizationRoleAssignment::query()->create([
            'authorization_role_id' => $role->id,
            'user_id' => $this->user->id,
            'scope_type' => AuthorizationRoleAssignment::SCOPE_DEPARTMENT,
            'scope_id' => $department->id,
            'organization_id' => $department->organization_id,
            'inherit_to_children' => $inheritToChildren,
            'source' => 'manual',
            'expires_at' => $expiresAt,
        ]);
    }
}
