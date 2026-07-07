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
use App\Modules\OVR\Models\ReportComment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * Engine-based authz tests for the 8 controller sites being migrated in Wave 3 Task 7:
 *  - IncidentTypeController::index / list  -> Capability::OVR_VIEW
 *  - IncidentTypeController::store / update / destroy / storeReportableType
 *                                          -> Capability::OVR_MANAGE_TYPES
 *  - ReportCommentController::destroy       -> Capability::OVR_DELETE_ALL (target-aware)
 *  - OvrSettingsController::authorizeGovernance -> Capability::SETTINGS_MANAGE
 *
 * Routes verified against app/Modules/OVR/Routes/api.php:
 *  - GET    /api/ovr/categories                       -> index (incident-type categories)
 *  - GET    /api/ovr/categories/list                  -> list
 *  - POST   /api/ovr/categories                       -> store
 *  - GET    /api/ovr/settings/governing-department    -> getGoverningDepartment
 *  - DELETE /api/ovr/incidents/{report_number}/comments/{comment}
 *                                                    -> ReportCommentController::destroy
 *      (IncidentReport::getRouteKeyName() returns 'report_number')
 *
 * Route middleware: engine_capability:ovr.view / ovr.manage_types — delegates to
 * AccessDecision::can(). Tests grant via Tests\Support\GrantsEngineCapability;
 * legacy givePermissionTo('view_ovr_categories'|'manage_ovr_categories') was removed
 * with the middleware.
 */
class OvrControllersEngineAuthzTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    private Organization $org;

    private Department $department;

    private IncidentType $incidentType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $this->department = Department::factory()->create([
            'organization_id' => $this->org->id,
        ]);
        $this->incidentType = IncidentType::create([
            'name' => 'Safety',
            'name_ar' => 'سلامة',
            'is_active' => true,
        ]);
    }

    private function makeUser(array $attrs = []): User
    {
        return User::factory()->create(array_merge([
            'organization_id' => $this->org->id,
            'department_id' => $this->department->id,
            'is_active' => true,
        ], $attrs));
    }

    private function makeReport(User $reporter): IncidentReport
    {
        return IncidentReport::create([
            'organization_id' => $this->org->id,
            'reporter_id' => $reporter->id,
            'reporter_name' => $reporter->name,
            'reporter_email' => $reporter->email,
            'reporter_department_id' => $this->department->id,
            'incident_datetime' => now(),
            'is_patient_related' => false,
            'informed_authority' => false,
            'incident_type_id' => $this->incidentType->id,
            'incident_description' => 'desc',
            'immediate_action_required' => false,
            'severity_level' => SeverityLevel::Low,
            'status' => ReportStatus::New,
            'is_confidential' => false,
        ]);
    }

    // ----- OVR_VIEW (2 sites: index + list) -----

    public function test_view_categories_requires_ovr_view(): void
    {
        $user = $this->makeUser();
        // Route middleware engine_capability:ovr.view delegates to AccessDecision.
        $this->grantEngineCapability($user, Capability::OVR_VIEW);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/ovr/categories')
            ->assertStatus(200);
    }

    public function test_view_categories_list_requires_ovr_view(): void
    {
        $user = $this->makeUser();
        // Route middleware engine_capability:ovr.view delegates to AccessDecision.
        $this->grantEngineCapability($user, Capability::OVR_VIEW);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/ovr/categories/list')
            ->assertStatus(200);
    }

    // ----- OVR_MANAGE_TYPES (1 site exercised directly: store) -----

    public function test_store_category_requires_ovr_manage_types(): void
    {
        $user = $this->makeUser();
        // Route middleware engine_capability:ovr.manage_types delegates to AccessDecision.
        $this->grantEngineCapability($user, Capability::OVR_MANAGE_TYPES);

        // 422 (validation) confirms the request passed the auth gate; 403/401 would mean denied.
        // Missing 'name_ar' triggers validation failure AFTER the engine gate.
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/ovr/categories', ['name' => 'x'])
            ->assertStatus(422);
    }

    public function test_settings_governing_department_requires_settings_manage(): void
    {
        $user = $this->makeUser();
        $this->grantEngineCapability($user, Capability::SETTINGS_MANAGE);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/ovr/settings/governing-department')
            ->assertStatus(200);
    }

    // ----- OVR_DELETE_ALL (1 site: ReportCommentController::destroy, target-aware) -----

    public function test_delete_comment_requires_ovr_delete_all(): void
    {
        $reporter = $this->makeUser();
        // The upstream IncidentReportPolicy::view() call (out of scope) runs
        // before the migrated destroy() gate. The engine OVR_VIEW grant on the
        // same org satisfies the engine path and lets canAccessByAxis() pass.
        // OVR_DELETE_ALL is what the migrated destroy() gate evaluates.
        // ملاحظة: نمرّر القدرتين معاً كمصفوفة لأن grantEngineCapability يفرض
        // دوراً واحداً لكل نطاق (single-role-per-scope) — استدعاء منفصل
        // سيُلغي الأول.
        $admin = $this->makeUser();
        $this->grantEngineCapability($admin, [Capability::OVR_VIEW, Capability::OVR_DELETE_ALL]);

        $report = $this->makeReport($reporter);

        $comment = ReportComment::create([
            'report_id' => $report->id,
            'user_id' => $reporter->id,
            'author_name' => $reporter->name,
            'text' => 'test comment',
            'is_internal' => false,
        ]);

        // Route binding uses IncidentReport::getRouteKeyName() === 'report_number'
        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/ovr/incidents/{$report->report_number}/comments/{$comment->id}")
            ->assertStatus(200);
    }

    // ----- Negative control -----

    public function test_missing_capability_denies(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/ovr/categories')
            ->assertStatus(403);
    }
}
