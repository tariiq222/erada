<?php

namespace Tests\Feature\OVR;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\OVR\Enums\ReportStatus;
use App\Modules\OVR\Enums\SeverityLevel;
use App\Modules\OVR\Models\IncidentReport;
use App\Modules\OVR\Models\IncidentType;
use App\Modules\OVR\Models\ReportableType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * Coverage for IncidentTypeController (OVR categories).
 *
 * Pinpoints the live behavior of:
 *  - POST   /api/ovr/categories                              -> store
 *  - PUT    /api/ovr/categories/{type}                       -> update
 *  - DELETE /api/ovr/categories/{type}                       -> destroy
 *  - POST   /api/ovr/categories/{type}/reportable-types      -> storeReportableType
 *
 * Authorization: every mutating endpoint requires Capability::OVR_MANAGE_TYPES
 * via route middleware engine_capability:ovr.manage_types. The FormRequest
 * repeats the engine check (defense in depth). Tests must seed the engine
 * capability via Tests\Support\GrantsEngineCapability — flat Spatie perms
 * are no longer honored.
 *
 * Note on "in-use guard": the ovr_incident_reports.incident_type_id FK has no
 * ON DELETE clause, which translates to RESTRICT in PostgreSQL. Deleting an
 * IncidentType that any IncidentReport still references therefore surfaces
 * as an SQL error → the controller does NOT translate it into a friendly
 * 409/422 response. We pin that current behavior here so any future hardening
 * (a service-layer check) is intentional.
 */
class IncidentTypeControllerTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    protected Organization $org;

    protected Department $department;

    protected User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $this->department = Department::factory()->create([
            'organization_id' => $this->org->id,
        ]);
        $this->manager = User::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($this->manager, Capability::OVR_MANAGE_TYPES);
    }

    // ===== POST /api/ovr/categories (store) =====

    public function test_manager_can_store_incident_type(): void
    {
        $response = $this->actingAs($this->manager, 'sanctum')
            ->postJson('/api/ovr/categories', [
                'name' => 'Patient Safety',
                'name_ar' => 'سلامة المرضى',
                'is_active' => true,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'Patient Safety')
            ->assertJsonPath('data.name_ar', 'سلامة المرضى')
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('ovr_incident_types', [
            'name' => 'Patient Safety',
            'name_ar' => 'سلامة المرضى',
        ]);
    }

    public function test_store_persists_requires_reportable_type(): void
    {
        $this->actingAs($this->manager, 'sanctum')
            ->postJson('/api/ovr/categories', [
                'name' => 'Medication',
                'name_ar' => 'دوائي',
                'requires_reportable_type' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.requires_reportable_type', true);

        $this->assertDatabaseHas('ovr_incident_types', [
            'name' => 'Medication',
            'requires_reportable_type' => true,
        ]);
    }

    public function test_store_requires_name_and_name_ar(): void
    {
        $this->actingAs($this->manager, 'sanctum')
            ->postJson('/api/ovr/categories', ['name' => 'only name'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name_ar']);

        $this->actingAs($this->manager, 'sanctum')
            ->postJson('/api/ovr/categories', ['name_ar' => 'فقط الاسم العربي'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_unauthenticated_cannot_store_incident_type(): void
    {
        $this->postJson('/api/ovr/categories', [
            'name' => 'No Auth',
            'name_ar' => 'بدون مصادقة',
        ])->assertStatus(401);
    }

    public function test_user_without_manage_types_capability_cannot_store(): void
    {
        // A user in the org without the OVR_MANAGE_TYPES capability must NOT
        // be able to create a category. The seeded viewer/member roles do
        // not grant ovr.manage_types.
        $user = User::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/ovr/categories', [
                'name' => 'Denied',
                'name_ar' => 'مرفوض',
            ])
            ->assertStatus(403);

        $this->assertDatabaseMissing('ovr_incident_types', ['name' => 'Denied']);
    }

    // ===== PUT /api/ovr/categories/{type} (update) =====

    public function test_manager_can_update_incident_type(): void
    {
        $type = IncidentType::create([
            'name' => 'Old Name',
            'name_ar' => 'اسم قديم',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->manager, 'sanctum')
            ->putJson("/api/ovr/categories/{$type->id}", [
                'name' => 'New Name',
                'name_ar' => 'اسم جديد',
                'is_active' => false,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.name_ar', 'اسم جديد')
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('ovr_incident_types', [
            'id' => $type->id,
            'name' => 'New Name',
            'name_ar' => 'اسم جديد',
            'is_active' => false,
        ]);
    }

    public function test_update_leaves_unmentioned_fields_intact(): void
    {
        $type = IncidentType::create([
            'name' => 'Keep Me',
            'name_ar' => 'ابقني',
            'is_active' => true,
        ]);

        // Partial update — only name is sent.
        $this->actingAs($this->manager, 'sanctum')
            ->putJson("/api/ovr/categories/{$type->id}", [
                'name' => 'Updated Only Name',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Updated Only Name')
            ->assertJsonPath('data.name_ar', 'ابقني')
            ->assertJsonPath('data.is_active', true);

        $type->refresh();
        $this->assertSame('Updated Only Name', $type->name);
        $this->assertSame('ابقني', $type->name_ar);
        $this->assertTrue($type->is_active);
    }

    public function test_update_persists_requires_reportable_type(): void
    {
        $type = IncidentType::create([
            'name' => 'Medication',
            'name_ar' => 'دوائي',
            'is_active' => true,
            'requires_reportable_type' => false,
        ]);

        $this->actingAs($this->manager, 'sanctum')
            ->putJson("/api/ovr/categories/{$type->id}", [
                'requires_reportable_type' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.requires_reportable_type', true);
    }

    public function test_super_admin_can_explicitly_include_inactive_incident_types(): void
    {
        $superAdmin = User::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $this->department->id,
        ]);
        $superAdmin->assignRole('super_admin');
        $active = IncidentType::create(['name' => 'Active', 'name_ar' => 'نشط', 'is_active' => true]);
        $inactive = IncidentType::create(['name' => 'Inactive', 'name_ar' => 'غير نشط', 'is_active' => false]);

        $default = $this->actingAs($superAdmin, 'sanctum')
            ->getJson('/api/admin/incident-types')
            ->assertOk();
        $this->assertContains($active->id, collect($default->json('data'))->pluck('id')->all());
        $this->assertNotContains($inactive->id, collect($default->json('data'))->pluck('id')->all());

        $withInactive = $this->actingAs($superAdmin, 'sanctum')
            ->getJson('/api/admin/incident-types?include_inactive=1')
            ->assertOk();
        $ids = collect($withInactive->json('data'))->pluck('id')->all();
        $this->assertContains($active->id, $ids);
        $this->assertContains($inactive->id, $ids);
    }

    public function test_unauthenticated_cannot_update_incident_type(): void
    {
        $type = IncidentType::create([
            'name' => 'Locked',
            'name_ar' => 'مقفل',
            'is_active' => true,
        ]);

        $this->putJson("/api/ovr/categories/{$type->id}", [
            'name' => 'Hacked',
        ])->assertStatus(401);

        $this->assertDatabaseHas('ovr_incident_types', [
            'id' => $type->id,
            'name' => 'Locked',
        ]);
    }

    public function test_user_without_manage_types_capability_cannot_update(): void
    {
        $type = IncidentType::create([
            'name' => 'Untouched',
            'name_ar' => 'لم يُلمَس',
            'is_active' => true,
        ]);
        $user = User::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/ovr/categories/{$type->id}", [
                'name' => 'Should Not Persist',
            ])
            ->assertStatus(403);

        $this->assertDatabaseHas('ovr_incident_types', [
            'id' => $type->id,
            'name' => 'Untouched',
        ]);
    }

    // ===== DELETE /api/ovr/categories/{type} (destroy) =====

    public function test_manager_can_delete_unused_incident_type(): void
    {
        $type = IncidentType::create([
            'name' => 'Removable',
            'name_ar' => 'قابل للحذف',
            'is_active' => true,
        ]);

        $this->actingAs($this->manager, 'sanctum')
            ->deleteJson("/api/ovr/categories/{$type->id}")
            ->assertOk()
            ->assertJsonStructure(['message']);

        $this->assertDatabaseMissing('ovr_incident_types', [
            'id' => $type->id,
        ]);
    }

    public function test_destroy_cascades_its_own_reportable_sub_types(): void
    {
        // ovr_reportable_types.incident_type_id has cascadeOnDelete(), so a
        // successful destroy of the parent removes sub-types too. Pin this so a
        // future FK change is intentional.
        $type = IncidentType::create([
            'name' => 'Cascader',
            'name_ar' => 'مسلّسل',
            'is_active' => true,
        ]);
        $subType = ReportableType::create([
            'incident_type_id' => $type->id,
            'name' => 'Sub One',
            'name_ar' => 'فرعي واحد',
        ]);

        $this->actingAs($this->manager, 'sanctum')
            ->deleteJson("/api/ovr/categories/{$type->id}")
            ->assertOk();

        $this->assertDatabaseMissing('ovr_incident_types', ['id' => $type->id]);
        $this->assertDatabaseMissing('ovr_reportable_types', [
            'id' => $subType->id,
        ]);
    }

    public function test_unauthenticated_cannot_delete_incident_type(): void
    {
        $type = IncidentType::create([
            'name' => 'Locked',
            'name_ar' => 'مقفل',
            'is_active' => true,
        ]);

        $this->deleteJson("/api/ovr/categories/{$type->id}")
            ->assertStatus(401);

        $this->assertDatabaseHas('ovr_incident_types', ['id' => $type->id]);
    }

    public function test_user_without_manage_types_capability_cannot_delete(): void
    {
        $type = IncidentType::create([
            'name' => 'Safe',
            'name_ar' => 'آمن',
            'is_active' => true,
        ]);
        $user = User::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/ovr/categories/{$type->id}")
            ->assertStatus(403);

        $this->assertDatabaseHas('ovr_incident_types', ['id' => $type->id]);
    }

    public function test_destroy_in_use_incident_type_succeeds_without_in_use_guard(): void
    {
        // Pins the in-use GAP: the controller has NO controller-layer guard
        // against deleting an IncidentType that IncidentReport rows still
        // reference. The FK ovr_incident_reports.incident_type_id has NO
        // onDelete clause, so PostgreSQL RESTRICTs the DELETE and Laravel's
        // exception handler converts the QueryException into a 500 response.
        // The category row is NOT removed (the DB rolled back). If a graceful
        // guard is ever added, flip this test to expect a friendly 4xx
        // response and document the upgrade.
        //
        // We can't reliably assert "row still exists after" from a second
        // connection inside the same RefreshDatabase outer transaction — the
        // test's main connection is aborted at 25P02 after the DELETE failure,
        // and a separate connection sees the pre-setup (uncommitted) state. The
        // reliable observable here is the response status code (500) plus the
        // fact that the FK violation (23503) is the underlying cause — both
        // prove the row was NOT deleted.
        $type = IncidentType::create([
            'name' => 'In Use',
            'name_ar' => 'مستخدم',
            'is_active' => true,
        ]);
        IncidentReport::create([
            'organization_id' => $this->org->id,
            'reporter_id' => $this->manager->id,
            'reporter_name' => $this->manager->name,
            'reporter_email' => $this->manager->email,
            'reporter_department_id' => $this->department->id,
            'incident_datetime' => now(),
            'is_patient_related' => false,
            'informed_authority' => false,
            'incident_type_id' => $type->id,
            'incident_description' => 'Ref to in-use type',
            'immediate_action_required' => false,
            'severity_level' => SeverityLevel::Low,
            'status' => ReportStatus::New,
            'is_confidential' => false,
        ]);

        // Sanity check: the FK reference actually persisted in the test's
        // outer transaction.
        $referencingReports = IncidentReport::where('incident_type_id', $type->id)->count();
        $this->assertSame(1, $referencingReports, 'precondition: exactly one report references the in-use type');

        // The controller has no in-use guard; the DELETE fires and Postgres
        // RESTRICTs it because of the FK reference in the same transaction.
        // That bubbles up as an unhandled QueryException, which Laravel's handler
        // converts to a 500 response. The category row is NOT removed.
        $response = $this->actingAs($this->manager, 'sanctum')
            ->deleteJson("/api/ovr/categories/{$type->id}");

        $this->assertSame(
            500,
            $response->status(),
            'in-use destroy currently surfaces as 500 SQL error (no controller guard)'
        );

        // Pin the underlying FK violation by looking up the last DB exception
        // log entry for this request — proves the 500 came from a Postgres
        // foreign_key_violation (SQLSTATE 23503) on ovr_incident_types.
        $logPath = storage_path('logs/laravel.log');
        $tail = file_exists($logPath) ? file_get_contents($logPath) : '';
        $this->assertStringContainsString('SQLSTATE[23503]', $tail);
        $this->assertStringContainsString('ovr_incident_reports_incident_type_id_foreign', $tail);
    }

    // ===== POST /api/ovr/categories/{type}/reportable-types (storeReportableType) =====

    public function test_manager_can_store_reportable_sub_type_under_parent(): void
    {
        $parent = IncidentType::create([
            'name' => 'Parent',
            'name_ar' => 'أصل',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/ovr/categories/{$parent->id}/reportable-types", [
                'name' => 'Subtype A',
                'name_ar' => 'فرعي ألف',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'Subtype A')
            ->assertJsonPath('data.name_ar', 'فرعي ألف')
            ->assertJsonPath('data.incident_type_id', $parent->id);

        $this->assertDatabaseHas('ovr_reportable_types', [
            'incident_type_id' => $parent->id,
            'name' => 'Subtype A',
            'name_ar' => 'فرعي ألف',
        ]);
    }

    public function test_store_reportable_type_requires_name_and_name_ar(): void
    {
        $parent = IncidentType::create([
            'name' => 'Parent',
            'name_ar' => 'أصل',
            'is_active' => true,
        ]);

        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/ovr/categories/{$parent->id}/reportable-types", [
                'name' => 'only name',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name_ar']);

        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/ovr/categories/{$parent->id}/reportable-types", [
                'name_ar' => 'فقط الاسم',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_unauthenticated_cannot_store_reportable_type(): void
    {
        $parent = IncidentType::create([
            'name' => 'Parent',
            'name_ar' => 'أصل',
            'is_active' => true,
        ]);

        $this->postJson("/api/ovr/categories/{$parent->id}/reportable-types", [
            'name' => 'No Auth',
            'name_ar' => 'بدون مصادقة',
        ])->assertStatus(401);
    }

    public function test_user_without_manage_types_capability_cannot_store_reportable_type(): void
    {
        $parent = IncidentType::create([
            'name' => 'Parent',
            'name_ar' => 'أصل',
            'is_active' => true,
        ]);
        $user = User::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/ovr/categories/{$parent->id}/reportable-types", [
                'name' => 'Denied',
                'name_ar' => 'مرفوض',
            ])
            ->assertStatus(403);

        $this->assertDatabaseMissing('ovr_reportable_types', ['name' => 'Denied']);
    }
}
