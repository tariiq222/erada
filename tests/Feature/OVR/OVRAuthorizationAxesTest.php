<?php

namespace Tests\Feature\OVR;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\OVR\Enums\ReportStatus;
use App\Modules\OVR\Enums\SeverityLevel;
use App\Modules\OVR\Models\IncidentReport;
use App\Modules\OVR\Models\IncidentType;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

class OVRAuthorizationAxesTest extends TestCase
{
    use GrantsEngineCapability;
    use RefreshDatabase;

    private Organization $organizationA;

    private Organization $organizationB;

    private Department $departmentA1;

    private Department $departmentA2;

    private Department $departmentB1;

    private IncidentType $incidentType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->organizationA = Organization::factory()->create();
        $this->organizationB = Organization::factory()->create();
        $this->departmentA1 = Department::factory()->create();
        $this->departmentA2 = Department::factory()->create();
        $this->departmentB1 = Department::factory()->create();

        $this->incidentType = IncidentType::create([
            'name' => 'Medical Error',
            'name_ar' => 'خطأ طبي',
            'is_active' => true,
        ]);
    }

    private function makeUser(Organization $organization, Department $department, ?string $role = null, array $permissions = []): User
    {
        $user = User::factory()->create([
            'organization_id' => $organization->id,
            'department_id' => $department->id,
            'is_active' => true,
        ]);

        if ($role) {
            $user->assignRole($role);
        }

        if ($permissions !== []) {
            $user->givePermissionTo($permissions);
        }

        return $user;
    }

    private function makeReport(User $reporter, Department $department, Organization $organization, array $override = []): IncidentReport
    {
        return IncidentReport::create(array_merge([
            'organization_id' => $organization->id,
            'reporter_id' => $reporter->id,
            'reporter_name' => $reporter->name,
            'reporter_email' => $reporter->email,
            'reporter_department_id' => $department->id,
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

    public function test_index_and_recent_require_view_permission(): void
    {
        $user = $this->makeUser($this->organizationA, $this->departmentA1);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/ovr/incidents')
            ->assertStatus(403);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/ovr/incidents/recent')
            ->assertStatus(403);
    }

    public function test_change_status_respects_own_department_and_all_axes(): void
    {
        $this->markTestIncomplete(
            'Engine-only AuthZ: department-level OVR change_status isolation cannot be tested '.
            'with the current engine fixture — scoped_role_definitions at department scope '.
            'are not seeded, and the engine ignores flat givePermissionTo() grants. '.
            'Cross-org isolation is covered in test_update_status_rejects_cross_organization_assignee.'
        );
    }

    public function test_update_status_rejects_cross_organization_assignee(): void
    {
        $actor = $this->makeUser($this->organizationA, $this->departmentA1, 'admin');
        $reporter = $this->makeUser($this->organizationA, $this->departmentA1);
        $report = $this->makeReport($reporter, $this->departmentA1, $this->organizationA);
        $assignee = $this->makeUser($this->organizationB, $this->departmentB1);

        $this->actingAs($actor, 'sanctum')
            ->patchJson("/api/ovr/incidents/{$report->report_number}/status", [
                'status' => ReportStatus::InProgress->value,
                'assigned_to' => $assignee->id,
                'reason' => 'cross org assignment',
            ])
            ->assertStatus(403);

        $this->assertNull($report->fresh()->assigned_to);
        $this->assertSame(ReportStatus::New, $report->fresh()->status);
    }

    public function test_comment_permission_follows_view_axis(): void
    {
        // Actor holds ONLY the engine OVR_VIEW grant at the same org — no
        // OVR_COMMENT capability, so the comment gate must deny them.
        $actor = $this->makeUser($this->organizationA, $this->departmentA1);
        $this->grantEngineCapability($actor, Capability::OVR_VIEW);
        $otherReporter = $this->makeUser($this->organizationA, $this->departmentA2);
        $report = $this->makeReport($otherReporter, $this->departmentA2, $this->organizationA);

        $this->actingAs($actor, 'sanctum')
            ->postJson("/api/ovr/incidents/{$report->report_number}/comments", [
                'text' => 'blocked comment',
                'is_internal' => false,
            ])
            ->assertStatus(403);
    }

    public function test_stats_requires_view_statistics_permission(): void
    {
        $viewer = $this->makeUser($this->organizationA, $this->departmentA1, 'member');
        $this->makeReport($viewer, $this->departmentA1, $this->organizationA);

        $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/ovr/incidents/stats')
            ->assertStatus(403);

        // admin role has is_admin_role=true in scoped_role_definitions so the engine
        // grants all capabilities including OVR_VIEW_STATISTICS.
        $statsUser = $this->makeUser($this->organizationA, $this->departmentA1, 'admin');
        $this->makeReport($statsUser, $this->departmentA1, $this->organizationA);

        $this->actingAs($statsUser, 'sanctum')
            ->getJson('/api/ovr/incidents/stats')
            ->assertStatus(200)
            ->assertJsonStructure([
                'total',
                'by_status',
                'by_severity',
                'patient_related',
                'informed_authority',
                'overdue',
                'avg_resolution_hours',
                'period',
            ]);
    }

    public function test_viewer_role_has_reporting_baseline_ovr_permissions_only(): void
    {
        $viewer = $this->makeUser($this->organizationA, $this->departmentA1, 'viewer');

        // Viewer scoped_role_definition (engine seed) grants OVR_VIEW only —
        // edit/delete/investigate/close/export/statistics stay reserved for admin.
        $this->assertTrue(
            AccessDecision::can($viewer, Capability::OVR_VIEW),
            'viewer must hold Capability::OVR_VIEW via engine scoped role'
        );

        foreach ([
            Capability::OVR_DELETE,
            Capability::OVR_DELETE_ALL,
            Capability::OVR_EDIT,
            Capability::OVR_INVESTIGATE,
            Capability::OVR_CLOSE,
            Capability::OVR_CHANGE_STATUS,
            Capability::OVR_ASSIGN,
            Capability::OVR_VIEW_INTERNAL_COMMENTS,
            Capability::OVR_EXPORT,
            Capability::OVR_VIEW_STATISTICS,
            Capability::OVR_MANAGE_TYPES,
        ] as $capability) {
            $this->assertFalse(
                AccessDecision::can($viewer, $capability),
                "viewer must not hold {$capability}"
            );
        }
    }
}
