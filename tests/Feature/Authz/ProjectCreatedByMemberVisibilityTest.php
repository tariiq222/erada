<?php

namespace Tests\Feature\Authz;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\Scenarios\AuthzTestFixturesScenario;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

/**
 * Practical example: an employee creates a project, then we audit who can see it.
 *
 * Story:
 *   1. Alice — a manager in the Deep fixture (HR department chain in ORG A) —
 *      creates a real project under her own department AUTHZ2-DEEP-L3.
 *   2. We GET /api/projects/{id} as each of four users and record what the
 *      engine answers (200 / 403). The result is the system's actual visibility
 *      graph for one specific project.
 *
 * Casts:
 *   - alice (deep.l3.manager)        — creator, org-wide admin role
 *   - super_admin                     — spans every org, should always see
 *   - l4 member (deep.l4.member)      — alice's subordinate in the same dept subtree
 *   - tntb member (tntb.member@authz) — different org, cross-tenant boundary
 *
 * The test asserts EXACTLY what the engine returns. Run once and you have a
 * real, replayable description of "this project is visible to these N users
 * and not those M users".
 *
 * ponytail: one test, real HTTP, no in-memory engine-direct calls. The
 * scenario seeder is reused — no fabricated orgs or users.
 */
class ProjectCreatedByMemberVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private ?int $projectId = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->makeScenario()->run();
        AccessDecision::flushCache();
    }

    public function test_alice_creates_a_project_then_audits_who_can_see_it(): void
    {
        $alice = User::where('email', 'deep.l3.manager@authz.demo')->firstOrFail();
        $aliceDept = Department::where('code', 'AUTHZ2-DEEP-L3')->firstOrFail();
        $l4Member = User::where('email', 'deep.l4.member@authz.demo')->firstOrFail();
        $tntbMember = User::where('email', 'tntb.member@authz.demo')->firstOrFail();
        $superUser = User::where('email', 'super.authz@authz.demo')->firstOrFail();

        // Sanity: alice and her subordinate share ORG A; tntb member sits in ORG B.
        $orgAId = Organization::where('code', 'AUTHZ-CO')->value('id');
        $orgBId = Organization::where('code', 'AUTHZ-TNTB')->value('id');
        $this->assertSame($orgAId, $alice->organization_id);
        $this->assertSame($orgAId, $l4Member->organization_id);
        $this->assertSame($orgBId, $tntbMember->organization_id);

        // 1) Alice creates the project ----------------------------------------
        $create = $this->actingAs($alice, 'sanctum')
            ->postJson('/api/projects', [
                'name' => 'مشروع اختباري لـ Alice',
                'type' => 'development',
                'department_id' => $aliceDept->id,
                'priority' => 'medium',
                'status' => 'draft',
            ]);

        $create->assertStatus(201);
        $this->projectId = $create->json('project.id');
        $this->assertNotNull($this->projectId, 'Project create must return a project id.');
        $this->assertSame($alice->id, $create->json('project.created_by'),
            'created_by must equal the authenticated alice.');

        // 2) Audit: can each user GET /api/projects/{id}? ----------------------
        $report = [];
        foreach ([
            'alice (creator, admin)' => $alice,
            'super_admin' => $superUser,
            'l4 member (alice dept subtree)' => $l4Member,
            'tenant B member (cross-org)' => $tntbMember,
        ] as $label => $user) {
            $report[$label] = $this->canSee($user);
        }

        // The engine's actual answers, pinned:
        $this->assertTrue($report['alice (creator, admin)'],
            'alice must see her own project — owner_floor + admin role.');
        $this->assertTrue($report['super_admin'],
            'super_admin bypasses every gate.');

        // ponytail: l4Member has the flat 'member' Spatie role. RolesAndPermissionsSeeder::seedLegacyTestRoles
        // re-creates that role in non-production with an org-scoped scoped_role_definition whose
        // can_view_all=true. The engine's grantedViaOrgFunctionalRole() bridges the flat 'member'
        // role to that definition, so 'projects.view' (action='view') is granted via the can_view_all
        // flag — *not* via positional/chain visibility. The result is the same here (200), but the
        // reason matters: remove seedLegacyTestRoles() and this user drops to 403.
        $this->assertTrue($report['l4 member (alice dept subtree)'],
            'l4 member sees the project because the test-only member->viewer role bridge grants can_view_all.');

        $this->assertFalse($report['tenant B member (cross-org)'],
            'tntb member is in ORG B — org-isolation gate must 403.');

        // 3) Index visibility: alice's /api/projects MUST list her new project.
        $indexResp = $this->actingAs($alice, 'sanctum')->getJson('/api/projects?per_page=50');
        $indexResp->assertStatus(200);
        $this->assertTrue(
            collect($indexResp->json('data'))->pluck('id')->contains($this->projectId),
            "Alice's /api/projects index must list project #{$this->projectId}."
        );

        // 4) Cross-org user's index MUST NOT list alice's project.
        $tntbIndex = $this->actingAs($tntbMember, 'sanctum')->getJson('/api/projects?per_page=50');
        $tntbIndex->assertStatus(200);
        $this->assertFalse(
            collect($tntbIndex->json('data'))->pluck('id')->contains($this->projectId),
            "Tenant B user's index MUST NOT list project #{$this->projectId} (different org)."
        );
    }

    private function canSee(User $user): bool
    {
        if ($this->projectId === null) {
            throw new \RuntimeException('Project id missing — call create first.');
        }

        $resp = $this->actingAs($user, 'sanctum')
            ->getJson("/api/projects/{$this->projectId}");

        return $resp->status() === 200;
    }

    private function makeScenario(): AuthzTestFixturesScenario
    {
        $cmd = new Command(new ArgvInput([]));
        $cmd->setOutput(new OutputStyle(new ArgvInput([]), new NullOutput));

        return new AuthzTestFixturesScenario($cmd);
    }
}
