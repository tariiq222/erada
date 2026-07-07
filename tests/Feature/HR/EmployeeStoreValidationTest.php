<?php

namespace Tests\Feature\HR;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

class EmployeeStoreValidationTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    private Organization $organization;

    private Organization $otherOrganization;

    private Department $department;

    private User $hrManager;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeaders(['X-Skip-Csrf' => '1']);

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

        $this->viewer = User::factory()->create([
            'organization_id' => $this->organization->id,
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        // Engine route middleware is satisfied by HR_VIEW (no Spatie grant needed);
        // the engine grant covers the route gate. HR_MANAGE is intentionally NOT
        // granted so the FormRequest denies writes via AccessDecision.
        $this->grantEngineCapability($this->viewer, Capability::HR_VIEW);
    }

    private function saudiPayload(?User $employee = null): array
    {
        $employee ??= User::factory()->create([
            'organization_id' => $this->organization->id,
            'department_id' => $this->department->id,
        ]);

        return [
            'user_id' => $employee->id,
            'employee_no' => 'EMP-'.uniqid(),
            'hire_date' => '2026-01-15',
            'employment_type' => 'full_time',
            'employment_status' => 'active',
            'staff_category' => 'administrative',
            'personal_info' => [
                'full_name_english' => 'Ahmad Almutairi',
                'full_name_arabic' => 'أحمد المطيري',
                'nationality' => 'SA',
                'gender' => 'male',
                'birth_date' => '1990-01-01',
                'address' => 'Riyadh, KSA',
                'emergency_contact' => 'Sara',
                'emergency_phone' => '+966500000000',
                'emergency_contact_relation' => 'wife',
                'national_id' => '1234567890',
                'national_id_issue_date' => '2018-01-01',
                'national_id_issue_place' => 'Riyadh',
                'national_id_expiry_date' => '2028-01-01',
            ],
        ];
    }

    private function nonSaudiPayload(?User $employee = null): array
    {
        $employee ??= User::factory()->create([
            'organization_id' => $this->organization->id,
            'department_id' => $this->department->id,
        ]);

        return [
            'user_id' => $employee->id,
            'employee_no' => 'EMP-'.uniqid(),
            'hire_date' => '2026-01-15',
            'employment_type' => 'contract',
            'employment_status' => 'active',
            'staff_category' => 'administrative',
            'personal_info' => [
                'full_name_english' => 'John Doe',
                'full_name_arabic' => 'جون دو',
                'nationality' => 'US',
                'gender' => 'male',
                'birth_date' => '1985-05-05',
                'address' => 'Jeddah, KSA',
                'emergency_contact' => 'Jane',
                'emergency_phone' => '+966511111111',
                'emergency_contact_relation' => 'sister',
                'iqama_number' => '2234567890',
                'iqama_issue_date' => '2022-06-01',
                'iqama_issue_place' => 'Jeddah',
                'iqama_expiry_date' => '2027-06-01',
                'profession' => 'Engineer',
                'religion' => 'Christian',
                'sponsor' => 'Acme Co.',
            ],
        ];
    }

    private function medicalPayload(?User $employee = null): array
    {
        $payload = $this->saudiPayload($employee);
        $payload['staff_category'] = 'medical';

        return $payload;
    }

    public function test_store_creates_profile_with_personal_info_for_saudi(): void
    {
        $employee = User::factory()->create([
            'organization_id' => $this->organization->id,
            'department_id' => $this->department->id,
        ]);

        $payload = $this->saudiPayload($employee);

        $response = $this->actingAs($this->hrManager, 'sanctum')
            ->postJson('/api/hr/employees', $payload);

        $response->assertCreated()
            ->assertJsonPath('employee_no', $payload['employee_no'])
            ->assertJsonPath('personal_info.nationality', 'SA')
            ->assertJsonPath('personal_info.national_id', '1234567890')
            ->assertJsonPath('is_medical_staff', false);

        $this->assertDatabaseHas('employee_profiles', [
            'user_id' => $employee->id,
            'employee_no' => $payload['employee_no'],
            'staff_category' => 'administrative',
        ]);
        $this->assertDatabaseHas('employee_personal_info', [
            'employee_profile_id' => $response->json('id'),
            'nationality' => 'SA',
            'national_id' => '1234567890',
        ]);
    }

    public function test_saudi_national_missing_required_fields_rejected(): void
    {
        $employee = User::factory()->create([
            'organization_id' => $this->organization->id,
            'department_id' => $this->department->id,
        ]);

        $payload = $this->saudiPayload($employee);
        unset(
            $payload['personal_info']['national_id'],
            $payload['personal_info']['national_id_issue_date'],
            $payload['personal_info']['national_id_expiry_date']
        );

        $response = $this->actingAs($this->hrManager, 'sanctum')
            ->postJson('/api/hr/employees', $payload);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors([
                'personal_info.national_id',
                'personal_info.national_id_issue_date',
                'personal_info.national_id_expiry_date',
            ]);
    }

    public function test_non_saudi_must_supply_iqama_and_sponsor(): void
    {
        $employee = User::factory()->create([
            'organization_id' => $this->organization->id,
            'department_id' => $this->department->id,
        ]);

        $payload = $this->nonSaudiPayload($employee);
        unset(
            $payload['personal_info']['iqama_number'],
            $payload['personal_info']['sponsor'],
            $payload['personal_info']['profession']
        );

        $response = $this->actingAs($this->hrManager, 'sanctum')
            ->postJson('/api/hr/employees', $payload);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors([
                'personal_info.iqama_number',
                'personal_info.sponsor',
                'personal_info.profession',
            ]);
    }

    public function test_medical_staff_missing_required_certs_rejected(): void
    {
        $employee = User::factory()->create([
            'organization_id' => $this->organization->id,
            'department_id' => $this->department->id,
        ]);

        $payload = $this->medicalPayload($employee);
        $payload['certificates'] = [
            ['type' => 'graduation', 'title' => 'MBBS'],
        ];

        $response = $this->actingAs($this->hrManager, 'sanctum')
            ->postJson('/api/hr/employees', $payload);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['certificates']);
    }

    public function test_medical_staff_with_full_certs_accepted(): void
    {
        $employee = User::factory()->create([
            'organization_id' => $this->organization->id,
            'department_id' => $this->department->id,
        ]);

        $payload = $this->medicalPayload($employee);
        $payload['certificates'] = [
            ['type' => 'graduation', 'title' => 'MBBS'],
            ['type' => 'bls', 'title' => 'BLS Cert'],
            ['type' => 'medical_malpractice_insurance', 'title' => 'Malpractice'],
            ['type' => 'health_specialties', 'title' => 'Specialty'],
        ];

        $response = $this->actingAs($this->hrManager, 'sanctum')
            ->postJson('/api/hr/employees', $payload);

        $response->assertCreated()
            ->assertJsonPath('staff_category', 'medical')
            ->assertJsonPath('is_medical_staff', true);

        $this->assertDatabaseCount('employee_certificates', 4);
    }

    public function test_self_employed_requires_social_insurance_number(): void
    {
        $employee = User::factory()->create([
            'organization_id' => $this->organization->id,
            'department_id' => $this->department->id,
        ]);

        $payload = $this->saudiPayload($employee);
        $payload['contract_type'] = 'self_employed';

        $response = $this->actingAs($this->hrManager, 'sanctum')
            ->postJson('/api/hr/employees', $payload);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['social_insurance_number']);
    }

    public function test_user_without_manage_hr_cannot_post_or_delete(): void
    {
        $employee = User::factory()->create([
            'organization_id' => $this->organization->id,
            'department_id' => $this->department->id,
        ]);

        $this->actingAs($this->viewer, 'sanctum')
            ->postJson('/api/hr/employees', $this->saudiPayload($employee))
            ->assertForbidden();

        $this->actingAs($this->viewer, 'sanctum')
            ->deleteJson("/api/hr/employees/{$employee->id}")
            ->assertForbidden();
    }

    public function test_cross_org_access_returns_403(): void
    {
        $foreignEmployee = User::factory()->create([
            'organization_id' => $this->otherOrganization->id,
            'department_id' => null,
        ]);

        $this->actingAs($this->hrManager, 'sanctum')
            ->deleteJson("/api/hr/employees/{$foreignEmployee->id}")
            ->assertForbidden();

        $this->actingAs($this->hrManager, 'sanctum')
            ->postJson('/api/hr/employees', [
                'user_id' => $foreignEmployee->id,
                'employee_no' => 'EMP-X',
                'hire_date' => '2026-01-01',
                'employment_type' => 'full_time',
                'employment_status' => 'active',
                'personal_info' => [
                    'full_name_english' => 'X Y',
                    'full_name_arabic' => 'س',
                    'nationality' => 'SA',
                    'address' => 'X',
                    'emergency_contact' => 'X',
                    'emergency_phone' => '0',
                    'emergency_contact_relation' => 'X',
                ],
            ])
            ->assertForbidden();
    }

    public function test_destroy_soft_deletes_profile_and_cascades(): void
    {
        $employee = User::factory()->create([
            'organization_id' => $this->organization->id,
            'department_id' => $this->department->id,
        ]);

        $payload = $this->saudiPayload($employee);
        $created = $this->actingAs($this->hrManager, 'sanctum')
            ->postJson('/api/hr/employees', $payload)
            ->assertCreated()
            ->json();

        $profileId = $created['id'];

        $this->actingAs($this->hrManager, 'sanctum')
            ->deleteJson("/api/hr/employees/{$employee->id}")
            ->assertNoContent();

        $this->assertSoftDeleted('employee_profiles', ['id' => $profileId]);
        $this->assertSoftDeleted('employee_personal_info', ['employee_profile_id' => $profileId]);
    }

    public function test_employment_status_on_leave_accepted_by_db_check(): void
    {
        $profileId = DB::table('employee_profiles')->insertGetId([
            'user_id' => User::factory()->create()->id,
            'employee_no' => 'EMP-ONLEAVE',
            'hire_date' => '2025-01-01',
            'employment_type' => 'full_time',
            'employment_status' => 'on_leave',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertDatabaseHas('employee_profiles', [
            'id' => $profileId,
            'employment_status' => 'on_leave',
        ]);
    }

    public function test_store_auto_assigns_manager_id_from_department_manager(): void
    {
        $departmentManager = User::factory()->create([
            'organization_id' => $this->organization->id,
            'department_id' => $this->department->id,
        ]);
        $managedDepartment = Department::factory()->create([
            'organization_id' => $this->organization->id,
            'level' => 4,
            'manager_id' => $departmentManager->id,
        ]);
        $employee = User::factory()->create([
            'organization_id' => $this->organization->id,
            'department_id' => $managedDepartment->id,
        ]);

        $payload = $this->saudiPayload($employee);
        $payload['dept_id'] = $managedDepartment->id;
        $payload['manager_id'] = $this->hrManager->id;

        $response = $this->actingAs($this->hrManager, 'sanctum')
            ->postJson('/api/hr/employees', $payload);

        $response->assertCreated()
            ->assertJsonPath('manager.id', $departmentManager->id);

        $this->assertDatabaseHas('users', [
            'id' => $employee->id,
            'department_id' => $managedDepartment->id,
        ]);
    }

    public function test_store_sets_manager_id_to_null_when_department_has_no_manager(): void
    {
        $employee = User::factory()->create([
            'organization_id' => $this->organization->id,
            'department_id' => $this->department->id,
        ]);

        $payload = $this->saudiPayload($employee);
        $payload['manager_id'] = $this->hrManager->id;

        $response = $this->actingAs($this->hrManager, 'sanctum')
            ->postJson('/api/hr/employees', $payload);

        $response->assertCreated()
            ->assertJsonPath('manager', null);
    }
}
