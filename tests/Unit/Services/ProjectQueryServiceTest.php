<?php

namespace Tests\Unit\Services;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\ScopedRole;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Services\ProjectQueryService;
use App\Modules\Tasks\Enums\TaskStatus;
use App\Modules\Tasks\Models\Task;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProjectQueryServiceTest extends TestCase
{
    use RefreshDatabase;

    protected ProjectQueryService $service;

    protected User $superAdmin;

    protected User $regularUser;

    protected Department $department;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->department = Department::factory()->create();

        $this->superAdmin = User::factory()->create([
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        $this->superAdmin->assignRole('super_admin');

        $this->regularUser = User::factory()->create([
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);

        $this->service = app(ProjectQueryService::class);
    }

    public function test_super_admin_sees_all_projects(): void
    {
        Project::factory()->count(3)->create(['department_id' => $this->department->id]);

        $query = $this->service->baseQuery();
        $query = $this->service->applyPermissionFilter($query, $this->superAdmin);
        $result = $query->get();

        $this->assertCount(3, $result);
    }

    public function test_regular_user_sees_only_own_projects(): void
    {
        // Project where user is manager (scoped role بدل عمود manager_id)
        $ownProject = Project::factory()->create([
            'department_id' => $this->department->id,
        ]);
        $this->regularUser->assignProjectRole($ownProject, ScopedRole::PROJECT_MANAGER);

        // Projects with no relation to user
        Project::factory()->count(2)->create(['department_id' => $this->department->id]);

        $query = $this->service->baseQuery();
        $query = $this->service->applyPermissionFilter($query, $this->regularUser);
        $result = $query->get();

        $this->assertCount(1, $result);
        $this->assertEquals($ownProject->id, $result->first()->id);
    }

    public function test_regular_user_sees_projects_as_member(): void
    {
        $project = Project::factory()->create(['department_id' => $this->department->id]);
        $this->regularUser->assignProjectRole($project, ScopedRole::PROJECT_MEMBER);

        // Unrelated project
        Project::factory()->create(['department_id' => $this->department->id]);

        $query = $this->service->baseQuery();
        $query = $this->service->applyPermissionFilter($query, $this->regularUser);
        $result = $query->get();

        $this->assertCount(1, $result);
        $this->assertEquals($project->id, $result->first()->id);
    }

    public function test_apply_filters_by_status(): void
    {
        Project::factory()->create(['department_id' => $this->department->id, 'status' => 'in_progress']);
        Project::factory()->create(['department_id' => $this->department->id, 'status' => 'completed']);
        Project::factory()->create(['department_id' => $this->department->id, 'status' => 'in_progress']);

        $request = Request::create('/projects', 'GET', ['status' => 'in_progress']);

        $query = $this->service->baseQuery();
        $query = $this->service->applyFilters($query, $request);
        $result = $query->get();

        $this->assertCount(2, $result);
        $result->each(fn ($p) => $this->assertEquals('in_progress', $p->status));
    }

    public function test_apply_filters_by_search(): void
    {
        Project::factory()->create(['department_id' => $this->department->id, 'name' => 'Alpha Project']);
        Project::factory()->create(['department_id' => $this->department->id, 'name' => 'Beta Project']);
        Project::factory()->create(['department_id' => $this->department->id, 'name' => 'Gamma Initiative']);

        $request = Request::create('/projects', 'GET', ['search' => 'Project']);

        $query = $this->service->baseQuery();
        $query = $this->service->applyFilters($query, $request);
        $result = $query->get();

        $this->assertCount(2, $result);
    }

    public function test_apply_sorting_defaults_to_created_at_desc(): void
    {
        $old = Project::factory()->create([
            'department_id' => $this->department->id,
            'created_at' => now()->subSeconds(10),
        ]);
        $new = Project::factory()->create([
            'department_id' => $this->department->id,
            'created_at' => now(),
        ]);

        $request = Request::create('/projects', 'GET');

        $query = $this->service->baseQuery();
        $query = $this->service->applySorting($query, $request);
        $result = $query->get();

        $this->assertEquals($new->id, $result->first()->id);
        $this->assertEquals($old->id, $result->last()->id);
    }

    public function test_apply_sorting_rejects_unknown_columns(): void
    {
        $older = Project::factory()->create(['department_id' => $this->department->id]);
        \DB::table('projects')->where('id', $older->id)->update(['created_at' => now()->subMinutes(5)]);

        $newer = Project::factory()->create(['department_id' => $this->department->id]);
        \DB::table('projects')->where('id', $newer->id)->update(['created_at' => now()->addMinutes(5)]);

        $request = Request::create('/projects', 'GET', [
            'sort_by' => 'id; DROP TABLE projects; --',
            'sort_dir' => 'asc',
        ]);

        $query = $this->service->baseQuery();
        // sort_by falls back to created_at (SQL injection guard); sort_dir 'asc' is preserved
        $query = $this->service->applySorting($query, $request);
        $result = $query->get();

        $this->assertCount(2, $result);
        // created_at asc: older project (earlier timestamp) comes first
        $this->assertEquals($older->id, $result->first()->id);
        $this->assertEquals($newer->id, $result->last()->id);
    }

    public function test_admin_sees_all_projects(): void
    {
        $admin = User::factory()->create([
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        $admin->assignRole('admin');

        Project::factory()->count(3)->create(['department_id' => $this->department->id]);

        $query = $this->service->baseQuery();
        $query = $this->service->applyPermissionFilter($query, $admin);
        $result = $query->get();

        $this->assertCount(3, $result);
    }

    // ملاحظة: اختبارا الرؤية «كمشرف» (supervisor_id) و«كراعٍ» (sponsor_id) حُذفا
    // بعد توحيد أدوار المشاريع — لم تعد هناك أعمدة أو فلترة رؤية بهذين المسارين.
    // الرؤية عبر الأدوار السياقية مغطاة في
    // test_regular_user_sees_projects_as_member و
    // test_regular_user_sees_projects_from_project_scoped_role.

    public function test_regular_user_sees_projects_they_created(): void
    {
        $project = Project::factory()->create([
            'department_id' => $this->department->id,
            'created_by' => $this->regularUser->id,
        ]);
        Project::factory()->create(['department_id' => $this->department->id]);

        $query = $this->service->baseQuery();
        $query = $this->service->applyPermissionFilter($query, $this->regularUser);
        $result = $query->get();

        $this->assertCount(1, $result);
        $this->assertEquals($project->id, $result->first()->id);
    }

    public function test_apply_filters_by_code_search(): void
    {
        // Project whose name doesn't match but code does
        $byCode = Project::factory()->create([
            'department_id' => $this->department->id,
            'name' => 'Unrelated Name',
            'code' => 'PRJ-TEST-C1',
        ]);
        // Project whose name matches but code doesn't
        Project::factory()->create([
            'department_id' => $this->department->id,
            'name' => 'TEST-C1 Project',
            'code' => 'PRJ-TEST-XXXX',
        ]);

        $request = Request::create('/projects', 'GET', ['search' => 'TEST-C1']);

        $query = $this->service->baseQuery();
        $query = $this->service->applyFilters($query, $request);
        $result = $query->get();

        // Both should match (name contains TEST-C1 OR code contains TEST-C1)
        $this->assertCount(2, $result);
    }

    public function test_get_project_stats_returns_task_counts(): void
    {
        $project = Project::factory()->create(['department_id' => $this->department->id]);

        Task::factory()->count(3)->create([
            'project_id' => $project->id,
            'type' => 'project',
            'status' => 'in_progress',
            'created_by' => $this->superAdmin->id,
        ]);
        Task::factory()->create([
            'project_id' => $project->id,
            'type' => 'project',
            'status' => 'completed',
            'created_by' => $this->superAdmin->id,
        ]);

        $result = $this->service->getProjectStats((string) $project->id, $this->superAdmin);

        $this->assertEquals(4, $result->tasks_count);
        $this->assertEquals(1, $result->completed_tasks_count);
    }

    public function test_get_paginated_list_returns_paginator(): void
    {
        Project::factory()->count(5)->create(['department_id' => $this->department->id]);

        $request = Request::create('/projects', 'GET', ['per_page' => 2]);

        $result = $this->service->getPaginatedList($request, $this->superAdmin);

        $this->assertEquals(2, $result->perPage());
        $this->assertEquals(5, $result->total());
    }

    public function test_regular_user_sees_projects_from_project_scoped_role(): void
    {
        $organization = Organization::factory()->create();
        $this->regularUser->forceFill(['organization_id' => $organization->id])->save();

        $coherentDept = Department::factory()->create(['organization_id' => $organization->id]);
        $project = Project::factory()->create([
            'organization_id' => $organization->id,
            'department_id' => $coherentDept->id,
        ]);
        Project::factory()->create([
            'organization_id' => $organization->id,
            'department_id' => $coherentDept->id,
        ]);

        $this->regularUser->assignProjectRole($project, ScopedRole::PROJECT_VIEWER, $this->superAdmin->id);

        $query = $this->service->baseQuery();
        $query = $this->service->applyPermissionFilter($query, $this->regularUser);
        $result = $query->get();

        $this->assertCount(1, $result);
        $this->assertEquals($project->id, $result->first()->id);
    }

    public function test_scoped_role_visibility_stays_limited_to_user_organization(): void
    {
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $this->regularUser->forceFill(['organization_id' => $organization->id])->save();

        $otherOrgProject = Project::factory()->create([
            'organization_id' => $otherOrganization->id,
            'department_id' => $this->department->id,
        ]);

        $this->regularUser->assignProjectRole($otherOrgProject, ScopedRole::PROJECT_VIEWER, $this->superAdmin->id);

        $query = $this->service->baseQuery();
        $query = $this->service->applyPermissionFilter($query, $this->regularUser);
        $result = $query->get();

        $this->assertCount(0, $result);
    }

    public function test_overdue_count_excludes_cancelled_tasks(): void
    {
        $project = Project::factory()->create(['department_id' => $this->department->id]);
        $past = now()->subDays(3);

        // 1) completed (past due) — must NOT count
        Task::factory()->create([
            'project_id' => $project->id,
            'type' => 'project',
            'status' => TaskStatus::COMPLETED->value,
            'due_date' => $past,
            'created_by' => $this->superAdmin->id,
        ]);
        // 2) cancelled (past due) — must NOT count
        Task::factory()->create([
            'project_id' => $project->id,
            'type' => 'project',
            'status' => TaskStatus::CANCELLED->value,
            'due_date' => $past,
            'created_by' => $this->superAdmin->id,
        ]);
        // 3) in_progress (past due) — the only one that should count
        Task::factory()->create([
            'project_id' => $project->id,
            'type' => 'project',
            'status' => TaskStatus::IN_PROGRESS->value,
            'due_date' => $past,
            'created_by' => $this->superAdmin->id,
        ]);

        $result = $this->service->getProjectStats((string) $project->id, $this->superAdmin);

        $this->assertEquals(3, $result->tasks_count);
        $this->assertEquals(1, $result->completed_tasks_count);
        $this->assertEquals(1, $result->overdue_tasks_count);
    }

    public function test_overdue_count_includes_in_progress_overdue(): void
    {
        $project = Project::factory()->create(['department_id' => $this->department->id]);

        Task::factory()->create([
            'project_id' => $project->id,
            'type' => 'project',
            'status' => TaskStatus::IN_PROGRESS->value,
            'due_date' => now()->subDay(),
            'created_by' => $this->superAdmin->id,
        ]);

        $result = $this->service->getProjectStats((string) $project->id, $this->superAdmin);

        $this->assertEquals(1, $result->overdue_tasks_count);
    }

    public function test_members_count_is_distinct_when_user_has_multiple_pivots(): void
    {
        $project = Project::factory()->create(['department_id' => $this->department->id]);
        $member = User::factory()->create([
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);

        // First scoped role pivot: project_manager
        DB::table('model_has_scoped_roles')->insert([
            'user_id' => $member->id,
            'role' => ScopedRole::PROJECT_MANAGER,
            'scope_type' => ScopedRole::SCOPE_PROJECT,
            'scope_id' => $project->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Second scoped role pivot for the same user on the same project
        // (e.g. role was upgraded, leaving both rows behind)
        DB::table('model_has_scoped_roles')->insert([
            'user_id' => $member->id,
            'role' => ScopedRole::PROJECT_MEMBER,
            'scope_type' => ScopedRole::SCOPE_PROJECT,
            'scope_id' => $project->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = $this->service->getProjectStats((string) $project->id, $this->superAdmin);

        $this->assertEquals(1, $result->members_count);
    }

    public function test_search_uses_case_insensitive_ilike(): void
    {
        $project = Project::factory()->create([
            'department_id' => $this->department->id,
            'name' => 'Project X',
            'code' => 'PRJ-X-001',
        ]);

        // lower-case query that would not match Postgres case-sensitive LIKE
        $request = Request::create('/projects', 'GET', ['search' => 'proj']);

        $query = $this->service->baseQuery();
        $query = $this->service->applyPermissionFilter($query, $this->superAdmin);
        $query = $this->service->applyFilters($query, $request);
        $result = $query->get();

        $this->assertCount(1, $result);
        $this->assertEquals($project->id, $result->first()->id);
    }
}
