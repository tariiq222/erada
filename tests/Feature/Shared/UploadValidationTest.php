<?php

namespace Tests\Feature\Shared;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use App\Modules\Shared\Services\FileUploadValidator;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class UploadValidationTest extends TestCase
{
    use DatabaseTransactions;

    protected Organization $org;

    protected Department $dept;

    protected User $superAdmin;

    protected Project $project;

    private const PDF_MAGIC = '%PDF-1.4';

    private const RIFF_WAV_HEADER = "RIFF\x10\x00\x00\x00WAVEfmt ";

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeaders(['X-Skip-Csrf' => '1']);

        Storage::fake('public');
        Storage::fake('local');

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->org = Organization::factory()->create();
        $this->dept = Department::factory()->create(['organization_id' => $this->org->id]);
        $this->superAdmin = User::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
            'is_active' => true,
        ]);
        $this->grantCanonicalSuperAdmin($this->superAdmin);

        $this->project = Project::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
            'created_by' => $this->superAdmin->id,
        ]);
    }

    private function realPdfBytes(): string
    {
        return self::PDF_MAGIC."\n".str_repeat('%', 2048);
    }

    /**
     * Construct a real RIFF/WAVE payload (audio/wav) and save it as `evil.webp`.
     * finfo on a real WAV file returns `audio/wav`, which must not match the .webp allowlist.
     */
    private function webpDisguisedAsWav(): UploadedFile
    {
        $payload = self::RIFF_WAV_HEADER.str_repeat("\x00", 4096);

        return UploadedFile::fake()->createWithContent('evil.webp', $payload);
    }

    public function test_validator_rejects_webp_extension_with_real_wav_magic_bytes(): void
    {
        $file = $this->webpDisguisedAsWav();

        $validator = app(FileUploadValidator::class);

        $this->expectException(ValidationException::class);
        $validator->validate(
            $file,
            FileUploadValidator::COMMENT_ATTACHMENT_EXTENSIONS,
            FileUploadValidator::COMMENT_ATTACHMENT_MAX_BYTES
        );
    }

    public function test_upload_attachment_rejects_webp_disguised_as_wav_via_api(): void
    {
        $file = $this->webpDisguisedAsWav();

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/upload/attachment', [
                'file' => $file,
                'project_id' => $this->project->id,
            ]);

        $response->assertStatus(422);
        $this->assertNotEmpty($response->json('errors') ?: $response->json('message'));
    }

    public function test_upload_attachment_accepts_real_pdf(): void
    {
        $file = UploadedFile::fake()->createWithContent('document.pdf', $this->realPdfBytes());

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/upload/attachment', [
                'file' => $file,
                'project_id' => $this->project->id,
            ]);

        // Attachments are stored on the private disk and downloaded via the
        // signed /api/attachments/{id}/download route, so no public 'url' is
        // returned here.
        $response->assertStatus(200)
            ->assertJsonStructure(['message', 'path', 'original_name', 'size', 'mime_type']);

        $path = $response->json('path');
        Storage::disk('local')->assertExists($path);
    }

    public function test_upload_attachment_rejects_file_one_byte_over_10mb(): void
    {
        // Build a real PDF payload padded to exactly 10 MB + 1 byte total.
        $payload = str_pad(self::PDF_MAGIC."\n", (10 * 1024 * 1024) + 1, '%');
        $this->assertSame((10 * 1024 * 1024) + 1, strlen($payload));

        $file = UploadedFile::fake()->createWithContent('huge.pdf', $payload);

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/upload/attachment', [
                'file' => $file,
                'project_id' => $this->project->id,
            ]);

        $response->assertStatus(422);
        $errors = $response->json('errors') ?? [];
        $this->assertNotEmpty($errors);
    }
}
