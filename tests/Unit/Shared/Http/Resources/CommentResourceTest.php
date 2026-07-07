<?php

namespace Tests\Unit\Shared\Http\Resources;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use App\Modules\Shared\Http\Resources\CommentResource;
use App\Modules\Shared\Models\Attachment;
use App\Modules\Shared\Models\Comment;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Tests\TestCase;

class CommentResourceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_resource_array_shape_with_empty_relations(): void
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
            'content' => 'مرحبا',
        ]);

        $request = Request::create('/api/comments/'.$comment->id);
        $array = (new CommentResource($comment))->toArray($request);

        $this->assertArrayHasKey('id', $array);
        $this->assertArrayHasKey('content', $array);
        $this->assertArrayHasKey('is_internal', $array);
        $this->assertArrayHasKey('created_at', $array);
        $this->assertArrayHasKey('updated_at', $array);
        $this->assertEquals('مرحبا', $array['content']);
    }

    public function test_resource_includes_user_and_attachments_when_loaded(): void
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
            'content' => 'تعليق مع مرفق',
        ]);

        Attachment::create([
            'user_id' => $user->id,
            'name' => 'a.pdf',
            'file_path' => 'comments/'.$comment->id.'/a.pdf',
            'file_type' => 'application/pdf',
            'file_size' => 2048,
            'attachable_type' => Comment::class,
            'attachable_id' => $comment->id,
        ]);

        $comment->load(['user', 'attachments']);

        $request = Request::create('/api/comments/'.$comment->id);
        $array = (new CommentResource($comment))->toArray($request);

        $this->assertArrayHasKey('user', $array);
        $this->assertArrayHasKey('attachments', $array);
        $this->assertCount(1, $array['attachments']);
    }
}
