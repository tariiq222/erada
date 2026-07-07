<?php

namespace Tests\Unit\Shared\Http\Resources;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use App\Modules\Shared\Http\Resources\AttachmentResource;
use App\Modules\Shared\Models\Attachment;
use App\Modules\Shared\Models\Comment;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Tests\TestCase;

class AttachmentResourceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_resource_array_shape_with_relations_loaded(): void
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

        $attachment = Attachment::create([
            'user_id' => $user->id,
            'name' => 'document.pdf',
            'file_path' => 'comments/'.$comment->id.'/document.pdf',
            'file_type' => 'application/pdf',
            'file_size' => 1024,
            'attachable_type' => Comment::class,
            'attachable_id' => $comment->id,
        ]);

        $attachment->load('user');

        $request = Request::create('/api/attachments/'.$attachment->id);
        $array = (new AttachmentResource($attachment))->toArray($request);

        $this->assertArrayHasKey('id', $array);
        $this->assertArrayHasKey('name', $array);
        $this->assertArrayHasKey('file_type', $array);
        $this->assertArrayHasKey('file_size', $array);
        $this->assertArrayHasKey('formatted_size', $array);
        $this->assertArrayHasKey('download_url', $array);
        $this->assertArrayHasKey('user', $array);
        $this->assertArrayHasKey('created_at', $array);

        $this->assertEquals($attachment->id, $array['id']);
        $this->assertEquals('document.pdf', $array['name']);
        $this->assertEquals('application/pdf', $array['file_type']);
        $this->assertEquals(1024, $array['file_size']);
        $this->assertStringContainsString('KB', $array['formatted_size']);
        $this->assertStringContainsString('/api/attachments/'.$attachment->id.'/download', $array['download_url']);
    }

    public function test_resource_returns_basic_fields_when_user_not_loaded(): void
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $user = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
            'is_active' => true,
        ]);
        $project = Project::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
        ]);

        $attachment = Attachment::create([
            'user_id' => $user->id,
            'name' => 'doc.pdf',
            'file_path' => 'projects/'.$project->id.'/doc.pdf',
            'file_type' => 'application/pdf',
            'file_size' => 500,
            'attachable_type' => Project::class,
            'attachable_id' => $project->id,
        ]);

        $request = Request::create('/api/attachments/'.$attachment->id);
        $array = (new AttachmentResource($attachment))->toArray($request);

        // Core fields are always present
        $this->assertArrayHasKey('id', $array);
        $this->assertArrayHasKey('name', $array);
        $this->assertArrayHasKey('file_type', $array);
        $this->assertArrayHasKey('file_size', $array);
        $this->assertArrayHasKey('formatted_size', $array);
        $this->assertArrayHasKey('download_url', $array);
        $this->assertEquals('500 bytes', $array['formatted_size']);
    }
}
