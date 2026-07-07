<?php

namespace Tests\Feature\Api\Shared;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use App\Modules\Shared\Models\Attachment;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PrivateAttachmentDownloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
        Notification::fake();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_authorized_same_organization_user_can_download_private_comment_attachment(): void
    {
        [$user, $project] = $this->makeProjectContext();
        $bytes = 'private attachment bytes';

        $storeResponse = $this->actingAs($user, 'sanctum')->postJson('/api/comments', [
            'commentable_type' => 'project',
            'commentable_id' => $project->id,
            'content' => 'تعليق بمرفق خاص',
            'attachments' => [UploadedFile::fake()->createWithContent('evidence.txt', $bytes)],
        ]);

        $storeResponse->assertCreated();
        $this->assertNoPublicAttachmentLeakage($storeResponse->json());

        $attachmentId = $storeResponse->json('comment.attachments.0.id');
        $attachment = Attachment::findOrFail($attachmentId);

        Storage::disk('local')->assertExists($attachment->file_path);
        Storage::disk('public')->assertMissing($attachment->file_path);

        $downloadResponse = $this->actingAs($user, 'sanctum')
            ->get("/api/attachments/{$attachment->id}/download");

        $downloadResponse->assertOk();
        $this->assertSame($bytes, $downloadResponse->streamedContent());
    }

    public function test_cross_organization_user_cannot_download_comment_attachment(): void
    {
        [$owner, $project] = $this->makeProjectContext();
        $crossOrgUser = $this->makeUserInOrganization(Organization::factory()->create());

        $storeResponse = $this->actingAs($owner, 'sanctum')->postJson('/api/comments', [
            'commentable_type' => 'project',
            'commentable_id' => $project->id,
            'content' => 'تعليق بمرفق خاص',
            'attachments' => [UploadedFile::fake()->createWithContent('secret.txt', 'secret')],
        ]);

        $storeResponse->assertCreated();
        $attachmentId = $storeResponse->json('comment.attachments.0.id');

        $response = $this->actingAs($crossOrgUser, 'sanctum')
            ->get("/api/attachments/{$attachmentId}/download");

        $this->assertContains($response->getStatusCode(), [403, 404]);
    }

    public function test_comment_attachment_json_does_not_expose_public_storage_urls(): void
    {
        [$user, $project] = $this->makeProjectContext();

        $storeResponse = $this->actingAs($user, 'sanctum')->postJson('/api/comments', [
            'commentable_type' => 'project',
            'commentable_id' => $project->id,
            'content' => 'تعليق بمرفق خاص',
            'attachments' => [UploadedFile::fake()->createWithContent('private.txt', 'private')],
        ]);

        $storeResponse->assertCreated();
        $this->assertNoPublicAttachmentLeakage($storeResponse->json());

        $commentId = $storeResponse->json('comment.id');
        $addResponse = $this->actingAs($user, 'sanctum')->postJson("/api/comments/{$commentId}/attachments", [
            'attachments' => [UploadedFile::fake()->createWithContent('private-2.txt', 'private-2')],
        ]);

        $addResponse->assertOk();
        $this->assertNoPublicAttachmentLeakage($addResponse->json());

        $indexResponse = $this->actingAs($user, 'sanctum')
            ->getJson('/api/comments?commentable_type=project&commentable_id='.$project->id);

        $indexResponse->assertOk();
        $this->assertNoPublicAttachmentLeakage($indexResponse->json());
    }

    public function test_deleting_attachment_soft_deletes_row_and_retains_private_file_until_purge(): void
    {
        [$user, $project] = $this->makeProjectContext();

        $storeResponse = $this->actingAs($user, 'sanctum')->postJson('/api/comments', [
            'commentable_type' => 'project',
            'commentable_id' => $project->id,
            'content' => 'تعليق بمرفق خاص',
            'attachments' => [UploadedFile::fake()->createWithContent('delete-me.txt', 'delete-me')],
        ]);

        $storeResponse->assertCreated();
        $commentId = $storeResponse->json('comment.id');
        $attachmentId = $storeResponse->json('comment.attachments.0.id');
        $attachment = Attachment::findOrFail($attachmentId);
        $path = $attachment->file_path;

        Storage::disk('local')->assertExists($path);

        $deleteResponse = $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/comments/{$commentId}/attachments/{$attachmentId}");

        $deleteResponse->assertOk();
        Storage::disk('local')->assertExists($path);
        Storage::disk('public')->assertMissing($path);
        $this->assertSoftDeleted('attachments', ['id' => $attachmentId]);
    }

    /**
     * @return array{0: User, 1: Project}
     */
    private function makeProjectContext(): array
    {
        $organization = Organization::factory()->create();
        $department = Department::factory()->create(['organization_id' => $organization->id]);
        $user = $this->makeUserInOrganization($organization, $department->id);
        $user->assignRole('admin');

        $project = Project::factory()->create([
            'organization_id' => $organization->id,
            'department_id' => $department->id,
            'created_by' => $user->id,
        ]);

        return [$user, $project];
    }

    private function makeUserInOrganization(Organization $organization, ?int $departmentId = null): User
    {
        $departmentId ??= Department::factory()->create(['organization_id' => $organization->id])->id;

        $user = User::factory()->create([
            'organization_id' => $organization->id,
            'department_id' => $departmentId,
            'is_active' => true,
        ]);
        $user->assignRole('member');

        return $user;
    }

    /**
     * @param  array<string, mixed>|array<int, mixed>  $payload
     */
    private function assertNoPublicAttachmentLeakage(array $payload): void
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('/storage/', $json);
        $this->assertStringNotContainsString('public/', $json);
        $this->assertStringNotContainsString('Storage::url', $json);
        $this->assertStringNotContainsString('"url"', $json);
    }
}
