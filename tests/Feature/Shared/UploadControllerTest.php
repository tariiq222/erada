<?php

namespace Tests\Feature\Shared;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use App\Modules\Shared\Models\ActivityLog;
use App\Modules\Tasks\Models\Task;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

class UploadControllerTest extends TestCase
{
    use DatabaseTransactions;
    use GrantsEngineCapability;

    protected Organization $org;

    protected Department $dept;

    protected User $superAdmin;

    protected User $admin;

    protected User $member;

    protected Project $project;

    private const PNG_MAGIC = "\x89\x50\x4E\x47\x0D\x0A\x1A\x0A";

    private const JPG_MAGIC = "\xFF\xD8\xFF\xE0\x00\x10\x4A\x46\x49\x46";

    private const PDF_MAGIC = '%PDF-1.4';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeaders(['X-Skip-Csrf' => '1']);

        Storage::fake('public');
        Storage::fake('local');
        Notification::fake();

        $this->seed(RolesAndPermissionsSeeder::class);

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
        // isAdmin() checks AccessDecision::can(SETTINGS_MANAGE); grant via the engine so the
        // admin Spatie role resolves to the engine definition carrying that capability.
        $this->grantEngineCapability($this->admin, Capability::SETTINGS_MANAGE);

        $this->member = User::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
            'is_active' => true,
        ]);
        $this->assignCanonicalRole($this->member, 'member');

        $this->project = Project::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
            'created_by' => $this->superAdmin->id,
        ]);
    }

    private function validPngBytes(): string
    {
        // Minimal valid PNG: signature + IHDR (1x1, 8-bit, RGB) + IDAT + IEND
        // so finfo identifies the buffer as image/png (not application/octet-stream).
        $ihdr = "\x00\x00\x00\x0D".'IHDR'
            ."\x00\x00\x00\x01\x00\x00\x00\x01\x08\x02\x00\x00\x00"
            ."\x90\x77\x53\xDE";
        $idat = "\x00\x00\x00\x0C".'IDAT'
            ."\x08\x99\x63\x00\x00\x00\x02\x00\x01"
            ."\xE2\x21\xBC\x33";
        $iend = "\x00\x00\x00\x00".'IEND'."\xAE\x42\x60\x82";

        return self::PNG_MAGIC.$ihdr.$idat.$iend;
    }

    private function validJpgBytes(): string
    {
        return self::JPG_MAGIC.str_repeat("\x00", 100);
    }

    private function validPdfBytes(): string
    {
        return self::PDF_MAGIC."\n".str_repeat('%', 200);
    }

    private function fakePngWithTextContent(): string
    {
        return 'This is plain text content, not a PNG image at all.';
    }

    private function fakeJpgWithTextContent(): string
    {
        return 'Definitely not a JPEG, just plain text.';
    }

    private function pngOfSize(int $kb): UploadedFile
    {
        $bytes = str_repeat("\x00", $kb * 1024);

        return UploadedFile::fake()->createWithContent('photo.png', self::PNG_MAGIC.$bytes);
    }

    // ============================================================
    // uploadImage
    // ============================================================

    public function test_upload_image_succeeds_with_valid_png(): void
    {
        $file = UploadedFile::fake()->createWithContent('photo.png', $this->validPngBytes());

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/upload/image', [
                'image' => $file,
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['message', 'url', 'path']);

        $path = $response->json('path');
        $url = $response->json('url');

        // M-17: images are private (org-scoped path) and served via an
        // authenticated endpoint, not a world-readable /storage/ URL.
        $this->assertStringContainsString('/api/upload/image/', $url);
        $this->assertMatchesRegularExpression('#^uploads/images/.+\.png$#', $path);
        Storage::disk('local')->assertExists($path);
    }

    public function test_upload_image_succeeds_with_valid_jpg(): void
    {
        $file = UploadedFile::fake()->createWithContent('photo.jpg', $this->validJpgBytes());

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/upload/image', [
                'image' => $file,
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['message', 'url', 'path']);

        $path = $response->json('path');

        $this->assertStringEndsWith('.jpg', $path);
        Storage::disk('local')->assertExists($path);
    }

    public function test_upload_image_rejects_svg(): void
    {
        $file = UploadedFile::fake()->createWithContent('image.svg', '<svg></svg>');

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/upload/image', [
                'image' => $file,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['image']);
    }

    public function test_upload_image_rejects_magic_bytes_mismatch(): void
    {
        $file = UploadedFile::fake()->createWithContent('image.png', $this->fakePngWithTextContent());

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/upload/image', [
                'image' => $file,
            ]);

        $response->assertStatus(422);
        $response->assertJsonFragment([
            'message' => 'نوع الملف غير صالح أو تم التلاعب به',
        ]);
    }

    public function test_upload_image_rejects_oversize(): void
    {
        // 5 MB + 1 KB PNG (limit is 5 MB) — ponytail: minimal oversize, avoids OOM in Docker
        $file = $this->pngOfSize(5 * 1024 + 1);

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/upload/image', [
                'image' => $file,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['image']);
    }

    public function test_upload_image_requires_file(): void
    {
        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/upload/image', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['image']);
    }

    public function test_upload_image_rejects_jpeg_extension_with_text_content(): void
    {
        // jpeg is in allowed mimes, but magic bytes fail
        $file = UploadedFile::fake()->createWithContent('photo.jpeg', $this->fakeJpgWithTextContent());

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/upload/image', [
                'image' => $file,
            ]);

        $response->assertStatus(422);
        $response->assertJsonFragment([
            'message' => 'نوع الملف غير صالح أو تم التلاعب به',
        ]);
    }

    // ============================================================
    // uploadLogo
    // ============================================================

    public function test_upload_logo_forbidden_for_member(): void
    {
        $file = UploadedFile::fake()->createWithContent('logo.png', $this->validPngBytes());

        $response = $this->actingAs($this->member, 'sanctum')
            ->postJson('/api/upload/logo', [
                'logo' => $file,
            ]);

        $response->assertStatus(403);
        // The global exception handler overrides abort() messages with a generic
        // Arabic "permission denied" string — just verify the response is a 403.
        $this->assertNotNull($response->json('message'));
    }

    public function test_upload_logo_succeeds_for_admin(): void
    {
        $file = UploadedFile::fake()->createWithContent('logo.png', $this->validPngBytes());

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/upload/logo', [
                'logo' => $file,
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['message', 'url']);

        $url = $response->json('url');
        $this->assertStringContainsString('/storage/logos/', $url);

        // Logo response does not include `path`, so verify the file was stored
        // on the (default) local disk by listing files under public/logos.
        $files = Storage::disk('local')->allFiles('public/logos');
        $this->assertNotEmpty($files, 'No files were stored under public/logos.');
        $this->assertStringContainsString('.png', $files[0]);
    }

    public function test_upload_logo_succeeds_for_superadmin(): void
    {
        $file = UploadedFile::fake()->createWithContent('logo.png', $this->validPngBytes());

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/upload/logo', [
                'logo' => $file,
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['message', 'url']);

        $url = $response->json('url');
        $this->assertStringContainsString('/storage/logos/', $url);
    }

    public function test_upload_logo_rejects_svg(): void
    {
        $file = UploadedFile::fake()->createWithContent('logo.svg', '<svg></svg>');

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/upload/logo', [
                'logo' => $file,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['logo']);
    }

    public function test_upload_logo_rejects_magic_bytes_mismatch(): void
    {
        $file = UploadedFile::fake()->createWithContent('logo.png', $this->fakePngWithTextContent());

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/upload/logo', [
                'logo' => $file,
            ]);

        $response->assertStatus(422);
        $response->assertJsonFragment([
            'message' => 'نوع الملف غير صالح أو تم التلاعب به',
        ]);
    }

    public function test_upload_logo_rejects_oversize(): void
    {
        // 2 MB + 1 KB PNG (limit is 2 MB) — ponytail: minimal oversize
        $file = $this->pngOfSize(2 * 1024 + 1);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/upload/logo', [
                'logo' => $file,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['logo']);
    }

    public function test_upload_logo_requires_file(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/upload/logo', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['logo']);
    }

    // ============================================================
    // uploadAttachment
    // ============================================================

    public function test_upload_attachment_to_project_succeeds_for_superadmin_and_logs_activity(): void
    {
        $file = UploadedFile::fake()->createWithContent('doc.png', $this->validPngBytes());

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/upload/attachment', [
                'file' => $file,
                'project_id' => $this->project->id,
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'path',
                'original_name',
                'size',
                'mime_type',
            ]);

        $path = $response->json('path');
        $this->assertStringStartsWith("projects/{$this->project->id}/attachments/", $path);
        Storage::disk('local')->assertExists($path);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'attachment_added',
            'loggable_type' => Project::class,
            'loggable_id' => $this->project->id,
            'user_id' => $this->superAdmin->id,
        ]);

        $log = DB::table('activity_logs')
            ->where('action', 'attachment_added')
            ->where('loggable_type', Project::class)
            ->where('loggable_id', $this->project->id)
            ->first();

        $this->assertNotNull($log);
        $newValues = json_decode($log->new_values, true);
        $this->assertArrayHasKey('file_name', $newValues);
        $this->assertEquals('doc.png', $newValues['file_name']);
    }

    public function test_upload_attachment_to_task_succeeds_for_authorized_user(): void
    {
        $task = Task::factory()->create([
            'project_id' => $this->project->id,
            'assigned_to' => $this->admin->id,
        ]);

        $file = UploadedFile::fake()->createWithContent('task.png', $this->validPngBytes());

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/upload/attachment', [
                'file' => $file,
                'task_id' => $task->id,
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'path',
                'original_name',
                'size',
                'mime_type',
            ]);

        $path = $response->json('path');
        $this->assertStringStartsWith("tasks/{$task->id}/attachments/", $path);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'attachment_added',
            'loggable_type' => Task::class,
            'loggable_id' => $task->id,
            'user_id' => $this->admin->id,
        ]);
    }

    /**
     * Upload authorization now mirrors ProjectPolicy::view exactly (unified engine).
     * The engine grants project view to any same-organization user whose position
     * oversees the project (a same-department member included), so the genuine
     * "forbidden" case is a CROSS-ORGANIZATION outsider — the engine denies them.
     */
    public function test_upload_attachment_forbidden_for_cross_org_user_on_project(): void
    {
        $otherOrg = Organization::factory()->create();
        $otherDept = Department::factory()->create(['organization_id' => $otherOrg->id]);
        $otherProject = Project::factory()->create([
            'organization_id' => $otherOrg->id,
            'department_id' => $otherDept->id,
            'created_by' => $this->superAdmin->id,
        ]);

        // Outsider belongs to a DIFFERENT organization — no oversight of the project.
        $crossOrg = Organization::factory()->create();
        $crossOrgDept = Department::factory()->create(['organization_id' => $crossOrg->id]);
        $outsider = User::factory()->create([
            'organization_id' => $crossOrg->id,
            'department_id' => $crossOrgDept->id,
            'is_active' => true,
        ]);
        $this->assignCanonicalRole($outsider, 'member');

        $file = UploadedFile::fake()->createWithContent('photo.png', $this->validPngBytes());

        $response = $this->actingAs($outsider, 'sanctum')
            ->postJson('/api/upload/attachment', [
                'file' => $file,
                'project_id' => $otherProject->id,
            ]);

        $response->assertStatus(403);
    }

    public function test_upload_attachment_forbidden_for_cross_org_user_on_task(): void
    {
        $otherOrg = Organization::factory()->create();
        $otherDept = Department::factory()->create(['organization_id' => $otherOrg->id]);
        $otherProject = Project::factory()->create([
            'organization_id' => $otherOrg->id,
            'department_id' => $otherDept->id,
            'created_by' => $this->superAdmin->id,
        ]);
        $otherTask = Task::factory()->create([
            'project_id' => $otherProject->id,
        ]);

        $crossOrg = Organization::factory()->create();
        $crossOrgDept = Department::factory()->create(['organization_id' => $crossOrg->id]);
        $outsider = User::factory()->create([
            'organization_id' => $crossOrg->id,
            'department_id' => $crossOrgDept->id,
            'is_active' => true,
        ]);
        $this->assignCanonicalRole($outsider, 'member');

        $file = UploadedFile::fake()->createWithContent('task.png', $this->validPngBytes());

        $response = $this->actingAs($outsider, 'sanctum')
            ->postJson('/api/upload/attachment', [
                'file' => $file,
                'task_id' => $otherTask->id,
            ]);

        $response->assertStatus(403);
    }

    public function test_upload_attachment_rejects_svg(): void
    {
        $file = UploadedFile::fake()->createWithContent('file.svg', '<svg></svg>');

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/upload/attachment', [
                'file' => $file,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    public function test_upload_attachment_rejects_magic_bytes_mismatch(): void
    {
        $file = UploadedFile::fake()->createWithContent('file.png', $this->fakePngWithTextContent());

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/upload/attachment', [
                'file' => $file,
            ]);

        $response->assertStatus(422);
        $response->assertJsonFragment([
            'message' => 'نوع الملف المكتشف لا يطابق الامتداد. تم رفض الملف لأسباب أمنية.',
        ]);
    }

    public function test_upload_attachment_rejects_oversize(): void
    {
        // 10 MB + 1 KB PNG (limit is 10 MB) — ponytail: minimal oversize
        $file = $this->pngOfSize(10 * 1024 + 1);

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/upload/attachment', [
                'file' => $file,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    public function test_upload_attachment_no_project_id_uses_default_folder(): void
    {
        $file = UploadedFile::fake()->createWithContent('doc.png', $this->validPngBytes());

        $logsBefore = ActivityLog::count();

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/upload/attachment', [
                'file' => $file,
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'path',
                'original_name',
                'size',
                'mime_type',
            ]);

        $path = $response->json('path');
        $this->assertStringStartsWith('attachments/', $path);

        $logsAfter = ActivityLog::count();
        $this->assertEquals($logsBefore, $logsAfter, 'No new activity log row should be created when no project_id/task_id is set.');
    }

    public function test_upload_attachment_validation_requires_existing_project_id(): void
    {
        $file = UploadedFile::fake()->createWithContent('doc.png', $this->validPngBytes());

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/upload/attachment', [
                'file' => $file,
                'project_id' => 99999,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['project_id']);
    }
}
