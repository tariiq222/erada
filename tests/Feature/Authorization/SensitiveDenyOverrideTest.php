<?php

namespace Tests\Feature\Authorization;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\ScopedRole;
use App\Modules\Core\Models\ScopeType;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\DepartmentCapacityRole;
use App\Modules\OVR\Enums\ReportStatus;
use App\Modules\OVR\Enums\SeverityLevel;
use App\Modules\OVR\Models\IncidentReport;
use App\Modules\OVR\Models\IncidentType;
use Database\Seeders\ScopedDepartmentRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * SensitiveDenyOverrideTest — Phase 6, Task 4.
 *
 * A sensitive record (an OVR incident with is_confidential=true) must not be
 * visible to a hierarchy ancestor by scope-chain inheritance alone. Access to a
 * sensitive record is need-to-know: reporter/assigned, a can_view_confidential
 * scoped role, super_admin, or the owner floor. Non-sensitive records are
 * unaffected (the engine override is skipped entirely).
 */
class SensitiveDenyOverrideTest extends TestCase
{
    use RefreshDatabase;

    private IncidentType $incidentType;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ScopedDepartmentRolesSeeder::class);
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
        DepartmentCapacityRole::create(['department_id' => $sector->id, 'capacity' => 'manager', 'role_key' => 'dept_manager']);
        $mgr = User::factory()->create(['organization_id' => $org->id]);
        $sector->update(['manager_id' => $mgr->id]);

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
        DepartmentCapacityRole::create(['department_id' => $sector->id, 'capacity' => 'manager', 'role_key' => 'dept_manager']);
        $mgr = User::factory()->create(['organization_id' => $org->id]);
        $sector->update(['manager_id' => $mgr->id]);

        // same setup, but NOT confidential -> normal scope-chain visibility holds
        $report = $this->makeReport($org, $child, ['is_confidential' => false]);

        $why = AccessDecision::whyCan($mgr->fresh(), Capability::OVR_VIEW, $report);
        $this->assertTrue($why['granted']);
        $this->assertSame('scope_chain', $why['layer']);
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
        $this->seedConfidentialViewerDef();

        $cleared = User::factory()->create(['organization_id' => $org->id]);
        $cleared->assignScopedRole('confidential_viewer', ScopedRole::SCOPE_ORGANIZATION, $org->id, $cleared->id);

        $report = $this->makeReport($org, $dept, ['is_confidential' => true]);

        $this->assertTrue(AccessDecision::can($cleared->fresh(), Capability::OVR_VIEW, $report));
    }

    public function test_super_admin_bypasses_sensitive_gate(): void
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $admin = User::factory()->create(['organization_id' => $org->id]);
        $admin->assignRole('super_admin');

        $report = $this->makeReport($org, $dept, ['is_confidential' => true]);

        $this->assertTrue(AccessDecision::can($admin->fresh(), Capability::OVR_VIEW, $report));
    }

    private function seedConfidentialViewerDef(): void
    {
        $orgScopeType = ScopeType::firstOrCreate(
            ['key' => ScopedRole::SCOPE_ORGANIZATION],
            [
                'label_ar' => 'المؤسسة',
                'label_en' => 'Organization',
                'model_class' => Organization::class,
                'supports_hierarchy' => false,
                'supports_expiry' => false,
                'is_active' => true,
                'sort_order' => 1,
            ]
        );

        $exists = DB::table('scoped_role_definitions')
            ->where('scope_type_id', $orgScopeType->id)
            ->where('role_key', 'confidential_viewer')
            ->exists();

        if (! $exists) {
            DB::table('scoped_role_definitions')->insert([
                'name' => 'organization.confidential_viewer',
                'display_name' => 'Confidential Viewer',
                'scope_type' => ScopedRole::SCOPE_ORGANIZATION,
                'scope_type_id' => $orgScopeType->id,
                'role_key' => 'confidential_viewer',
                'label_ar' => 'مشاهد سري',
                'label_en' => 'Confidential Viewer',
                'is_admin_role' => false,
                'permissions' => json_encode($this->expandFlags(['ovr.view', 'ovr.view_all'], [
                    'can_manage_members' => false,
                    'can_edit' => false,
                    'can_delete' => false,
                    'can_view_all' => true,
                    'can_view_confidential' => true,
                ])),
                'is_active' => true,
                'sort_order' => 99,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            Cache::flush();
        }
    }

    private function expandFlags(array $permissions, array $flags): array
    {
        $byAction = fn (array $actions): array => array_values(array_filter(
            Capability::all(),
            function (string $c) use ($actions) {
                $a = str_contains($c, '.') ? substr($c, strrpos($c, '.') + 1) : $c;

                return in_array($a, $actions, true);
            }
        ));
        if (! empty($flags['can_edit'])) {
            $permissions = array_merge($permissions, $byAction(['edit', 'update']));
        }
        if (! empty($flags['can_delete'])) {
            $permissions = array_merge($permissions, $byAction(['delete', 'remove']));
        }
        if (! empty($flags['can_view_all'])) {
            $permissions = array_merge($permissions, $byAction(['view', 'view_all', 'view_reports']));
        }
        if (! empty($flags['can_manage_members'])) {
            $permissions = array_merge($permissions, $byAction(['manage_members', 'assign_roles']));
        }
        if (! empty($flags['can_view_confidential'])) {
            $permissions[] = Capability::OVR_VIEW_CONFIDENTIAL;
        }

        return array_values(array_unique($permissions));
    }
}
