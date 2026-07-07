<?php

namespace Tests\Feature\HR\Isolation;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\EmployeeCertificate;
use App\Modules\HR\Models\EmployeeProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * EmployeeCertificateIsolationTest - Phase 2: org-A user cannot store/delete
 * certificates for an org-B employee, nor download an org-B certificate.
 *
 * Coverage:
 *   - POST /api/hr/employees/{employee}/certificates → 403 cross-org.
 *   - DELETE /api/hr/certificates/{certificate} → 403 cross-org.
 *   - GET /api/hr/certificates/{certificate}/download (signed) → 403 cross-org
 *     (the FormRequest guards even when the URL signature is valid).
 */
class EmployeeCertificateIsolationTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_org_a_user_cannot_upload_certificate_for_org_b_employee(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);
        $deptB = Department::factory()->create(['organization_id' => $orgB->id]);

        $actor = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
        ]);
        $this->grantEngineCapability($actor, [Capability::HR_VIEW, Capability::HR_MANAGE]);

        $orgBEmployee = User::factory()->create([
            'organization_id' => $orgB->id,
            'department_id' => $deptB->id,
        ]);

        $upload = UploadedFile::fake()->create('cert.pdf', 10, 'application/pdf');

        $response = $this->actingAs($actor, 'sanctum')
            ->postJson("/api/hr/employees/{$orgBEmployee->id}/certificates", [
                'type' => 'graduation',
                'file' => $upload,
            ]);

        $response->assertStatus(403);
    }

    public function test_org_a_user_can_upload_certificate_for_org_a_employee(): void
    {
        $orgA = Organization::factory()->create();
        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);

        $actor = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
        ]);
        $this->grantEngineCapability($actor, [Capability::HR_VIEW, Capability::HR_MANAGE]);

        $orgAEmployee = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
        ]);
        EmployeeProfile::create([
            'user_id' => $orgAEmployee->id,
            'employee_no' => 'EMP-CERT-A',
            'hire_date' => '2024-01-01',
            'employment_type' => 'full_time',
            'employment_status' => 'active',
        ]);

        $upload = UploadedFile::fake()->create('cert.pdf', 10, 'application/pdf');

        $response = $this->actingAs($actor, 'sanctum')
            ->postJson("/api/hr/employees/{$orgAEmployee->id}/certificates", [
                'type' => 'graduation',
                'file' => $upload,
            ]);

        $response->assertStatus(201);
    }

    public function test_org_a_user_cannot_delete_org_b_certificate(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);
        $deptB = Department::factory()->create(['organization_id' => $orgB->id]);

        $actor = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
        ]);
        $this->grantEngineCapability($actor, [Capability::HR_VIEW, Capability::HR_MANAGE]);

        $orgBEmployee = User::factory()->create([
            'organization_id' => $orgB->id,
            'department_id' => $deptB->id,
        ]);
        $orgBProfile = EmployeeProfile::factory()->create(['user_id' => $orgBEmployee->id]);
        $cert = EmployeeCertificate::factory()->create([
            'employee_profile_id' => $orgBProfile->id,
        ]);

        $response = $this->actingAs($actor, 'sanctum')
            ->deleteJson("/api/hr/certificates/{$cert->id}");

        $response->assertStatus(403);
    }

    public function test_org_a_user_cannot_download_org_b_certificate(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);
        $deptB = Department::factory()->create(['organization_id' => $orgB->id]);

        $actor = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
        ]);
        $this->grantEngineCapability($actor, Capability::HR_VIEW);

        $orgBEmployee = User::factory()->create([
            'organization_id' => $orgB->id,
            'department_id' => $deptB->id,
        ]);
        $orgBProfile = EmployeeProfile::factory()->create(['user_id' => $orgBEmployee->id]);
        $cert = EmployeeCertificate::factory()->create([
            'employee_profile_id' => $orgBProfile->id,
        ]);

        // Build a valid signed URL using the certificate's getDownloadUrl().
        $signedUrl = $cert->getDownloadUrl();
        $relativePath = parse_url($signedUrl, PHP_URL_PATH);
        parse_str(parse_url($signedUrl, PHP_URL_QUERY) ?? '', $query);

        $response = $this->actingAs($actor, 'sanctum')
            ->get($relativePath.'?'.http_build_query($query));

        $response->assertStatus(403);
    }

    public function test_null_org_user_cannot_upload_certificate(): void
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $employee = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
        ]);

        $actor = User::factory()->create([
            'organization_id' => null,
            'department_id' => $dept->id,
        ]);
        $this->grantEngineCapability($actor, [Capability::HR_VIEW, Capability::HR_MANAGE]);

        $upload = UploadedFile::fake()->create('cert.pdf', 10, 'application/pdf');

        $response = $this->actingAs($actor, 'sanctum')
            ->postJson("/api/hr/employees/{$employee->id}/certificates", [
                'type' => 'graduation',
                'file' => $upload,
            ]);

        $response->assertStatus(403);
    }
}
