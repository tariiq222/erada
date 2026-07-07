<?php

namespace Tests\Feature\HR;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\EmployeeCertificate;
use App\Modules\HR\Models\EmployeePersonalInfo;
use App\Modules\HR\Models\EmployeeProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

class EmployeeCertificateUploadTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    private Organization $organization;

    private Organization $otherOrganization;

    private Department $department;

    private User $hrManager;

    private User $employee;

    private User $otherOrgEmployee;

    private EmployeeProfile $profile;

    private EmployeeProfile $otherOrgProfile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeaders(['X-Skip-Csrf' => '1']);

        Storage::fake('local');

        $this->organization = Organization::factory()->create();
        $this->otherOrganization = Organization::factory()->create();

        $this->department = Department::factory()->create([
            'organization_id' => $this->organization->id,
            'level' => 4,
        ]);

        $this->hrManager = User::factory()->create([
            'organization_id' => $this->organization->id,
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        // HR route group is now gated by engine_capability:Capability::HR_VIEW
        // (replaces the legacy `permission:view_hr` Spatie middleware). The
        // engine capability HR_VIEW / HR_MANAGE also satisfies the migrated
        // controller helper and FormRequest authorize() gate (formerly the
        // Spatie `manage_hr` permission). Single combined role covers both
        // HR reads and writes.
        $this->grantEngineCapability(
            $this->hrManager,
            [Capability::HR_VIEW, Capability::HR_MANAGE]
        );

        $this->employee = User::factory()->create([
            'organization_id' => $this->organization->id,
            'department_id' => $this->department->id,
        ]);

        $this->profile = EmployeeProfile::create([
            'user_id' => $this->employee->id,
            'employee_no' => 'EMP-CERT',
            'employment_type' => 'full_time',
            'employment_status' => 'active',
        ]);

        EmployeePersonalInfo::create([
            'employee_profile_id' => $this->profile->id,
            'nationality' => 'SA',
            'full_name_english' => 'Ahmad',
            'full_name_arabic' => 'أحمد',
            'address' => 'Riyadh',
            'emergency_contact' => 'Sara',
            'emergency_phone' => '+966500000000',
            'emergency_contact_relation' => 'wife',
        ]);

        $this->otherOrgEmployee = User::factory()->create([
            'organization_id' => $this->otherOrganization->id,
        ]);

        $this->otherOrgProfile = EmployeeProfile::create([
            'user_id' => $this->otherOrgEmployee->id,
            'employee_no' => 'EMP-OTHER',
            'employment_type' => 'full_time',
            'employment_status' => 'active',
        ]);
    }

    public function test_pdf_upload_stores_on_private_disk(): void
    {
        $file = UploadedFile::fake()->create('degree.pdf', 100, 'application/pdf');

        $response = $this->actingAs($this->hrManager, 'sanctum')
            ->postJson("/api/hr/employees/{$this->employee->id}/certificates", [
                'type' => 'graduation',
                'title' => 'MBBS',
                'issued_at' => '2020-06-01',
                'expires_at' => '2030-06-01',
                'file' => $file,
            ]);

        $response->assertCreated()
            ->assertJsonPath('type', 'graduation')
            ->assertJsonPath('file_name', 'degree.pdf');

        $certificate = EmployeeCertificate::firstOrFail();
        Storage::disk('local')->assertExists($certificate->file_path);
        $this->assertStringStartsWith("hr/employees/{$this->employee->id}/graduation/", $certificate->file_path);
    }

    public function test_wrong_mime_rejected(): void
    {
        $file = UploadedFile::fake()->create('evil.exe', 50, 'application/octet-stream');

        $this->actingAs($this->hrManager, 'sanctum')
            ->postJson("/api/hr/employees/{$this->employee->id}/certificates", [
                'type' => 'graduation',
                'file' => $file,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file']);

        $this->assertSame(0, EmployeeCertificate::count());
    }

    public function test_oversized_file_rejected(): void
    {
        $file = UploadedFile::fake()->create('big.pdf', 6000, 'application/pdf');

        $this->actingAs($this->hrManager, 'sanctum')
            ->postJson("/api/hr/employees/{$this->employee->id}/certificates", [
                'type' => 'graduation',
                'file' => $file,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file']);

        $this->assertSame(0, EmployeeCertificate::count());
    }

    public function test_signed_url_allows_download_and_missing_signature_403(): void
    {
        $file = UploadedFile::fake()->create('cpr.pdf', 50, 'application/pdf');

        $certificate = EmployeeCertificate::create([
            'employee_profile_id' => $this->profile->id,
            'type' => 'bls',
            'title' => 'BLS',
            'file_path' => 'hr/employees/'.$this->employee->id.'/bls/test.pdf',
            'file_name' => 'cpr.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 50,
        ]);

        Storage::disk('local')->put(
            $certificate->file_path,
            "%PDF-1.4\nfake contents",
        );

        $signedUrl = $certificate->getDownloadUrl();

        // Missing signature → middleware rejects
        $this->actingAs($this->hrManager, 'sanctum')
            ->get("/api/hr/certificates/{$certificate->id}/download")
            ->assertForbidden();

        // Valid signed URL → 200
        $response = $this->actingAs($this->hrManager, 'sanctum')
            ->get($signedUrl);

        $response->assertOk();
        $this->assertStringContainsString('fake contents', $response->streamedContent());
    }

    public function test_cross_org_download_denied(): void
    {
        $foreignCert = EmployeeCertificate::create([
            'employee_profile_id' => $this->otherOrgProfile->id,
            'type' => 'bls',
            'file_path' => 'hr/employees/'.$this->otherOrgEmployee->id.'/bls/x.pdf',
            'file_name' => 'x.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1,
        ]);

        Storage::disk('local')->put($foreignCert->file_path, 'secret');

        $signedUrl = $foreignCert->getDownloadUrl();

        $this->actingAs($this->hrManager, 'sanctum')
            ->get($signedUrl)
            ->assertForbidden();
    }

    public function test_destroy_removes_file_and_soft_deletes_row(): void
    {
        $certificate = EmployeeCertificate::create([
            'employee_profile_id' => $this->profile->id,
            'type' => 'bls',
            'file_path' => 'hr/employees/'.$this->employee->id.'/bls/del.pdf',
            'file_name' => 'del.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1,
        ]);

        Storage::disk('local')->put($certificate->file_path, 'tmp');

        $this->actingAs($this->hrManager, 'sanctum')
            ->deleteJson("/api/hr/certificates/{$certificate->id}")
            ->assertNoContent();

        Storage::disk('local')->assertMissing($certificate->file_path);
        $this->assertSoftDeleted('employee_certificates', ['id' => $certificate->id]);
    }

    public function test_invalid_certificate_type_rejected(): void
    {
        $file = UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf');

        $this->actingAs($this->hrManager, 'sanctum')
            ->postJson("/api/hr/employees/{$this->employee->id}/certificates", [
                'type' => 'invalid_type',
                'file' => $file,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['type']);
    }

    /**
     * A3 (POST) — Cross-org HR manager must not be able to upload a
     * certificate for an employee in a foreign organization. The
     * StoreEmployeeCertificateRequest FormRequest enforces the same-org
     * gate via AccessDecision::can(HR_MANAGE) + same-org check; the request
     * is rejected before the file lands on disk.
     */
    public function test_cross_org_actor_cannot_upload_certificate_to_foreign_employee(): void
    {
        $file = UploadedFile::fake()->create('foreign.pdf', 50, 'application/pdf');

        $response = $this->actingAs($this->hrManager, 'sanctum')
            ->postJson("/api/hr/employees/{$this->otherOrgEmployee->id}/certificates", [
                'type' => 'graduation',
                'title' => 'Foreign Degree',
                'file' => $file,
            ]);

        // FormRequest authorize() returns false → Laravel emits 403.
        $response->assertForbidden();

        $this->assertSame(0, EmployeeCertificate::count());

        // Ensure no file landed on the foreign employee's storage prefix either.
        $files = Storage::disk('local')->allFiles("hr/employees/{$this->otherOrgEmployee->id}");
        $this->assertEmpty($files, 'no file should be written for a cross-org upload');
    }

    /**
     * A3 (DELETE) — Cross-org HR manager must not be able to delete a
     * certificate that belongs to a foreign organization. The
     * DeleteEmployeeCertificateRequest FormRequest resolves the certificate,
     * walks to its owner org, and denies when actor and owner orgs differ.
     */
    public function test_cross_org_actor_cannot_delete_foreign_certificate(): void
    {
        $foreignCertificate = EmployeeCertificate::create([
            'employee_profile_id' => $this->otherOrgProfile->id,
            'type' => 'bls',
            'file_path' => 'hr/employees/'.$this->otherOrgEmployee->id.'/bls/foreign-cert.pdf',
            'file_name' => 'foreign-cert.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1,
        ]);

        Storage::disk('local')->put($foreignCertificate->file_path, 'foreign payload');

        $response = $this->actingAs($this->hrManager, 'sanctum')
            ->deleteJson("/api/hr/certificates/{$foreignCertificate->id}");

        $response->assertForbidden();

        // The row must NOT be soft-deleted by the cross-org actor.
        $this->assertNotSoftDeleted('employee_certificates', ['id' => $foreignCertificate->id]);

        // And the file must still exist on disk.
        Storage::disk('local')->assertExists($foreignCertificate->file_path);
    }
}
