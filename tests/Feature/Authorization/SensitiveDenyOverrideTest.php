<?php

namespace Tests\Feature\Authorization;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\OVR\Enums\ReportStatus;
use App\Modules\OVR\Enums\SeverityLevel;
use App\Modules\OVR\Models\IncidentReport;
use App\Modules\OVR\Models\IncidentType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * SensitiveDenyOverrideTest — Phase 6, Task 4.
 *
 * A sensitive record (an OVR incident with is_confidential=true) must not be
 * visible to a hierarchy ancestor by scope-chain inheritance alone. Access to a
 * sensitive record is need-to-know: reporter/assigned, a can_view_confidential
 * canonical confidential capability, super_admin, or the owner floor. Non-sensitive records are
 * unaffected (the engine override is skipped entirely).
 */
class SensitiveDenyOverrideTest extends TestCase
{
    use GrantsEngineCapability;
    use RefreshDatabase;

    private IncidentType $incidentType;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->incidentType = IncidentType::create([
            'name' => 'Medical Error',
            'name_ar' => 'خطأ طبي',
            'is_active' => true,
        ]);
    }

    private function makeReport(Organization $org, Department $dept, array $override = []): IncidentReport
    {
        // reporter_id is NOT NULL; default to a distinct reporter so the report is
        // not implicitly owned by the user under test (unless the test sets it).
        $reporter = User::factory()->create(['organization_id' => $org->id]);

        return IncidentReport::create(array_merge([
            'organization_id' => $org->id,
            'reporter_id' => $reporter->id,
            'reporter_name' => $reporter->name,
            'reporter_email' => $reporter->email,
            'reporter_department_id' => $dept->id,
            'incident_datetime' => now(),
            'is_patient_related' => false,
            'informed_authority' => false,
            'incident_type_id' => $this->incidentType->id,
            'incident_description' => 'desc',
            'immediate_action_required' => false,
            'severity_level' => SeverityLevel::High,
            'status' => ReportStatus::New,
            'is_confidential' => false,
        ], $override));
    }

    public function test_sensitive_record_does_not_leak_up_the_chain(): void
    {
        $org = Organization::factory()->create();
        $sector = Department::factory()->create(['organization_id' => $org->id]);
        $child = Department::factory()->create(['organization_id' => $org->id, 'parent_id' => $sector->id]);
        $mgr = User::factory()->create(['organization_id' => $org->id]);
        $sector->update(['manager_id' => $mgr->id]);
        $this->grantEngineCapability($mgr, Capability::OVR_VIEW, 'department', $sector->id, 'dept_manager', [
            'inherit_to_children' => true,
        ]);

        // a sensitive clinical record in the child department
        $report = $this->makeReport($org, $child, ['is_confidential' => true]);

        // sector manager normally sees child records, but sensitive blocks the ascent
        $this->assertFalse(AccessDecision::can($mgr->fresh(), Capability::OVR_VIEW, $report));

        $why = AccessDecision::whyCan($mgr->fresh(), Capability::OVR_VIEW, $report);
        $this->assertFalse($why['granted']);
        $this->assertSame('sensitive_denied', $why['layer']);
    }

    public function test_non_sensitive_record_still_inherits_up_the_chain(): void
    {
        $org = Organization::factory()->create();
        $sector = Department::factory()->create(['organization_id' => $org->id]);
        $child = Department::factory()->create(['organization_id' => $org->id, 'parent_id' => $sector->id]);
        $mgr = User::factory()->create(['organization_id' => $org->id]);
        $sector->update(['manager_id' => $mgr->id]);
        $this->grantEngineCapability($mgr, Capability::OVR_VIEW, 'department', $sector->id, 'dept_manager', [
            'inherit_to_children' => true,
        ]);

        // same setup, but NOT confidential -> normal scope-chain visibility holds
        $report = $this->makeReport($org, $child, ['is_confidential' => false]);

        $why = AccessDecision::whyCan($mgr->fresh(), Capability::OVR_VIEW, $report);
        $this->assertTrue($why['granted']);
        $this->assertSame('canonical_assignment', $why['layer']);
    }

    public function test_reporter_sees_own_sensitive_record(): void
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $reporter = User::factory()->create(['organization_id' => $org->id, 'department_id' => $dept->id]);

        $report = $this->makeReport($org, $dept, [
            'is_confidential' => true,
            'reporter_id' => $reporter->id,
        ]);

        $this->assertTrue(AccessDecision::can($reporter->fresh(), Capability::OVR_VIEW, $report));
    }

    public function test_assignee_sees_assigned_sensitive_record(): void
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $assignee = User::factory()->create(['organization_id' => $org->id, 'department_id' => $dept->id]);

        $report = $this->makeReport($org, $dept, [
            'is_confidential' => true,
            'assigned_to' => $assignee->id,
        ]);

        $this->assertTrue(AccessDecision::can($assignee->fresh(), Capability::OVR_VIEW, $report));
    }

    public function test_confidential_cleared_role_sees_sensitive_record(): void
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $cleared = User::factory()->create(['organization_id' => $org->id]);
        $this->grantEngineCapability(
            $cleared,
            [Capability::OVR_VIEW, Capability::OVR_CONFIDENTIAL],
            'organization',
            $org->id,
            'confidential_viewer',
        );

        $report = $this->makeReport($org, $dept, ['is_confidential' => true]);

        $this->assertTrue(AccessDecision::can($cleared->fresh(), Capability::OVR_VIEW, $report));
    }

    public function test_super_admin_bypasses_sensitive_gate(): void
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $admin = User::factory()->create(['organization_id' => $org->id]);
        $this->grantCanonicalSuperAdmin($admin);

        $report = $this->makeReport($org, $dept, ['is_confidential' => true]);

        $this->assertTrue(AccessDecision::can($admin->fresh(), Capability::OVR_VIEW, $report));
    }
}
