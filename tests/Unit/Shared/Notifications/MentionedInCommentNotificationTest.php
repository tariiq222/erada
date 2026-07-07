<?php

namespace Tests\Unit\Shared\Notifications;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use App\Modules\Shared\Models\Comment;
use App\Modules\Shared\Notifications\MentionedInCommentNotification;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class MentionedInCommentNotificationTest extends TestCase
{
    use DatabaseTransactions;

    protected User $mentioned;

    protected User $mentionedBy;

    protected Project $project;

    protected Comment $comment;

    protected function setUp(): void
    {
        parent::setUp();

        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);

        $this->mentionedBy = User::factory()->create([
            'name' => 'علي الكاتب',
            'organization_id' => $org->id,
            'department_id' => $dept->id,
            'is_active' => true,
        ]);
        $this->mentioned = User::factory()->create([
            'name' => 'سالم المعلق',
            'organization_id' => $org->id,
            'department_id' => $dept->id,
            'is_active' => true,
        ]);

        $this->project = Project::factory()->create(['department_id' => $dept->id]);
        $this->comment = Comment::factory()->create([
            'commentable_type' => Project::class,
            'commentable_id' => $this->project->id,
            'user_id' => $this->mentionedBy->id,
            'content' => 'مرحبا @سالم أرجو الاطلاع',
        ]);
    }

    public function test_via_returns_mail_and_database(): void
    {
        $notification = new MentionedInCommentNotification(
            $this->comment,
            $this->mentionedBy,
            'project',
            $this->project->id,
            $this->project->name
        );

        $channels = $notification->via($this->mentioned);

        $this->assertEquals(['mail', 'database'], $channels);
    }

    public function test_to_mail_contains_mention_details_for_project(): void
    {
        $notification = new MentionedInCommentNotification(
            $this->comment,
            $this->mentionedBy,
            'project',
            $this->project->id,
            $this->project->name
        );

        $mail = $notification->toMail($this->mentioned);

        $this->assertEquals('تم ذكرك في تعليق', $mail->subject);
        $this->assertStringContainsString($this->mentioned->name, $mail->greeting);
        $this->assertStringContainsString($this->mentionedBy->name, $mail->introLines[0] ?? '');
        $this->assertStringContainsString($this->project->name, $mail->introLines[1] ?? '');
        $this->assertStringContainsString('/projects/'.$this->project->id, $mail->actionUrl);
    }

    public function test_to_mail_uses_task_url_for_task_context(): void
    {
        $notification = new MentionedInCommentNotification(
            $this->comment,
            $this->mentionedBy,
            'task',
            123,
            'مهمة الاختبار'
        );

        $mail = $notification->toMail($this->mentioned);

        $this->assertStringContainsString('/tasks/123', $mail->actionUrl);
        $this->assertStringContainsString('المهمة', $mail->introLines[1] ?? '');
    }

    public function test_to_array_contains_required_keys(): void
    {
        $notification = new MentionedInCommentNotification(
            $this->comment,
            $this->mentionedBy,
            'project',
            $this->project->id,
            $this->project->name
        );

        $array = $notification->toArray($this->mentioned);

        $this->assertArrayHasKey('type', $array);
        $this->assertArrayHasKey('comment_id', $array);
        $this->assertArrayHasKey('mentioned_by_id', $array);
        $this->assertArrayHasKey('mentioned_by_name', $array);
        $this->assertArrayHasKey('context_type', $array);
        $this->assertArrayHasKey('context_id', $array);
        $this->assertArrayHasKey('context_name', $array);
        $this->assertArrayHasKey('content_preview', $array);
        $this->assertArrayHasKey('message', $array);

        $this->assertEquals('mention_in_comment', $array['type']);
        $this->assertEquals($this->comment->id, $array['comment_id']);
        $this->assertEquals($this->mentionedBy->id, $array['mentioned_by_id']);
        $this->assertEquals($this->mentionedBy->name, $array['mentioned_by_name']);
        $this->assertEquals('project', $array['context_type']);
        $this->assertEquals($this->project->id, $array['context_id']);
        $this->assertEquals($this->project->name, $array['context_name']);
    }

    public function test_content_preview_truncated_at_100_chars(): void
    {
        $longContent = str_repeat('أ', 250);
        $longComment = Comment::factory()->create([
            'commentable_type' => Project::class,
            'commentable_id' => $this->project->id,
            'user_id' => $this->mentionedBy->id,
            'content' => $longContent,
        ]);

        $notification = new MentionedInCommentNotification(
            $longComment,
            $this->mentionedBy,
            'project',
            $this->project->id,
            $this->project->name
        );

        $array = $notification->toArray($this->mentioned);

        $this->assertLessThanOrEqual(100, mb_strlen($array['content_preview']));
    }

    public function test_content_preview_preserves_short_content(): void
    {
        $shortComment = Comment::factory()->create([
            'commentable_type' => Project::class,
            'commentable_id' => $this->project->id,
            'user_id' => $this->mentionedBy->id,
            'content' => 'قصير',
        ]);

        $notification = new MentionedInCommentNotification(
            $shortComment,
            $this->mentionedBy,
            'project',
            $this->project->id,
            $this->project->name
        );

        $array = $notification->toArray($this->mentioned);

        $this->assertEquals('قصير', $array['content_preview']);
    }
}
