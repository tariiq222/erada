<?php

namespace Tests\Feature\Shared;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use App\Modules\Shared\Models\ActivityLog;
use App\Modules\Shared\Models\Attachment;
use App\Modules\Shared\Models\Comment;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PrivateAttachmentRetentionTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeaders(['X-Skip-Csrf' => '1']);

        Storage::fake('local');
        Storage::fake('public');
        Notification::fake();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_deleting_single_comment_attachment_soft_deletes_row_and_retains_private_file(): void
    {
        [$user, $project] = $this->makeProjectContext();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/comments', [
            'commentable_type' => 'project',
            'commentable_id' => $project->id,
            'content' => 'تعليق بمرفق خاص',
            'attachments' => [UploadedFile::fake()->createWithContent('retained-single.txt', 'private bytes')],
        ]);

        $response->assertCreated();
        $commentId = $response->json('comment.id');
        $attachmentId = $response->json('comment.attachments.0.id');
        $attachment = Attachment::findOrFail($attachmentId);
        $path = $attachment->file_path;

        Storage::disk('local')->assertExists($path);

        $deleteResponse = $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/comments/{$commentId}/attachments/{$attachmentId}");

        $deleteResponse->assertOk()
            ->assertJson(['message' => 'تم حذف المرفق بنجاح']);

        $this->assertSoftDeleted('attachments', ['id' => $attachmentId]);
        Storage::disk('local')->assertExists($path);
    }

    public function test_deleting_comment_soft_deletes_comment_attachments_and_retains_private_files(): void
    {
        [$user, $project] = $this->makeProjectContext();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/comments', [
            'commentable_type' => 'project',
            'commentable_id' => $project->id,
            'content' => 'تعليق بمرفقات خاصة',
            'attachments' => [
                UploadedFile::fake()->createWithContent('retained-one.txt', 'one'),
                UploadedFile::fake()->createWithContent('retained-two.txt', 'two'),
            ],
        ]);

        $response->assertCreated();
        $commentId = $response->json('comment.id');
        $attachmentIds = collect($response->json('comment.attachments'))->pluck('id')->all();
        $paths = Attachment::whereIn('id', $attachmentIds)->pluck('file_path')->all();

        $deleteResponse = $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/comments/{$commentId}");

        $deleteResponse->assertOk()
            ->assertJson(['message' => 'تم حذف التعليق بنجاح']);

        $this->assertSoftDeleted('comments', ['id' => $commentId]);

        foreach ($attachmentIds as $attachmentId) {
            $this->assertSoftDeleted('attachments', ['id' => $attachmentId]);
        }

        foreach ($paths as $path) {
            Storage::disk('local')->assertExists($path);
        }
    }

    public function test_touched_attachment_delete_activity_payloads_are_pii_minimized(): void
    {
        [$user, $project] = $this->makeProjectContext();
        $commentContent = 'sensitive comment content should not be logged by delete payloads';
        $filename = 'sensitive-original-name.txt';

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/comments', [
            'commentable_type' => 'project',
            'commentable_id' => $project->id,
            'content' => $commentContent,
            'attachments' => [UploadedFile::fake()->createWithContent($filename, 'private bytes')],
        ]);

        $response->assertCreated();
        $commentId = $response->json('comment.id');
        $attachmentId = $response->json('comment.attachments.0.id');
        $path = Attachment::findOrFail($attachmentId)->file_path;

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/comments/{$commentId}/attachments/{$attachmentId}")
            ->assertOk();

        $logs = ActivityLog::query()
            ->where(function ($query) use ($attachmentId) {
                $query->whereIn('action', ['attachment_deleted', 'comment_deleted'])
                    ->orWhere(function ($query) use ($attachmentId) {
                        $query->where('loggable_type', Attachment::class)
                            ->where('loggable_id', (string) $attachmentId);
                    });
            })
            ->get(['old_values', 'new_values', 'metadata']);

        $this->assertNotEmpty($logs);
        $this->assertSensitiveValuesAbsent($logs->toArray(), [$filename, $path, '/storage/', "comments/{$commentId}/", $commentContent]);
    }

    public function test_purge_command_deletes_only_eligible_soft_deleted_private_comment_attachments(): void
    {
        [$user, $project] = $this->makeProjectContext();
        $comment = Comment::factory()->forProject($project)->create(['user_id' => $user->id]);

        $eligible = $this->makeAttachment($user, $comment, 'comments/'.$comment->id.'/eligible.txt', 'eligible-original.txt');
        $missingFile = $this->makeAttachment($user, $comment, 'comments/'.$comment->id.'/missing.txt', 'missing-original.txt', putFile: false);
        $recent = $this->makeAttachment($user, $comment, 'comments/'.$comment->id.'/recent.txt', 'recent-original.txt');
        $active = $this->makeAttachment($user, $comment, 'comments/'.$comment->id.'/active.txt', 'active-original.txt');
        $nonComment = $this->makeAttachment($user, $project, 'comments/non-comment/project.txt', 'project-original.txt');

        $this->softDeleteAttachmentAt($eligible, now()->subDays(31));
        $this->softDeleteAttachmentAt($missingFile, now()->subDays(31));
        $this->softDeleteAttachmentAt($recent, now()->subDays(3));
        $this->softDeleteAttachmentAt($nonComment, now()->subDays(31));

        // Intentional scope guard: this plan covers comment/private attachments only; non-comment retention is untouched.
        $this->artisan('attachments:purge-private', ['--days' => '30'])->assertExitCode(0);

        Storage::disk('local')->assertMissing($eligible->file_path);
        Storage::disk('local')->assertExists($recent->file_path);
        Storage::disk('local')->assertExists($active->file_path);
        Storage::disk('local')->assertExists($nonComment->file_path);

        $this->assertNull(Attachment::withTrashed()->find($eligible->id));
        $this->assertNull(Attachment::withTrashed()->find($missingFile->id));
        $this->assertNotNull(Attachment::withTrashed()->find($recent->id));
        $this->assertNotNull(Attachment::find($active->id));
        $this->assertNotNull(Attachment::withTrashed()->find($nonComment->id));
    }

    public function test_purge_command_dry_run_reports_without_deleting_files_or_rows(): void
    {
        [$user, $project] = $this->makeProjectContext();
        $comment = Comment::factory()->forProject($project)->create(['user_id' => $user->id]);
        $eligible = $this->makeAttachment($user, $comment, 'comments/'.$comment->id.'/dry-run.txt', 'dry-run-original.txt');

        $this->softDeleteAttachmentAt($eligible, now()->subDays(31));

        $this->artisan('attachments:purge-private', ['--days' => '30', '--dry-run' => true])
            ->expectsOutput('Eligible: 1; purged: 0; missing files: 0; dry-run: yes.')
            ->assertExitCode(0);

        Storage::disk('local')->assertExists($eligible->file_path);
        $this->assertNotNull(Attachment::withTrashed()->find($eligible->id));
    }

    public function test_purge_activity_payload_contains_counts_and_ids_without_pii(): void
    {
        [$user, $project] = $this->makeProjectContext();
        $comment = Comment::factory()->forProject($project)->create([
            'user_id' => $user->id,
            'content' => 'purge sensitive comment content',
        ]);
        $attachment = $this->makeAttachment($user, $comment, 'comments/'.$comment->id.'/purge-sensitive.txt', 'purge-sensitive-original.txt');

        $this->softDeleteAttachmentAt($attachment, now()->subDays(31));

        $this->artisan('attachments:purge-private', ['--days' => '30'])->assertExitCode(0);

        $log = ActivityLog::query()->where('action', 'private_attachments_purged')->latest('id')->firstOrFail();

        $this->assertSame(1, $log->metadata['eligible_count']);
        $this->assertSame(1, $log->metadata['purged_count']);
        $this->assertContains($attachment->id, $log->metadata['attachment_ids']);
        $this->assertSensitiveValuesAbsent($log->only(['old_values', 'new_values', 'metadata']), [
            'purge-sensitive-original.txt',
            $attachment->file_path,
            '/storage/',
            "comments/{$comment->id}/",
            'purge sensitive comment content',
            $user->email,
        ]);
    }

    /**
     * @return array{0: User, 1: Project}
     */
    private function makeProjectContext(): array
    {
        $organization = Organization::factory()->create();
        $department = Department::factory()->create(['organization_id' => $organization->id]);
        $user = User::factory()->create([
            'organization_id' => $organization->id,
            'department_id' => $department->id,
            'is_active' => true,
        ]);
        $user->assignRole('admin');

        $project = Project::factory()->create([
            'organization_id' => $organization->id,
            'department_id' => $department->id,
            'created_by' => $user->id,
        ]);

        return [$user, $project];
    }

    private function makeAttachment(User $user, object $attachable, string $path, string $name, bool $putFile = true): Attachment
    {
        if ($putFile) {
            Storage::disk('local')->put($path, 'private bytes');
        }

        return Attachment::create([
            'user_id' => $user->id,
            'attachable_type' => $attachable::class,
            'attachable_id' => $attachable->id,
            'name' => $name,
            'file_path' => $path,
            'file_type' => 'text/plain',
            'file_size' => 13,
        ]);
    }

    private function softDeleteAttachmentAt(Attachment $attachment, mixed $deletedAt): void
    {
        $attachment->delete();

        Attachment::withTrashed()
            ->findOrFail($attachment->id)
            ->forceFill(['deleted_at' => $deletedAt])
            ->saveQuietly();
    }

    /**
     * @param  array<mixed>  $payload
     * @param  array<int, string>  $sensitiveValues
     */
    private function assertSensitiveValuesAbsent(array $payload, array $sensitiveValues): void
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR);

        foreach ($sensitiveValues as $sensitiveValue) {
            $this->assertStringNotContainsString($sensitiveValue, $json);
        }
    }
}
