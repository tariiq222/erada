<?php

namespace Tests\Feature\Authz;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\RiskManagement\Models\Risk;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\Scenarios\AuthzTestFixturesScenario;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

/**
 * Practical example: an employee logs a risk, then we audit who can see it.
 *
 * Mirrors ProjectCreatedByMemberVisibilityTest but exercises the Risk module:
 *   1. Alice — a manager in the Deep fixture (HR dept chain in ORG A) — logs a
 *      risk under her department AUTH2-DEEP-L3 via POST /api/risk-management/risks.
 *   2. We GET /api/risk-management/risks/{id} as each of four users and pin
 *      what the engine answers (200 / 403). Same cast as the project test
 *      so the visibility graph between the two modules is directly comparable.
 *
 * Casts:
 *   - alice (deep.l3.manager)         — creator, org-wide admin role
 *   - super_admin                      — spans every org
 *   - l4 member (deep.l4.member)       — alice's subordinate in the dept subtree
 *   - tntb member                      — different org, cross-tenant boundary
 *
 * ponytail: parallel structure with the project test on purpose — it makes
 * "what did the engine grant on each of my modules" answerable at a glance.
 */
class RiskCreatedByMemberVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private ?int $riskId = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->makeScenario()->run();
        AccessDecision::flushCache();
    }

    public function test_alice_logs_a_risk_then_audits_who_can_see_it(): void
    {
        $alice = User::where('email', 'deep.l3.manager@authz.demo')->firstOrFail();
        $aliceDept = Department::where('code', 'AUTHZ2-DEEP-L3')->firstOrFail();
        $l4Member = User::where('email', 'deep.l4.member@authz.demo')->firstOrFail();
        $tntbMember = User::where('email', 'tntb.member@authz.demo')->firstOrFail();
        $superUser = User::where('email', 'super.authz@authz.demo')->firstOrFail();

        // Sanity: alice + l4 share ORG A; tntb sits in ORG B.
        $orgAId = Organization::where('code', 'AUTHZ-CO')->value('id');
        $orgBId = Organization::where('code', 'AUTHZ-TNTB')->value('id');
        $this->assertSame($orgAId, $alice->organization_id);
        $this->assertSame($orgAId, $l4Member->organization_id);
        $this->assertSame($orgBId, $tntbMember->organization_id);

        // 1) Alice logs the risk ----------------------------------------------
        $create = $this->actingAs($alice, 'sanctum')
            ->postJson('/api/risk-management/risks', [
                'title' => 'خطر تشغيلي في إدارة العمليات',
                'description' => 'انقطاع متوقع في خدمة أساسية يؤثر على تدفق العمل اليومي.',
                'discovery_date' => '2026-06-29',
                'type' => 'operational',
                'department_id' => $aliceDept->id,
                'initial_likelihood' => 4,
                'initial_impact' => 3,
            ]);

        $create->assertStatus(201);
        $this->riskId = $create->json('risk.id') ?? $create->json('data.id');

        if ($this->riskId === null) {
            // Fallback for response shapes that nest under 'data' or differently.
            $this->riskId = Risk::latest('id')->value('id');
        }

        $this->assertNotNull($this->riskId, 'Risk create must return a risk id.');
        $risk = Risk::findOrFail($this->riskId);
        $this->assertSame($alice->id, $risk->created_by,
            'created_by must equal the authenticated alice.');

        // 2) Audit: can each user GET /api/risk-management/risks/{id}? --------
        $report = [];
        foreach ([
            'alice (creator, admin)' => $alice,
            'super_admin' => $superUser,
            'l4 member (alice dept subtree)' => $l4Member,
            'tenant B member (cross-org)' => $tntbMember,
        ] as $label => $user) {
            $report[$label] = $this->canSee($user);
        }

        // Engine's actual answers, pinned:
        $this->assertTrue($report['alice (creator, admin)'],
            'alice must see the risk she logged — owner_floor + admin role.');
        $this->assertTrue($report['super_admin'],
            'super_admin bypasses every gate, including the Risk one.');

        // ponytail: same engine path as the project test — l4Member's flat
        // 'member' Spatie role bridges to a viewer-style scoped definition via
        // seedLegacyTestRoles() (test-only), and the engine treats 'risks.view'
        // as a view-action granted by can_view_all=true. The result happens to
        // match the project test (200), but the granting path is the
        // org-functional-role bridge, not positional chain.
        $this->assertTrue($report['l4 member (alice dept subtree)'],
            'l4 member sees the risk via the test-only member->viewer role bridge (can_view_all=true).');

        $this->assertFalse($report['tenant B member (cross-org)'],
            'tntb member is in ORG B — org-isolation gate must 403.');

        // 3) Index visibility: alice's /api/risk-management/risks MUST list her risk.
        $indexResp = $this->actingAs($alice, 'sanctum')
            ->getJson('/api/risk-management/risks?per_page=50');
        $indexResp->assertStatus(200);
        $this->assertTrue(
            collect($indexResp->json('data'))->pluck('id')->contains($this->riskId),
            "Alice's risk index must list risk #{$this->riskId}."
        );

        // 4) Cross-org user's index MUST NOT list alice's risk.
        $tntbIndex = $this->actingAs($tntbMember, 'sanctum')
            ->getJson('/api/risk-management/risks?per_page=50');
        $tntbIndex->assertStatus(200);
        $this->assertFalse(
            collect($tntbIndex->json('data'))->pluck('id')->contains($this->riskId),
            "Tenant B user's index MUST NOT list risk #{$this->riskId} (different org)."
        );
    }

    private function canSee(User $user): bool
    {
        if ($this->riskId === null) {
            throw new \RuntimeException('Risk id missing — call create first.');
        }

        $resp = $this->actingAs($user, 'sanctum')
            ->getJson("/api/risk-management/risks/{$this->riskId}");

        return $resp->status() === 200;
    }

    private function makeScenario(): AuthzTestFixturesScenario
    {
        $cmd = new Command(new ArgvInput([]));
        $cmd->setOutput(new OutputStyle(new ArgvInput([]), new NullOutput));

        return new AuthzTestFixturesScenario($cmd);
    }
}
