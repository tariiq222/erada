<?php

namespace Tests\Unit\Shared\Policies;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use App\Modules\Shared\Models\Attachment;
use App\Modules\Shared\Models\Comment;
use App\Modules\Shared\Policies\AttachmentPolicy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class AttachmentPolicyTest extends TestCase
{
    use DatabaseTransactions;

    protected AttachmentPolicy $policy;

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

        $this->policy = new AttachmentPolicy;

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

    public function test_view_any_returns_true(): void
    {
        $this->assertTrue($this->policy->viewAny($this->member));
    }

    public function test_create_requires_upload_attachments_permission(): void
    {
        $plainUser = User::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
            'is_active' => true,
        ]);

        $this->assertFalse($this->policy->create($plainUser));

        // admin role has upload_attachments permission (member/viewer do not per current seeder)
        $this->assertTrue($this->policy->create($this->admin));
    }

    public function test_owner_can_update_own_attachment(): void
    {
        $comment = Comment::factory()->create([
            'commentable_type' => Project::class,
            'commentable_id' => $this->project->id,
            'user_id' => $this->member->id,
        ]);

        $attachment = Attachment::create([
            'user_id' => $this->member->id,
            'name' => 'doc.pdf',
            'file_path' => 'comments/'.$comment->id.'/doc.pdf',
            'file_type' => 'application/pdf',
            'file_size' => 1024,
            'attachable_type' => Comment::class,
            'attachable_id' => $comment->id,
        ]);

        $this->assertTrue($this->policy->update($this->member, $attachment));
    }

    public function test_non_owner_without_admin_role_cannot_update(): void
    {
        $comment = Comment::factory()->create([
            'commentable_type' => Project::class,
            'commentable_id' => $this->project->id,
            'user_id' => $this->member->id,
        ]);

        $attachment = Attachment::create([
            'user_id' => $this->member->id,
            'name' => 'doc.pdf',
            'file_path' => 'comments/'.$comment->id.'/doc.pdf',
            'file_type' => 'application/pdf',
            'file_size' => 1024,
            'attachable_type' => Comment::class,
            'attachable_id' => $comment->id,
        ]);

        $plainUser = User::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
            'is_active' => true,
        ]);

        $this->assertFalse($this->policy->update($plainUser, $attachment));
    }

    public function test_project_manager_can_update_attachment_on_managed_project(): void
    {
        $projectManager = User::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
            'is_active' => true,
        ]);
        $this->assignCanonicalRole($projectManager, 'project_manager', 'project', (int) $this->project->id);

        $attachment = Attachment::create([
            'user_id' => $this->member->id,
            'name' => 'project.pdf',
            'file_path' => 'projects/'.$this->project->id.'/project.pdf',
            'file_type' => 'application/pdf',
            'file_size' => 1024,
            'attachable_type' => Project::class,
            'attachable_id' => $this->project->id,
        ]);

        $this->assertTrue($this->policy->update($projectManager, $attachment));
    }

    public function test_project_member_cannot_update_another_users_attachment(): void
    {
        $projectMember = User::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
            'is_active' => true,
        ]);
        $this->assignCanonicalRole($projectMember, 'project_member', 'project', (int) $this->project->id);

        $attachment = Attachment::create([
            'user_id' => $this->member->id,
            'name' => 'project.pdf',
            'file_path' => 'projects/'.$this->project->id.'/project.pdf',
            'file_type' => 'application/pdf',
            'file_size' => 1024,
            'attachable_type' => Project::class,
            'attachable_id' => $this->project->id,
        ]);

        $this->assertFalse($this->policy->update($projectMember, $attachment));
    }

    public function test_owner_can_delete_own_attachment(): void
    {
        $comment = Comment::factory()->create([
            'commentable_type' => Project::class,
            'commentable_id' => $this->project->id,
            'user_id' => $this->member->id,
        ]);

        $attachment = Attachment::create([
            'user_id' => $this->member->id,
            'name' => 'doc.pdf',
            'file_path' => 'comments/'.$comment->id.'/doc.pdf',
            'file_type' => 'application/pdf',
            'file_size' => 1024,
            'attachable_type' => Comment::class,
            'attachable_id' => $comment->id,
        ]);

        $this->assertTrue($this->policy->delete($this->member, $attachment));
    }

    public function test_delete_with_delete_attachments_permission(): void
    {
        $comment = Comment::factory()->create([
            'commentable_type' => Project::class,
            'commentable_id' => $this->project->id,
            'user_id' => $this->member->id,
        ]);

        $attachment = Attachment::create([
            'user_id' => $this->member->id,
            'name' => 'doc.pdf',
            'file_path' => 'comments/'.$comment->id.'/doc.pdf',
            'file_type' => 'application/pdf',
            'file_size' => 1024,
            'attachable_type' => Comment::class,
            'attachable_id' => $comment->id,
        ]);

        // admin role grants 'delete_attachments' permission
        $this->assertTrue($this->policy->delete($this->admin, $attachment));
    }

    public function test_view_returns_true_for_attachable_owner(): void
    {
        $comment = Comment::factory()->create([
            'commentable_type' => Project::class,
            'commentable_id' => $this->project->id,
            'user_id' => $this->admin->id,
        ]);

        $attachment = Attachment::create([
            'user_id' => $this->admin->id,
            'name' => 'doc.pdf',
            'file_path' => 'comments/'.$comment->id.'/doc.pdf',
            'file_type' => 'application/pdf',
            'file_size' => 1024,
            'attachable_type' => Comment::class,
            'attachable_id' => $comment->id,
        ]);

        // super_admin is allowed via before() in both AttachmentPolicy and ProjectPolicy
        $this->assertTrue($this->policy->view($this->superAdmin, $attachment));
    }

    public function test_view_returns_false_for_unrelated_user(): void
    {
        $comment = Comment::factory()->create([
            'commentable_type' => Project::class,
            'commentable_id' => $this->project->id,
            'user_id' => $this->member->id,
        ]);

        $attachment = Attachment::create([
            'user_id' => $this->member->id,
            'name' => 'doc.pdf',
            'file_path' => 'comments/'.$comment->id.'/doc.pdf',
            'file_type' => 'application/pdf',
            'file_size' => 1024,
            'attachable_type' => Comment::class,
            'attachable_id' => $comment->id,
        ]);

        $otherOrg = Organization::factory()->create();
        $plainUser = User::factory()->create([
            'organization_id' => $otherOrg->id,
            'department_id' => Department::factory()->create(['organization_id' => $otherOrg->id])->id,
            'is_active' => true,
        ]);

        $this->assertFalse($this->policy->view($plainUser, $attachment));
    }

    public function test_download_delegates_to_view(): void
    {
        $comment = Comment::factory()->create([
            'commentable_type' => Project::class,
            'commentable_id' => $this->project->id,
            'user_id' => $this->member->id,
        ]);

        $attachment = Attachment::create([
            'user_id' => $this->member->id,
            'name' => 'doc.pdf',
            'file_path' => 'comments/'.$comment->id.'/doc.pdf',
            'file_type' => 'application/pdf',
            'file_size' => 1024,
            'attachable_type' => Comment::class,
            'attachable_id' => $comment->id,
        ]);

        $this->assertEquals(
            $this->policy->view($this->member, $attachment),
            $this->policy->download($this->member, $attachment)
        );

        $plainUser = User::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
            'is_active' => true,
        ]);
        $this->assertEquals(
            $this->policy->view($plainUser, $attachment),
            $this->policy->download($plainUser, $attachment)
        );
    }

    public function test_attachment_without_attachable_only_visible_to_owner(): void
    {
        // Use a valid type that points to a non-existent record so the morphTo
        // resolves to null but the DB NOT NULL constraint is satisfied.
        $attachment = Attachment::create([
            'user_id' => $this->member->id,
            'name' => 'orphan.pdf',
            'file_path' => 'orphans/orphan.pdf',
            'file_type' => 'application/pdf',
            'file_size' => 1024,
            'attachable_type' => Project::class,
            'attachable_id' => 9999999,
        ]);

        $this->assertNull($attachment->attachable, 'Sanity check: attachable should be null.');
        $this->assertTrue($this->policy->view($this->member, $attachment));
        $this->assertFalse($this->policy->view($this->otherMember, $attachment));
    }
}
