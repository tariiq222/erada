<?php

namespace Tests\Feature\OVR;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\OVR\Enums\ReportStatus;
use App\Modules\OVR\Enums\SeverityLevel;
use App\Modules\OVR\Models\IncidentParticipant;
use App\Modules\OVR\Models\IncidentReport;
use App\Modules\OVR\Models\IncidentType;
use App\Modules\OVR\Models\OvrSetting;
use App\Modules\OVR\Services\OvrAuthorizationService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\ScopedDepartmentRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * Department-scoped + governing-department creation & visibility for OVR incident
 * reports. A single governing department applies to all reports. Mirrors
 * RiskCreationGovernanceTest, plus the OVR-only participant-visibility path.
 */
class OvrCreationGovernanceTest extends TestCase
{
    use GrantsEngineCapability;
    use RefreshDatabase;

    private Organization $org;

    private Department $parentDept;

    private Department $childDept;

    private Department $otherDept;

    private Department $governingDept;

    private IncidentType $incidentType;

    private OvrAuthorizationService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(ScopedDepartmentRolesSeeder::class);
        Cache::flush();

        $this->org = Organization::factory()->create();
        $this->parentDept = $this->makeDept('O-PARENT', null, Department::LEVEL_DEPARTMENT);
        $this->childDept = $this->makeDept('O-CHILD', $this->parentDept->id, Department::LEVEL_SECTION);
        $this->otherDept = $this->makeDept('O-OTHER', null, Department::LEVEL_DEPARTMENT);
        $this->governingDept = $this->makeDept('O-GOV', null, Department::LEVEL_DEPARTMENT);

        OvrSetting::setGoverningDepartmentId($this->governingDept->id);

        $this->incidentType = IncidentType::create([
            'name' => 'Medical Error',
            'name_ar' => 'خطأ طبي',
            'is_active' => true,
        ]);

        $this->svc = app(OvrAuthorizationService::class);
        Cache::flush();
    }

    private function makeDept(string $code, ?int $parentId, int $level): Department
    {
        return Department::factory()->create([
            'code' => $code.'-'.uniqid(),
            'organization_id' => $this->org->id,
            'parent_id' => $parentId,
            'level' => $level,
            'is_active' => true,
        ]);
    }

    private function makeUser(?int $deptId, ?Organization $org = null): User
    {
        return User::factory()->create([
            'organization_id' => ($org ?? $this->org)->id,
            'department_id' => $deptId,
            'is_active' => true,
        ]);
    }

    private function withDeptRole(User $user, string $roleKey, Department $dept): User
    {
        $this->grantEngineCapability(
            $user,
            [Capability::OVR_CREATE, Capability::OVR_VIEW],
            'department',
            $dept->id,
            $roleKey,
            ['inherit_to_children' => true],
        );
        Cache::flush();

        return $user;
    }

    private function makeReport(Department $dept, ?Organization $org = null, array $override = []): IncidentReport
    {
        $organization = $org ?? $this->org;
        $reporter = $this->makeUser($dept->id, $organization);

        return IncidentReport::create(array_merge([
            'organization_id' => $organization->id,
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

    /** @return list<string> */
    private function visibleIds(User $user): array
    {
        return IncidentReport::query()
            ->forOrganization($user->organization_id)
            ->visibleTo($user)
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();
    }

    // ---- creation ----

    public function test_member_can_create_for_own_department_only(): void
    {
        $m = $this->withDeptRole($this->makeUser($this->childDept->id), 'dept_member', $this->childDept);
        $this->assertTrue($this->svc->canCreate($m, $this->childDept->id));
        $this->assertFalse($this->svc->canCreate($m, $this->otherDept->id));
        $this->assertEqualsCanonicalizing([$this->childDept->id], $this->svc->creatableDepartmentIds($m));
    }

    public function test_higher_manager_covers_subtree(): void
    {
        $mgr = $this->withDeptRole($this->makeUser($this->parentDept->id), 'dept_manager', $this->parentDept);
        $this->assertTrue($this->svc->canCreate($mgr, $this->childDept->id));
        $this->assertFalse($this->svc->canCreate($mgr, $this->otherDept->id));
    }

    public function test_governing_member_creates_for_any_department(): void
    {
        $gov = $this->withDeptRole($this->makeUser($this->governingDept->id), 'dept_member', $this->governingDept);
        $this->assertTrue($this->svc->governs($gov));
        $this->assertTrue($this->svc->canCreate($gov, $this->otherDept->id));
        $this->assertNull($this->svc->creatableDepartmentIds($gov));
    }

    public function test_user_without_role_cannot_create(): void
    {
        $out = $this->makeUser($this->otherDept->id);
        $this->assertFalse($this->svc->canCreateAny($out));
        $this->assertSame([], $this->svc->creatableDepartmentIds($out));
    }

    // ---- visibility ----

    public function test_member_sees_only_own_department_reports(): void
    {
        $m = $this->withDeptRole($this->makeUser($this->childDept->id), 'dept_member', $this->childDept);
        $own = $this->makeReport($this->childDept);
        $foreign = $this->makeReport($this->otherDept);

        $vis = $this->visibleIds($m);
        $this->assertContains($own->id, $vis);
        $this->assertNotContains($foreign->id, $vis, 'foreign-dept report hidden from member');
    }

    public function test_governing_member_sees_all_reports_org_wide(): void
    {
        $gov = $this->withDeptRole($this->makeUser($this->governingDept->id), 'dept_member', $this->governingDept);
        $a = $this->makeReport($this->otherDept);
        $b = $this->makeReport($this->childDept);

        $vis = $this->visibleIds($gov);
        $this->assertContains($a->id, $vis);
        $this->assertContains($b->id, $vis);
    }

    public function test_higher_manager_sees_subtree_reports(): void
    {
        $mgr = $this->withDeptRole($this->makeUser($this->parentDept->id), 'dept_manager', $this->parentDept);
        $child = $this->makeReport($this->childDept);
        $foreign = $this->makeReport($this->otherDept);

        $vis = $this->visibleIds($mgr);
        $this->assertContains($child->id, $vis);
        $this->assertNotContains($foreign->id, $vis);
    }

    public function test_participant_sees_invited_report(): void
    {
        // A plain user in otherDept with no OVR role: would normally NOT see a
        // report filed in childDept. Inviting them as a participant grants access.
        $invitee = $this->makeUser($this->otherDept->id);
        $report = $this->makeReport($this->childDept);

        $this->assertNotContains($report->id, $this->visibleIds($invitee), 'not visible before invitation');

        IncidentParticipant::create([
            'incident_report_id' => $report->id,
            'user_id' => $invitee->id,
            'invited_by' => $report->reporter_id,
        ]);
        Cache::flush();

        $this->assertContains($report->id, $this->visibleIds($invitee), 'visible after invitation');
    }

    public function test_cross_org_reports_never_visible(): void
    {
        $gov = $this->withDeptRole($this->makeUser($this->governingDept->id), 'dept_member', $this->governingDept);
        $otherOrg = Organization::factory()->create();
        $otherOrgDept = Department::factory()->create([
            'organization_id' => $otherOrg->id,
            'level' => Department::LEVEL_DEPARTMENT,
        ]);
        $foreign = $this->makeReport($otherOrgDept, $otherOrg);

        $this->assertNotContains($foreign->id, $this->visibleIds($gov));
    }

    // ---- HTTP create authorization ----

    public function test_http_member_creates_own_dept_but_not_foreign(): void
    {
        $m = $this->withDeptRole($this->makeUser($this->childDept->id), 'dept_member', $this->childDept);

        $payload = fn (?int $dept) => array_filter([
            'incident_datetime' => now()->format('Y-m-d H:i:s'),
            'is_patient_related' => false,
            'informed_authority' => false,
            'incident_type_id' => $this->incidentType->id,
            'incident_description' => 'E2E incident',
            'immediate_action_required' => false,
            'severity_level' => SeverityLevel::High->value,
            'is_confidential' => false,
            'reporter_department_id' => $dept,
        ], fn ($v) => $v !== null);

        // No reporter_department_id => own department (childDept) => allowed.
        $this->actingAs($m, 'sanctum')->withHeader('X-Skip-Csrf', '1')
            ->postJson('/api/ovr/incidents', $payload(null))
            ->assertCreated();

        // Explicit foreign department => forbidden (member is not a governing member).
        $this->actingAs($m, 'sanctum')->withHeader('X-Skip-Csrf', '1')
            ->postJson('/api/ovr/incidents', $payload($this->otherDept->id))
            ->assertForbidden();
    }

    public function test_http_member_report_pins_own_department(): void
    {
        $m = $this->withDeptRole($this->makeUser($this->childDept->id), 'dept_member', $this->childDept);

        $response = $this->actingAs($m, 'sanctum')->withHeader('X-Skip-Csrf', '1')
            ->postJson('/api/ovr/incidents', [
                'incident_datetime' => now()->format('Y-m-d H:i:s'),
                'is_patient_related' => false,
                'informed_authority' => false,
                'incident_type_id' => $this->incidentType->id,
                'incident_description' => 'pin test',
                'immediate_action_required' => false,
                'severity_level' => SeverityLevel::High->value,
                'is_confidential' => false,
            ])
            ->assertCreated();

        $reportNumber = $response->json('data.report_number');
        $report = IncidentReport::where('report_number', $reportNumber)->firstOrFail();
        $this->assertSame($this->childDept->id, $report->reporter_department_id);
    }

    public function test_governing_member_can_pin_foreign_department_over_http(): void
    {
        $gov = $this->withDeptRole($this->makeUser($this->governingDept->id), 'dept_member', $this->governingDept);

        $response = $this->actingAs($gov, 'sanctum')->withHeader('X-Skip-Csrf', '1')
            ->postJson('/api/ovr/incidents', [
                'incident_datetime' => now()->format('Y-m-d H:i:s'),
                'is_patient_related' => false,
                'informed_authority' => false,
                'incident_type_id' => $this->incidentType->id,
                'incident_description' => 'gov pin test',
                'immediate_action_required' => false,
                'severity_level' => SeverityLevel::High->value,
                'is_confidential' => false,
                'reporter_department_id' => $this->otherDept->id,
            ])
            ->assertCreated();

        $reportNumber = $response->json('data.report_number');
        $report = IncidentReport::where('report_number', $reportNumber)->firstOrFail();
        $this->assertSame($this->otherDept->id, $report->reporter_department_id);
    }

    // ---- admin governing endpoints ----

    public function test_admin_reads_and_updates_governing_department(): void
    {
        $admin = $this->makeUser($this->parentDept->id);
        $this->grantEngineCapability($admin, Capability::SETTINGS_MANAGE);
        Cache::flush();

        $this->actingAs($admin, 'sanctum')->getJson('/api/ovr/settings/governing-department')
            ->assertOk()->assertJsonStructure(['department_id', 'departments']);

        $this->actingAs($admin, 'sanctum')->withHeader('X-Skip-Csrf', '1')
            ->putJson('/api/ovr/settings/governing-department', ['department_id' => $this->otherDept->id])
            ->assertOk();

        $this->assertSame($this->otherDept->id, OvrSetting::getGoverningDepartmentId());
    }

    public function test_non_admin_cannot_update_governing_department(): void
    {
        $m = $this->withDeptRole($this->makeUser($this->childDept->id), 'dept_member', $this->childDept);

        $this->actingAs($m, 'sanctum')->withHeader('X-Skip-Csrf', '1')
            ->putJson('/api/ovr/settings/governing-department', ['department_id' => $this->childDept->id])
            ->assertForbidden();
    }
}
