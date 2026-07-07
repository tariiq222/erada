<?php

namespace Tests\Feature\Api;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use App\Modules\Shared\Models\Comment;
use App\Modules\Tasks\Models\Task;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CommentControllerTest extends TestCase
{
    use DatabaseTransactions;

    protected User $user;

    protected Department $department;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeaders(['X-Skip-Csrf' => '1']);

        Storage::fake('public');
        Notification::fake();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->department = Department::factory()->create();

        $this->user = User::factory()->create([
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        $this->user->assignRole('super_admin');

        $this->project = Project::factory()->create([
            'department_id' => $this->department->id,
        ]);
    }

    // ========================================
    // اختبارات قراءة التعليقات
    // ========================================

    public function test_can_list_project_comments(): void
    {
        Comment::factory()->count(3)->create([
            'commentable_type' => Project::class,
            'commentable_id' => $this->project->id,
            'user_id' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/comments?commentable_type=project&commentable_id='.$this->project->id);

        $response->assertStatus(200);
        $this->assertCount(3, $response->json());
    }

    public function test_can_list_task_comments(): void
    {
        $task = Task::factory()->create([
            'project_id' => $this->project->id,
            'assigned_to' => $this->user->id,
        ]);

        Comment::factory()->count(2)->create([
            'commentable_type' => Task::class,
            'commentable_id' => $task->id,
            'user_id' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/comments?commentable_type=task&commentable_id='.$task->id);

        $response->assertStatus(200);
        $this->assertCount(2, $response->json());
    }

    public function test_list_comments_requires_valid_type(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/comments?commentable_type=invalid&commentable_id=1');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['commentable_type']);
    }

    // ========================================
    // اختبارات إضافة التعليقات
    // ========================================

    public function test_can_add_comment_to_project(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/comments', [
                'commentable_type' => 'project',
                'commentable_id' => $this->project->id,
                'content' => 'هذا تعليق اختباري',
            ]);

        $response->assertStatus(201)
            ->assertJsonFragment(['content' => 'هذا تعليق اختباري']);

        $this->assertDatabaseHas('comments', [
            'content' => 'هذا تعليق اختباري',
            'commentable_type' => Project::class,
            'commentable_id' => $this->project->id,
        ]);
    }

    public function test_can_add_comment_to_task(): void
    {
        $task = Task::factory()->create([
            'project_id' => $this->project->id,
            'assigned_to' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/comments', [
                'commentable_type' => 'task',
                'commentable_id' => $task->id,
                'content' => 'تعليق على المهمة',
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('comments', [
            'content' => 'تعليق على المهمة',
            'commentable_type' => Task::class,
            'commentable_id' => $task->id,
        ]);
    }

    public function test_can_add_comment_with_attachments(): void
    {
        $file = UploadedFile::fake()->createWithContent('document.pdf', '%PDF-1.4\n'.str_repeat('%', 1024));

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/comments', [
                'commentable_type' => 'project',
                'commentable_id' => $this->project->id,
                'content' => 'تعليق مع مرفق',
                'attachments' => [$file],
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'comment' => [
                    'id',
                    'content',
                    'attachments',
                ],
            ]);

        $this->assertCount(1, $response->json('comment.attachments'));
    }

    public function test_can_add_comment_with_mentions(): void
    {
        $mentionedUser = User::factory()->create();

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/comments', [
                'commentable_type' => 'project',
                'commentable_id' => $this->project->id,
                'content' => 'مرحباً @'.$mentionedUser->name,
                'mentioned_users' => [$mentionedUser->id],
            ]);

        $response->assertStatus(201);
        $this->assertNotEmpty($response->json('comment.mentioned_users'));
    }

    public function test_add_comment_validation(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/comments', [
                'commentable_type' => 'project',
                'commentable_id' => $this->project->id,
                'content' => '', // فارغ
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['content']);
    }

    public function test_add_comment_content_max_length(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/comments', [
                'commentable_type' => 'project',
                'commentable_id' => $this->project->id,
                'content' => str_repeat('أ', 6000), // أكثر من 5000
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['content']);
    }

    // ========================================
    // اختبارات تحديث التعليقات
    // ========================================

    public function test_can_update_own_comment(): void
    {
        $comment = Comment::factory()->create([
            'commentable_type' => Project::class,
            'commentable_id' => $this->project->id,
            'user_id' => $this->user->id,
            'content' => 'محتوى قديم',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson("/api/comments/{$comment->id}", [
                'content' => 'محتوى جديد',
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('comments', [
            'id' => $comment->id,
            'content' => 'محتوى جديد',
        ]);
    }

    public function test_cannot_update_other_user_comment(): void
    {
        $otherUser = User::factory()->create();
        $regularUser = User::factory()->create([
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);

        $comment = Comment::factory()->create([
            'commentable_type' => Project::class,
            'commentable_id' => $this->project->id,
            'user_id' => $otherUser->id,
        ]);

        $response = $this->actingAs($regularUser, 'sanctum')
            ->putJson("/api/comments/{$comment->id}", [
                'content' => 'محاولة تعديل',
            ]);

        $response->assertStatus(403);
    }

    // ========================================
    // اختبارات حذف التعليقات
    // ========================================

    public function test_can_delete_own_comment(): void
    {
        $comment = Comment::factory()->create([
            'commentable_type' => Project::class,
            'commentable_id' => $this->project->id,
            'user_id' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/comments/{$comment->id}");

        $response->assertStatus(200);
        $this->assertSoftDeleted('comments', ['id' => $comment->id]);
    }

    public function test_super_admin_can_delete_any_comment(): void
    {
        $otherUser = User::factory()->create();

        $comment = Comment::factory()->create([
            'commentable_type' => Project::class,
            'commentable_id' => $this->project->id,
            'user_id' => $otherUser->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/comments/{$comment->id}");

        $response->assertStatus(200);
        $this->assertSoftDeleted('comments', ['id' => $comment->id]);
    }

    public function test_regular_user_cannot_delete_other_user_comment(): void
    {
        $regularUser = User::factory()->create([
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        $regularUser->assignRole('member');

        $comment = Comment::factory()->create([
            'commentable_type' => Project::class,
            'commentable_id' => $this->project->id,
            'user_id' => $this->user->id,
        ]);

        $response = $this->actingAs($regularUser, 'sanctum')
            ->deleteJson("/api/comments/{$comment->id}");

        $response->assertStatus(403);
    }

    // ========================================
    // اختبارات المرفقات
    // ========================================

    public function test_can_add_attachments_to_existing_comment(): void
    {
        $comment = Comment::factory()->create([
            'commentable_type' => Project::class,
            'commentable_id' => $this->project->id,
            'user_id' => $this->user->id,
        ]);

        $ihdr = "\x00\x00\x00\x0D".'IHDR'."\x00\x00\x00\x01\x00\x00\x00\x01\x08\x02\x00\x00\x00"."\x90\x77\x53\xDE";
        $idat = "\x00\x00\x00\x0C".'IDAT'."\x08\x99\x63\x00\x00\x00\x02\x00\x01"."\xE2\x21\xBC\x33";
        $iend = "\x00\x00\x00\x00".'IEND'."\xAE\x42\x60\x82";
        $png = "\x89\x50\x4E\x47\x0D\x0A\x1A\x0A".$ihdr.$idat.$iend.str_repeat("\x00", 512);
        $file = UploadedFile::fake()->createWithContent('image.png', $png);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/comments/{$comment->id}/attachments", [
                'attachments' => [$file],
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'attachments',
            ]);
    }

    public function test_can_delete_attachment_from_comment(): void
    {
        $comment = Comment::factory()->create([
            'commentable_type' => Project::class,
            'commentable_id' => $this->project->id,
            'user_id' => $this->user->id,
        ]);

        // إضافة مرفق
        $file = UploadedFile::fake()->createWithContent('doc.pdf', '%PDF-1.4\n'.str_repeat('%', 256));
        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/comments/{$comment->id}/attachments", [
                'attachments' => [$file],
            ]);

        $attachment = $comment->attachments()->first();

        $response = $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/comments/{$comment->id}/attachments/{$attachment->id}");

        $response->assertStatus(200);
    }

    // ========================================
    // اختبارات الأمان والصلاحيات
    // ========================================

    public function test_unauthenticated_cannot_access_comments(): void
    {
        $response = $this->getJson('/api/comments?commentable_type=project&commentable_id=1');
        $response->assertStatus(401);
    }

    public function test_unauthenticated_cannot_add_comment(): void
    {
        $response = $this->postJson('/api/comments', [
            'commentable_type' => 'project',
            'commentable_id' => 1,
            'content' => 'اختبار',
        ]);
        $response->assertStatus(401);
    }

    public function test_cannot_comment_on_non_existent_project(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/comments', [
                'commentable_type' => 'project',
                'commentable_id' => 99999,
                'content' => 'تعليق',
            ]);

        $response->assertStatus(404);
    }

    public function test_cannot_comment_on_project_without_access(): void
    {
        $otherDepartment = Department::factory()->create();
        $otherProject = Project::factory()->create([
            'department_id' => $otherDepartment->id,
        ]);

        $regularUser = User::factory()->create([
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        $regularUser->assignRole('member');

        $response = $this->actingAs($regularUser, 'sanctum')
            ->postJson('/api/comments', [
                'commentable_type' => 'project',
                'commentable_id' => $otherProject->id,
                'content' => 'تعليق',
            ]);

        $response->assertStatus(403);
    }

    public function test_admin_cannot_comment_on_cross_org_project_even_with_same_department(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        // Cross-org test: the admin sits in orgA; the target project lives in
        // orgB. ProjectObserver::saving forces project.organization_id to match
        // the project's department, so the project must use a department in orgB
        // (the sharedDepartment pattern is a test-setup trap and would silently
        // rewrite the project's org to orgA, masking the cross-org denial).
        $sharedDepartment = Department::factory()->create(['organization_id' => $orgA->id]);
        $orgBDepartment = Department::factory()->create(['organization_id' => $orgB->id]);

        $admin = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $sharedDepartment->id,
            'is_active' => true,
        ]);
        $admin->assignRole('admin');

        $crossOrgProject = Project::factory()->create([
            'organization_id' => $orgB->id,
            'department_id' => $orgBDepartment->id,
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/comments', [
                'commentable_type' => 'project',
                'commentable_id' => $crossOrgProject->id,
                'content' => 'تعليق cross-org',
            ]);

        $response->assertStatus(403);
    }

    // ============================================================
    // PATCH /api/comments/{id} — happy path + denial (route exists, was
    // untested). The route is the same as PUT; PATCH is mapped to update().
    // ============================================================

    public function test_patch_comment_updates_own_comment(): void
    {
        $comment = Comment::factory()->create([
            'commentable_type' => Project::class,
            'commentable_id' => $this->project->id,
            'user_id' => $this->user->id,
            'content' => 'محتوى قديم',
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/comments/{$comment->id}", [
                'content' => 'محتوى محدّث عبر PATCH',
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('comments', [
            'id' => $comment->id,
            'content' => 'محتوى محدّث عبر PATCH',
        ]);
    }

    public function test_patch_comment_denies_user_without_edit_permission(): void
    {
        // The PUT/PATCH controller delegates authz to UpdateCommentRequest.
        // A foreign user (not the owner, no COMMENTS_EDIT) must be denied.
        $owner = $this->user;
        $intruder = User::factory()->create([
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        $intruder->assignRole('member');

        $comment = Comment::factory()->create([
            'commentable_type' => Project::class,
            'commentable_id' => $this->project->id,
            'user_id' => $owner->id,
            'content' => 'محتوى محمي',
        ]);

        $this->actingAs($intruder, 'sanctum')
            ->patchJson("/api/comments/{$comment->id}", [
                'content' => 'محاولة تعديل',
            ])
            ->assertStatus(403);

        $this->assertDatabaseHas('comments', [
            'id' => $comment->id,
            'content' => 'محتوى محمي',
        ]);
    }

    public function test_patch_comment_requires_authentication(): void
    {
        $comment = Comment::factory()->create([
            'commentable_type' => Project::class,
            'commentable_id' => $this->project->id,
            'user_id' => $this->user->id,
        ]);

        $this->patchJson("/api/comments/{$comment->id}", [
            'content' => 'unauth attempt',
        ])->assertStatus(401);
    }

    // ============================================================
    // T-3.5 addAttachments IDOR — non-owner must get 403.
    // The route POST /api/comments/{id}/attachments enforces "owner only" via
    // a hard equality check (CommentController::addAttachments, lines 303-308).
    // ============================================================

    public function test_add_attachments_denies_non_owner_user(): void
    {
        $owner = $this->user;
        $intruder = User::factory()->create([
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        $intruder->assignRole('member');

        $comment = Comment::factory()->create([
            'commentable_type' => Project::class,
            'commentable_id' => $this->project->id,
            'user_id' => $owner->id,
        ]);

        $png = "\x89\x50\x4E\x47\x0D\x0A\x1A\x0A".
            "\x00\x00\x00\x0D".'IHDR'."\x00\x00\x00\x01\x00\x00\x00\x01\x08\x02\x00\x00\x00"."\x90\x77\x53\xDE".
            "\x00\x00\x00\x0C".'IDAT'."\x08\x99\x63\x00\x00\x00\x02\x00\x01"."\xE2\x21\xBC\x33".
            "\x00\x00\x00\x00".'IEND'."\xAE\x42\x60\x82".str_repeat("\x00", 512);
        $file = UploadedFile::fake()->createWithContent('intruder.png', $png);

        $this->actingAs($intruder, 'sanctum')
            ->postJson("/api/comments/{$comment->id}/attachments", [
                'attachments' => [$file],
            ])
            ->assertStatus(403)
            ->assertJson(['message' => 'غير مصرح لك بإضافة مرفقات لهذا التعليق']);

        $this->assertSame(0, $comment->attachments()->count(), 'no attachment may be created');
    }

    public function test_add_attachments_requires_authentication(): void
    {
        $comment = Comment::factory()->create([
            'commentable_type' => Project::class,
            'commentable_id' => $this->project->id,
            'user_id' => $this->user->id,
        ]);

        $png = "\x89\x50\x4E\x47\x0D\x0A\x1A\x0A".
            "\x00\x00\x00\x0D".'IHDR'."\x00\x00\x00\x01\x00\x00\x00\x01\x08\x02\x00\x00\x00"."\x90\x77\x53\xDE".
            "\x00\x00\x00\x00".'IEND'."\xAE\x42\x60\x82";
        $file = UploadedFile::fake()->createWithContent('pixel.png', $png);

        $this->postJson("/api/comments/{$comment->id}/attachments", [
            'attachments' => [$file],
        ])->assertStatus(401);
    }

    // ============================================================
    // T-3.5 unauthenticated GET /api/attachments/{id}/download -> 401.
    // ============================================================

    public function test_attachment_download_requires_authentication(): void
    {
        // Build a private attachment via the happy-path store, then verify the
        // download endpoint rejects unauthenticated requests with 401.
        $bytes = 'private attachment bytes';
        $storeResponse = $this->actingAs($this->user, 'sanctum')->postJson('/api/comments', [
            'commentable_type' => 'project',
            'commentable_id' => $this->project->id,
            'content' => 'تعليق بمرفق خاص',
            'attachments' => [UploadedFile::fake()->createWithContent('evidence.txt', $bytes)],
        ]);
        $storeResponse->assertCreated();

        $attachmentId = $storeResponse->json('comment.attachments.0.id');

        // The Laravel test framework's actingAs() persists the user on the
        // auth guard for the rest of the request lifecycle. Reset every guard
        // so the next call hits auth:sanctum as anonymous.
        $this->app['auth']->forgetGuards();

        // getJson() sets Accept: application/json so the auth middleware
        // returns 401 (instead of the 302 redirect it would emit for HTML).
        $this->getJson("/api/attachments/{$attachmentId}/download")->assertStatus(401);
    }

    // ============================================================
    // P0 cross-org IDOR regression for update() and destroy().
    // The author of the comment sits in orgA and is super_admin within it;
    // the project lives in orgB. Without the controller's explicit
    // authorizeCommentableParent() defense-in-depth (and the FormRequest's
    // engine gate), a super_admin in orgA could mutate or delete a comment
    // whose parent project lives in orgB. ProjectObserver::saving forces
    // project.organization_id to match its department, so the cross-org
    // project MUST use a department in orgB.
    // ============================================================

    public function test_cross_org_user_cannot_update_comment(): void
    {
        $originalContent = 'محتوى محمي عبر المؤسسات';

        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();

        $orgADepartment = Department::factory()->create(['organization_id' => $orgA->id]);
        $orgBDepartment = Department::factory()->create(['organization_id' => $orgB->id]);

        // Author sits in orgA and is super_admin within it.
        $author = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $orgADepartment->id,
            'is_active' => true,
        ]);
        $author->assignRole('super_admin');

        $crossOrgProject = Project::factory()->create([
            'organization_id' => $orgB->id,
            'department_id' => $orgBDepartment->id,
        ]);

        $comment = Comment::factory()->create([
            'commentable_type' => Project::class,
            'commentable_id' => $crossOrgProject->id,
            'user_id' => $author->id,
            'content' => $originalContent,
        ]);

        $response = $this->actingAs($author, 'sanctum')
            ->putJson("/api/comments/{$comment->id}", [
                'content' => 'محاولة تعديل cross-org',
            ]);

        $response->assertStatus(403);

        // Comment content must be unchanged — no partial write on denial.
        $this->assertDatabaseHas('comments', [
            'id' => $comment->id,
            'content' => $originalContent,
        ]);
    }

    public function test_cross_org_user_cannot_delete_comment(): void
    {
        $originalContent = 'محتوى محمي عبر المؤسسات';

        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();

        $orgADepartment = Department::factory()->create(['organization_id' => $orgA->id]);
        $orgBDepartment = Department::factory()->create(['organization_id' => $orgB->id]);

        // Author sits in orgA and is super_admin within it.
        $author = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $orgADepartment->id,
            'is_active' => true,
        ]);
        $author->assignRole('super_admin');

        $crossOrgProject = Project::factory()->create([
            'organization_id' => $orgB->id,
            'department_id' => $orgBDepartment->id,
        ]);

        $comment = Comment::factory()->create([
            'commentable_type' => Project::class,
            'commentable_id' => $crossOrgProject->id,
            'user_id' => $author->id,
            'content' => $originalContent,
        ]);

        $response = $this->actingAs($author, 'sanctum')
            ->deleteJson("/api/comments/{$comment->id}");

        $response->assertStatus(403);

        // Comment must NOT be soft-deleted by the cross-org request.
        $this->assertDatabaseHas('comments', [
            'id' => $comment->id,
            'deleted_at' => null,
        ]);
    }
}
