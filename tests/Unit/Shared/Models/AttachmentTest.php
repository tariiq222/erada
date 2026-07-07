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

class AttachmentTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
    }

    public function test_fillable_attributes(): void
    {
        $attachment = new Attachment;

        $expected = [
            'user_id',
            'name',
            'file_path',
            'file_type',
            'file_size',
            'attachable_type',
            'attachable_id',
        ];

        $this->assertEquals($expected, $attachment->getFillable());
    }

    public function test_formatted_size_bytes(): void
    {
        $attachment = new Attachment(['file_size' => 500]);

        $this->assertEquals('500 bytes', $attachment->formatted_size);
    }

    public function test_formatted_size_kb(): void
    {
        $attachment = new Attachment(['file_size' => 2048]);

        $this->assertStringContainsString('KB', $attachment->formatted_size);
    }

    public function test_formatted_size_mb(): void
    {
        $attachment = new Attachment(['file_size' => 5 * 1024 * 1024]);

        $this->assertStringContainsString('MB', $attachment->formatted_size);
    }

    public function test_formatted_size_gb(): void
    {
        $attachment = new Attachment(['file_size' => 2 * 1024 * 1024 * 1024]);

        $this->assertStringContainsString('GB', $attachment->formatted_size);
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

        $attachment = Attachment::create([
            'user_id' => $user->id,
            'name' => 'doc.pdf',
            'file_path' => 'comments/'.$comment->id.'/doc.pdf',
            'file_type' => 'application/pdf',
            'file_size' => 1024,
            'attachable_type' => Comment::class,
            'attachable_id' => $comment->id,
        ]);

        $this->assertInstanceOf(User::class, $attachment->user);
        $this->assertEquals($user->id, $attachment->user->id);
    }

    public function test_attachable_morph_to(): void
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $user = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
            'is_active' => true,
        ]);
        $project = Project::factory()->create(['department_id' => $dept->id]);

        $attachment = Attachment::create([
            'user_id' => $user->id,
            'name' => 'doc.pdf',
            'file_path' => 'projects/'.$project->id.'/doc.pdf',
            'file_type' => 'application/pdf',
            'file_size' => 1024,
            'attachable_type' => Project::class,
            'attachable_id' => $project->id,
        ]);

        $this->assertInstanceOf(Project::class, $attachment->attachable);
        $this->assertEquals($project->id, $attachment->attachable->id);
    }

    public function test_appends_includes_formatted_size(): void
    {
        $attachment = new Attachment(['file_size' => 500]);

        $array = $attachment->toArray();

        $this->assertArrayHasKey('formatted_size', $array);
        $this->assertEquals('500 bytes', $array['formatted_size']);
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

        $attachment = Attachment::create([
            'user_id' => $user->id,
            'name' => 'doc.pdf',
            'file_path' => 'comments/'.$comment->id.'/doc.pdf',
            'file_type' => 'application/pdf',
            'file_size' => 1024,
            'attachable_type' => Comment::class,
            'attachable_id' => $comment->id,
        ]);

        $id = $attachment->id;
        $attachment->delete();

        $this->assertNull(Attachment::find($id));
        $this->assertNotNull(Attachment::withTrashed()->find($id));
    }
}
