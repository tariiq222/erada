<?php

namespace Tests\Unit\Models;

use App\Modules\Core\Models\Organization;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Tests\TestCase;

class ScopedRolesInverseTest extends TestCase
{
    public function test_organization_has_scoped_roles_relationship(): void
    {
        $org = new Organization;
        $this->assertInstanceOf(HasMany::class, $org->scopedRoles());
    }

    public function test_department_has_scoped_roles_relationship(): void
    {
        $dept = new Department;
        $this->assertInstanceOf(HasMany::class, $dept->scopedRoles());
    }

    public function test_project_has_scoped_roles_relationship(): void
    {
        $project = new Project;
        $this->assertInstanceOf(HasMany::class, $project->scopedRoles());
    }

    public function test_organization_scoped_roles_filters_by_scope_type(): void
    {
        $org = new Organization;
        $relation = $org->scopedRoles();
        $sql = $relation->toBase()->toSql();
        $this->assertStringContainsString('scope_type', $sql);
    }

    public function test_department_scoped_roles_filters_by_scope_type(): void
    {
        $dept = new Department;
        $relation = $dept->scopedRoles();
        $sql = $relation->toBase()->toSql();
        $this->assertStringContainsString('scope_type', $sql);
    }

    public function test_project_scoped_roles_filters_by_scope_type(): void
    {
        $project = new Project;
        $relation = $project->scopedRoles();
        $sql = $relation->toBase()->toSql();
        $this->assertStringContainsString('scope_type', $sql);
    }
}
