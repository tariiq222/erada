<?php

namespace Tests\Unit\Models;

use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DepartmentHierarchyTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_all_children_ids_includes_self(): void
    {
        $root = Department::factory()->create(['level' => 1, 'parent_id' => null]);

        $ids = $root->getAllChildrenIds();

        $this->assertContains($root->id, $ids);
    }

    public function test_get_all_children_ids_includes_direct_children(): void
    {
        $root = Department::factory()->create(['level' => 1, 'parent_id' => null]);
        $child = Department::factory()->create(['level' => 2, 'parent_id' => $root->id]);

        $ids = $root->getAllChildrenIds();

        $this->assertContains($child->id, $ids);
    }

    public function test_get_all_children_ids_includes_grandchildren(): void
    {
        $root = Department::factory()->create(['level' => 1, 'parent_id' => null]);
        $child = Department::factory()->create(['level' => 2, 'parent_id' => $root->id]);
        $grandchild = Department::factory()->create(['level' => 3, 'parent_id' => $child->id]);

        $ids = $root->getAllChildrenIds();

        $this->assertContains($root->id, $ids);
        $this->assertContains($child->id, $ids);
        $this->assertContains($grandchild->id, $ids);
        $this->assertCount(3, $ids);
    }

    public function test_leaf_department_returns_only_itself(): void
    {
        $root = Department::factory()->create(['level' => 1, 'parent_id' => null]);
        $leaf = Department::factory()->create(['level' => 2, 'parent_id' => $root->id]);

        $ids = $leaf->getAllChildrenIds();

        $this->assertContains($leaf->id, $ids);
        $this->assertNotContains($root->id, $ids);
    }

    public function test_hierarchy_helpers_return_ancestors_depth_path_and_leaf_state(): void
    {
        $root = Department::factory()->create(['name' => 'Root Department', 'level' => 1, 'parent_id' => null]);
        $child = Department::factory()->create(['name' => 'Child Department', 'level' => 2, 'parent_id' => $root->id]);
        $grandchild = Department::factory()->create(['name' => 'Grandchild Department', 'level' => 3, 'parent_id' => $child->id]);

        $this->assertTrue($root->isRoot());
        $this->assertFalse($child->isRoot());
        $this->assertFalse($root->isLeaf());
        $this->assertTrue($grandchild->isLeaf());
        $this->assertSame(2, $grandchild->getDepth());
        $this->assertSame('Root Department > Child Department > Grandchild Department', $grandchild->getFullPath());
        $this->assertSame([$root->id, $child->id], $grandchild->getAncestors()->pluck('id')->all());
        $this->assertSame([$child->id, $grandchild->id], $root->getAllChildren()->pluck('id')->all());
    }

    public function test_aggregate_helpers_return_users_projects_and_tasks_for_descendants(): void
    {
        $root = Department::factory()->create(['level' => 1, 'parent_id' => null]);
        $child = Department::factory()->create([
            'level' => 2,
            'parent_id' => $root->id,
            'organization_id' => $root->organization_id,
        ]);
        $outside = Department::factory()->create(['level' => 1, 'parent_id' => null]);

        $rootUser = User::factory()->create([
            'organization_id' => $root->organization_id,
            'department_id' => $root->id,
        ]);
        $childUser = User::factory()->create([
            'organization_id' => $root->organization_id,
            'department_id' => $child->id,
        ]);
        $outsideUser = User::factory()->create([
            'organization_id' => $outside->organization_id,
            'department_id' => $outside->id,
        ]);

        $rootProject = Project::factory()->create([
            'organization_id' => $root->organization_id,
            'department_id' => $root->id,
        ]);
        $childProject = Project::factory()->create([
            'organization_id' => $root->organization_id,
            'department_id' => $child->id,
        ]);
        $outsideProject = Project::factory()->create([
            'organization_id' => $outside->organization_id,
            'department_id' => $outside->id,
        ]);

        $rootTask = Task::factory()->create([
            'project_id' => $rootProject->id,
            'department_id' => $root->id,
            'assigned_to' => $rootUser->id,
            'type' => 'department',
        ]);
        $childTask = Task::factory()->create([
            'project_id' => $childProject->id,
            'department_id' => $child->id,
            'assigned_to' => $childUser->id,
            'type' => 'department',
        ]);
        $outsideTask = Task::factory()->create([
            'project_id' => $outsideProject->id,
            'department_id' => $outside->id,
            'assigned_to' => $outsideUser->id,
            'type' => 'department',
        ]);

        $this->assertEqualsCanonicalizing([$rootUser->id, $childUser->id], $root->getAllUsers()->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$rootProject->id, $childProject->id], $root->getAllProjects()->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$rootTask->id, $childTask->id], $root->getAllTasks()->pluck('id')->all());

        $this->assertNotContains($outsideUser->id, $root->getAllUsers()->pluck('id')->all());
        $this->assertNotContains($outsideProject->id, $root->getAllProjects()->pluck('id')->all());
        $this->assertNotContains($outsideTask->id, $root->getAllTasks()->pluck('id')->all());
    }

    public function test_query_scopes_filter_active_root_level_top_management_and_organization(): void
    {
        $root = Department::factory()->create(['level' => 1, 'parent_id' => null, 'is_active' => true]);
        $child = Department::factory()->create([
            'level' => 2,
            'parent_id' => $root->id,
            'organization_id' => $root->organization_id,
            'is_active' => false,
        ]);
        Department::factory()->create(['level' => 1, 'parent_id' => null, 'is_active' => true]);

        $this->assertTrue(Department::active()->pluck('id')->contains($root->id));
        $this->assertFalse(Department::active()->pluck('id')->contains($child->id));
        $this->assertTrue(Department::root()->pluck('id')->contains($root->id));
        $this->assertFalse(Department::root()->pluck('id')->contains($child->id));
        $this->assertSame([$child->id], Department::level(2)->pluck('id')->all());
        $this->assertTrue(Department::topManagement()->pluck('id')->contains($root->id));
        $this->assertSame([$root->id, $child->id], Department::forOrganization($root->organization_id)->orderBy('id')->pluck('id')->all());
    }
}
