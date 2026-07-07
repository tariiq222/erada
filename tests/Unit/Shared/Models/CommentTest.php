<?php

namespace Tests\Unit\Shared\Models;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use App\Modules\Shared\Models\Attachment;
use App\Modules\Shared\Models\Comment;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CommentTest extends TestCase
{
    use DatabaseTransactions;

    public function test_fillable_attributes(): void
    {
        $comment = new Comment;

        $expected = ['user_id', 'content', 'commentable_type', 'commentable_id'];

        $this->assertEquals($expected, $comment->getFillable());
    }

    public function test_user_relation(): void
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $user = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
            'is_active' => true,
        ]);
        $project = Project::factory()->create(['department_id' => $dept->id]);

        $comment = Comment::factory()->create([
            'commentable_type' => Project::class,
            'commentable_id' => $project->id,
            'user_id' => $user->id,
        ]);

        $this->assertInstanceOf(User::class, $comment->user);
        $this->assertEquals($user->id, $comment->user->id);
    }

    public function test_commentable_morph_to(): void
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $user = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
            'is_active' => true,
        ]);
        $project = Project::factory()->create(['department_id' => $dept->id]);

        $comment = Comment::factory()->create([
            'commentable_type' => Project::class,
            'commentable_id' => $project->id,
            'user_id' => $user->id,
        ]);

        $this->assertInstanceOf(Project::class, $comment->commentable);
        $this->assertEquals($project->id, $comment->commentable->id);
    }

    public function test_attachments_relation(): void
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $user = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
            'is_active' => true,
        ]);
        $project = Project::factory()->create(['department_id' => $dept->id]);

        $comment = Comment::factory()->create([
            'commentable_type' => Project::class,
            'commentable_id' => $project->id,
            'user_id' => $user->id,
        ]);

        Attachment::create([
            'user_id' => $user->id,
            'name' => 'doc.pdf',
            'file_path' => 'comments/'.$comment->id.'/doc.pdf',
            'file_type' => 'application/pdf',
            'file_size' => 1024,
            'attachable_type' => Comment::class,
            'attachable_id' => $comment->id,
        ]);

        $this->assertCount(1, $comment->attachments);
        $this->assertInstanceOf(Attachment::class, $comment->attachments->first());
    }

    public function test_soft_deletes(): void
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $user = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
            'is_active' => true,
        ]);
        $project = Project::factory()->create(['department_id' => $dept->id]);

        $comment = Comment::factory()->create([
            'commentable_type' => Project::class,
            'commentable_id' => $project->id,
            'user_id' => $user->id,
        ]);

        $id = $comment->id;
        $comment->delete();

        $this->assertNull(Comment::find($id));
        $this->assertNotNull(Comment::withTrashed()->find($id));
    }

    public function test_tracked_fields_contains_content(): void
    {
        $comment = new Comment;

        $this->assertContains('content', $comment->getTrackedFields());
    }
}
