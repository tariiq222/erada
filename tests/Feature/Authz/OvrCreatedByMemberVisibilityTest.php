<?php

namespace Tests\Feature\Authz;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\ScopedRole;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\OVR\Enums\SeverityLevel;
use App\Modules\OVR\Models\IncidentReport;
use App\Modules\OVR\Models\IncidentType;
use App\Modules\OVR\Models\OvrSetting;
use App\Modules\OVR\Services\OvrAuthorizationService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\Scenarios\AuthzTestFixturesScenario;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

/**
 * Practical example: an employee files an incident report, then we audit who can see it.
 *
 * Mirrors ProjectCreatedByMemberVisibilityTest and RiskCreatedByMemberVisibilityTest
 * for the OVR module. Same cast, same expectations — three things differ:
 *   1. Route prefix is `/api/ovr/incidents` (not `/api/risk-management/risks`).
 *   2. OVR requires an `IncidentType` reference (seeded ad-hoc here).
 *   3. OVR has `is_confidential`; we file a NON-confidential report so the
 *      visibility graph matches the project/risk tests. The confidential bypass
 *      is exercised by a separate audit below — the engine treats sensitive
 *      records differently (only mayAccessSensitive grants, no chain upward).
 *
 * ponytail: when is_confidential=false (this case), the visibility rule is
 * identical to project/risk: org-isolation gate on cross-org access, super_admin
 * spans, l4 member sees via test-only role bridge. The fascinating divergence
 * lives in the confidential case, which we also probe.
 */
class OvrCreatedByMemberVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private ?string $incidentId = null;

    private ?string $confidentialIncidentId = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->makeScenario()->run();
        $this->seedIncidentType();
        AccessDecision::flushCache();
    }

    public function test_alice_files_an_incident_then_audits_who_can_see_it(): void
    {
        $alice = User::where('email', 'deep.l3.manager@authz.demo')->firstOrFail();
        $aliceDept = Department::where('code', 'AUTHZ2-DEEP-L3')->firstOrFail();
        $l4Member = User::where('email', 'deep.l4.member@authz.demo')->firstOrFail();
        $tntbMember = User::where('email', 'tntb.member@authz.demo')->firstOrFail();
        $superUser = User::where('email', 'super.authz@authz.demo')->firstOrFail();
        $safetyMember = $this->ensureSafetyUser(); // a member-only user in ORG A with no scoping

        $incidentType = IncidentType::firstOrFail();

        // Sanity: alice + l4 share ORG A; tntb sits in ORG B.
        $orgAId = Organization::where('code', 'AUTHZ-CO')->value('id');
        $orgBId = Organization::where('code', 'AUTHZ-TNTB')->value('id');
        $this->assertSame($orgAId, $alice->organization_id);
        $this->assertSame($orgAId, $l4Member->organization_id);
        $this->assertSame($orgBId, $tntbMember->organization_id);

        // 1) Alice files a NON-confidential incident -----------------------------
        $create = $this->actingAs($alice, 'sanctum')
            ->postJson('/api/ovr/incidents', [
                'reporter_department_id' => $aliceDept->id,
                'incident_datetime' => '2026-06-25 10:30:00', // strictly before now; validator rejects future dates
                'is_patient_related' => false,
                'informed_authority' => false,
                'incident_type_id' => $incidentType->id,
                'incident_description' => 'انقطاع متوقع في خدمة أساسية يؤثر على تدفق العمل.',
                'immediate_action_required' => true,
                'severity_level' => SeverityLevel::Medium->value,
                'is_confidential' => false,
            ]);

        $create->assertStatus(201);
        $incidentPayload = $create->json('data');
        $this->assertNotNull($incidentPayload, 'OVR create response must include a data envelope.');

        // ponytail: OVR routes use the human-facing `report_number` (e.g.
        // OVR-2026-0001) as the route-model-binding key, NOT the UUID primary.
        // Building the URL with the UUID silently 404s — a hard-to-spot trap.
        $this->incidentId = $incidentPayload['report_number'] ?? null;
        $this->assertNotNull($this->incidentId, 'OVR create must return a report_number.');
        $incident = IncidentReport::where('report_number', $this->incidentId)->firstOrFail();
        $this->assertFalse($incident->is_confidential,
            'Sanity: this report really is non-confidential.');

        // 2) Audit visibility for the non-confidential incident -----------------
        $report = [];
        foreach ([
            'alice (creator, admin)' => $alice,
            'super_admin' => $superUser,
            'l4 member (alice dept subtree)' => $l4Member,
            'tenant B member (cross-org)' => $tntbMember,
            'safety member (random in org A)' => $safetyMember,
        ] as $label => $user) {
            $report[$label] = $this->canSee($user, $this->incidentId);
        }

        $this->assertTrue($report['alice (creator, admin)'],
            'alice must see her own non-confidential incident.');
        $this->assertTrue($report['super_admin'],
            'super_admin spans every org.');
        $this->assertTrue($report['l4 member (alice dept subtree)'],
            'l4 member sees via the test-only member->viewer can_view_all bridge.');
        $this->assertFalse($report['tenant B member (cross-org)'],
            'org-isolation gate must 403 cross-org.');

        // ponytail: a member user in ORG A but unrelated to alice's dept subtree
        // — same authority tier, no positional scope chain to lean on. Should
        // still see it because the same view-capability bridge kicks in. If
        // this flips, the engine has tightened the bridge.
        $this->assertTrue($report['safety member (random in org A)'],
            'member role grants org-wide view via can_view_all bridge (test-only).');

        // 3) Confidential incident: re-audit. Engine behavior changes here. ------
        $create2 = $this->actingAs($alice, 'sanctum')
            ->postJson('/api/ovr/incidents', [
                'reporter_department_id' => $aliceDept->id,
                'incident_datetime' => '2026-06-25 11:00:00',
                'is_patient_related' => false,
                'informed_authority' => true,
                'incident_type_id' => $incidentType->id,
                'incident_description' => 'حادثة سرية — يجب أن لا تنتشر عبر السلسلة الإدارية.',
                'immediate_action_required' => true,
                'severity_level' => SeverityLevel::High->value,
                'is_confidential' => true,
            ]);

        $create2->assertStatus(201);
        $incident2 = $create2->json('data');
        $this->confidentialIncidentId = $incident2['report_number'] ?? null;
        $this->assertNotNull($this->confidentialIncidentId, 'Confidential OVR create must return a report_number.');

        $aliceSeesConfidential = $this->canSee($alice, $this->confidentialIncidentId);
        $superSeesConfidential = $this->canSee($superUser, $this->confidentialIncidentId);
        $l4SeesConfidential = $this->canSee($l4Member, $this->confidentialIncidentId);
        $tntbSeesConfidential = $this->canSee($tntbMember, $this->confidentialIncidentId);

        // ponytail: confidential records must NOT inherit upward via the dept
        // hierarchy. Per docs/AUTHZ-DECISIONS.md: super_admin + owner floor are
        // already handled; from there, only mayAccessSensitive() grants. So a
        // peer l4 member — even one who could see the non-confidential twin —
        // must hit a deny. We pin that here.
        $this->assertTrue($aliceSeesConfidential, 'owner floor grants confidential view.');
        $this->assertTrue($superSeesConfidential, 'super_admin bypasses confidential deny.');
        $this->assertFalse($l4SeesConfidential,
            'CONFIDENTIAL deny-override: l4 member sees non-confidential incident but must NOT see the confidential twin.');
        $this->assertFalse($tntbSeesConfidential, 'cross-org + confidential still 403.');
    }

    /**
     * The OVR governing department is the org's appointed oversight unit — per
     * OvrGovernanceSeeder's contract, "Members of (the subtree of) this department
     * may create incident reports for any department and see every report org-wide."
     *
     * The single source of truth for that contract is
     * IncidentReport::scopeVisibleTo() which short-circuits the per-row filter
     * when OvrAuthorizationService::governs() is true. We pin the contract here:
     * a user in the governing department must see incidents they personally had
     * nothing to do with — specifically one filed in a totally separate branch.
     *
     * ponytail: this test stands or falls on the governance wiring existing in
     * the database. With no OvrSetting row, governs() returns false for every
     * user and this whole class of assertion collapses.
     */
    public function test_qa_governing_dept_member_sees_every_org_incident(): void
    {
        // 1) Pin the OVR governing department to AUTHZ2-DEEP-L1 ("CEO office").
        $governingDept = Department::where('code', 'AUTHZ2-DEEP-L1')->firstOrFail();
        OvrSetting::setGoverningDepartmentId($governingDept->id);

        // 2) Create a governor user with a scoped dept_manager role at the
        // governing dept. Without the scope grant, grantingScopes() returns an
        // empty array and governs() never resolves — the test would silently
        // fall back to the per-row filter and pass for the wrong reason.
        $governor = $this->mkGovernorIn($governingDept);

        AccessDecision::flushCache();
        $svc = app(OvrAuthorizationService::class);
        $this->assertTrue($svc->governs($governor),
            'governor must satisfy OvrAuthorizationService::governs() — otherwise the test below would pass via per-row filter, not the governance contract.');

        // 3) Two distinct reporters file incidents in two distinct branches.
        $alice = User::where('email', 'deep.l3.manager@authz.demo')->firstOrFail();
        $aliceDept = Department::where('code', 'AUTHZ2-DEEP-L3')->firstOrFail();
        $bobDept = Department::where('code', 'AUTHZ4-HYB-COLB-L3')->firstOrFail();

        // Bob is a scoped-only user (no flat role) so he's inactive for OVR
        // create by default — give him 'member' so we can use him as a 2nd
        // reporter from a sibling branch.
        $bob = User::where('email', 'hyb.colB.l3.manager@authz.demo')->firstOrFail();
        if (! $bob->hasRole('member')) {
            $bob->assignRole('member');
        }

        $aliceReport = $this->createIncidentAs($alice, $aliceDept, 'Incident by Alice (colA L3)');
        $bobReport = $this->createIncidentAs($bob, $bobDept, 'Incident by Bob (colB L3)');

        // 4) The governor's index must list BOTH — alice's in their own branch
        // AND bob's in a totally separate branch (different department, same org).
        // This is THE governance contract the OvrGovernanceSeeder comment promises.
        $indexGov = $this->actingAs($governor, 'sanctum')->getJson('/api/ovr/incidents?per_page=50');
        $indexGov->assertStatus(200);
        $listedGov = collect($indexGov->json('data'))->pluck('report_number');
        $this->assertContains($aliceReport, $listedGov,
            'governor must see alice\'s incident (own branch).');
        $this->assertContains($bobReport, $listedGov,
            'governor must see bob\'s incident (different branch, same org) — the governance contract.');

        // 5) ponytail: governance widens VISIBILITY within the org. It does NOT
        // widen past org isolation. A 3rd reporter in ORG B must produce an
        // incident the governor CANNOT see — even though governs() also fires
        // for them at the OvrAuthorizationService layer, the controller's
        // forOrganization($user->organization_id) gate runs first and blocks it.
        $tntb = User::where('email', 'tntb.admin@authz.demo')->firstOrFail();
        $tntbDept = Department::where('code', 'TNTB-L2')->firstOrFail();
        $tntbReport = $this->createIncidentAs($tntb, $tntbDept, 'Incident in Tenant B');

        $indexGovAgain = $this->actingAs($governor, 'sanctum')->getJson('/api/ovr/incidents?per_page=50');
        $indexGovAgain->assertStatus(200);
        $listedAgain = collect($indexGovAgain->json('data'))->pluck('report_number');
        $this->assertNotContains($tntbReport, $listedAgain,
            'governor is org-scoped — Tenant B incident must NOT leak into ORG A list.');
    }

    private function mkGovernorIn(Department $governingDept): User
    {
        $email = 'governor@authz.demo';
        $user = User::firstOrCreate(['email' => $email], [
            'name' => 'حاكم OVR',
            'password' => bcrypt('password'),
            'department_id' => $governingDept->id,
            'organization_id' => $governingDept->organization_id,
            'is_active' => true,
        ]);

        $user->update([
            'department_id' => $governingDept->id,
            'organization_id' => $governingDept->organization_id,
        ]);

        // No flat admin/super role — the governor's authority must come purely
        // from the dept_manager scoped role at the governing department.
        $user->assignScopedRole(
            role: 'dept_manager',
            scopeType: ScopedRole::SCOPE_DEPARTMENT,
            scopeId: $governingDept->id,
        );

        return $user;
    }

    private function createIncidentAs(User $reporter, Department $dept, string $description): string
    {
        $incidentType = IncidentType::firstOrFail();

        $resp = $this->actingAs($reporter, 'sanctum')->postJson('/api/ovr/incidents', [
            'reporter_department_id' => $dept->id,
            'incident_datetime' => '2026-06-25 09:00:00',
            'is_patient_related' => false,
            'informed_authority' => false,
            'incident_type_id' => $incidentType->id,
            'incident_description' => $description,
            'immediate_action_required' => true,
            'severity_level' => SeverityLevel::Medium->value,
            'is_confidential' => false,
        ]);

        $resp->assertStatus(201);

        return $resp->json('data.report_number');
    }

    private function canSee(User $user, string $reportNumber): bool
    {
        $resp = $this->actingAs($user, 'sanctum')
            ->getJson("/api/ovr/incidents/{$reportNumber}");

        return $resp->status() === 200;
    }

    private function seedIncidentType(): void
    {
        // The scenario seeder does not seed IncidentType; create the minimum
        // row the StoreIncidentReportRequest::rules() needs.
        IncidentType::firstOrCreate([
            'name' => 'Operational Incident',
        ], [
            'name_ar' => 'حادثة تشغيلية',
            'is_active' => true,
            'requires_reportable_type' => false,
        ]);
    }

    private function ensureSafetyUser(): User
    {
        // A 'member'-role user in ORG A that is NOT in alice's dept subtree,
        // to exercise the "no positional chain, only capability bridge" path.
        $safetyDept = Department::where('code', 'AUTHZ6-ORPHAN-ROOT')->firstOrFail();
        $email = 'safety.member@authz.demo';

        $user = User::firstOrCreate(['email' => $email], [
            'name' => 'عضو السلامة',
            'password' => bcrypt('password'),
            'department_id' => $safetyDept->id,
            'organization_id' => $safetyDept->organization_id,
            'is_active' => true,
        ]);

        // Reset the dept (in case re-running overwrote it).
        $user->update([
            'department_id' => $safetyDept->id,
            'organization_id' => $safetyDept->organization_id,
        ]);

        if (! $user->hasRole('member')) {
            $user->assignRole('member');
        }

        return $user;
    }

    private function makeScenario(): AuthzTestFixturesScenario
    {
        $cmd = new Command(new ArgvInput([]));
        $cmd->setOutput(new OutputStyle(new ArgvInput([]), new NullOutput));

        return new AuthzTestFixturesScenario($cmd);
    }
}
