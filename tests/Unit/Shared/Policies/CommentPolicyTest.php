<?php

namespace Tests\Unit\Shared\Policies;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use App\Modules\Shared\Models\Comment;
use App\Modules\Shared\Policies\CommentPolicy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CommentPolicyTest extends TestCase
{
    use DatabaseTransactions;

    protected CommentPolicy $policy;

    protected Organization $org;

    protected Department $dept;

    protected User $superAdmin;

    protected User $admin;

    protected User $member;

    protected User $otherMember;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->policy = new CommentPolicy;

        $this->org = Organization::factory()->create();
        $this->dept = Department::factory()->create(['organization_id' => $this->org->id]);

        $this->superAdmin = User::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
            'is_active' => true,
        ]);
        $this->grantCanonicalSuperAdmin($this->superAdmin);

        $this->admin = User::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
            'is_active' => true,
        ]);
        $this->grantCanonicalAdmin($this->admin);

        $this->member = User::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
            'is_active' => true,
        ]);
        $this->assignCanonicalRole($this->member, 'member');

        $this->otherMember = User::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
            'is_active' => true,
        ]);
        $this->assignCanonicalRole($this->otherMember, 'member');

        $this->project = Project::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
            'created_by' => $this->superAdmin->id,
        ]);
    }

    public function test_super_admin_before_returns_true(): void
    {
        $result = $this->policy->before($this->superAdmin, 'any_ability');

        $this->assertTrue($result);
    }

    public function test_non_super_admin_before_returns_null(): void
    {
        $result = $this->policy->before($this->member, 'any_ability');

        $this->assertNull($result);
    }

    public function test_view_any_returns_true(): void
    {
        $this->assertTrue($this->policy->viewAny($this->member));
    }

    public function test_create_requires_create_comments_permission(): void
    {
        $userWithoutPerm = User::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
            'is_active' => true,
        ]);
        $this->assignCanonicalRole($userWithoutPerm, 'member');
        // 'member' role has 'create_comments' permission per seeder, so create a user with no role
        $plainUser = User::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
            'is_active' => true,
        ]);

        $this->assertFalse($this->policy->create($plainUser));
    }

    public function test_create_passes_with_admin_role(): void
    {
        // Engine: admin role grants all capabilities via is_admin_role=true
        $this->assertTrue($this->policy->create($this->admin));
    }

    public function test_update_owner_can_update(): void
    {
        $comment = Comment::factory()->create([
            'commentable_type' => Project::class,
            'commentable_id' => $this->project->id,
            'user_id' => $this->member->id,
        ]);

        $this->assertTrue($this->policy->update($this->member, $comment));
    }

    public function test_update_non_owner_without_edit_permission_cannot_update(): void
    {
        $comment = Comment::factory()->create([
            'commentable_type' => Project::class,
            'commentable_id' => $this->project->id,
            'user_id' => $this->member->id,
        ]);

        // otherMember also has 'edit_comments' permission via 'member' role (per seeder)
        // so we use a plain user (no role) to test the deny path
        $plainUser = User::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
            'is_active' => true,
        ]);

        $this->assertFalse($this->policy->update($plainUser, $comment));
    }

    public function test_update_non_owner_with_edit_comments_permission_can_update(): void
    {
        $comment = Comment::factory()->create([
            'commentable_type' => Project::class,
            'commentable_id' => $this->project->id,
            'user_id' => $this->member->id,
        ]);

        // admin role grants 'edit_comments' permission
        $this->assertTrue($this->policy->update($this->admin, $comment));
    }

    public function test_delete_owner_can_delete(): void
    {
        $comment = Comment::factory()->create([
            'commentable_type' => Project::class,
            'commentable_id' => $this->project->id,
            'user_id' => $this->member->id,
        ]);

        $this->assertTrue($this->policy->delete($this->member, $comment));
    }

    public function test_delete_with_delete_comments_permission_can_delete_any(): void
    {
        $comment = Comment::factory()->create([
            'commentable_type' => Project::class,
            'commentable_id' => $this->project->id,
            'user_id' => $this->member->id,
        ]);

        // admin role grants 'delete_comments' permission
        $this->assertTrue($this->policy->delete($this->admin, $comment));
    }

    public function test_delete_project_admin_can_delete_via_scoped_role(): void
    {
        $projectManager = User::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
            'is_active' => true,
        ]);

        $this->assignCanonicalRole($projectManager, 'project_manager', 'project', (int) $this->project->id);

        $comment = Comment::factory()->create([
            'commentable_type' => Project::class,
            'commentable_id' => $this->project->id,
            'user_id' => $this->member->id,
        ]);

        $this->assertTrue($this->policy->delete($projectManager, $comment));
    }

    public function test_delete_project_member_without_team_admin_capability_is_denied(): void
    {
        $projectMember = User::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
            'is_active' => true,
        ]);

        $this->assignCanonicalRole($projectMember, 'project_member', 'project', (int) $this->project->id);

        $comment = Comment::factory()->create([
            'commentable_type' => Project::class,
            'commentable_id' => $this->project->id,
            'user_id' => $this->member->id,
        ]);

        $this->assertFalse($this->policy->delete($projectMember, $comment));
    }

    public function test_view_returns_true_for_project_creator(): void
    {
        $creator = User::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
            'is_active' => true,
        ]);
        // Admin can view projects in their department.
        $this->grantCanonicalAdmin($creator);

        $project = Project::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
            'created_by' => $creator->id,
        ]);

        $comment = Comment::factory()->create([
            'commentable_type' => Project::class,
            'commentable_id' => $project->id,
            'user_id' => $creator->id,
        ]);

        $this->assertTrue($this->policy->view($creator, $comment));
    }

    public function test_view_returns_false_when_commentable_missing(): void
    {
        $comment = Comment::factory()->create([
            'commentable_type' => Project::class,
            'commentable_id' => 99999,
            'user_id' => $this->member->id,
        ]);

        $this->assertFalse($this->policy->view($this->member, $comment));
    }

    public function test_view_returns_false_for_unrelated_user(): void
    {
        $comment = Comment::factory()->create([
            'commentable_type' => Project::class,
            'commentable_id' => $this->project->id,
            'user_id' => $this->member->id,
        ]);

        $unrelated = User::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
            'is_active' => true,
        ]);
        // plain user (no role) - cannot view project
        $this->assignCanonicalRole($unrelated, 'member');
        // Force a different org / no project access
        $otherOrg = Organization::factory()->create();
        $unrelated->update(['organization_id' => $otherOrg->id]);

        $this->assertFalse($this->policy->view($unrelated, $comment));
    }
}
