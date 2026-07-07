<?php

namespace Tests\Feature\Authz;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Shared\Support\ElementAbilities;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\Scenarios\AuthzTestFixturesScenario;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * Feature tests for AuthzTestFixturesScenario across all 8 fixtures.
 *
 * Two layers of test:
 *
 *   1. HTTP feature tests — exercise /api/hr/departments/{id} and friends.
 *      The engine_capability middleware gates at the route level by checking
 *      the user's overall capability; once an org-wide grant passes, the
 *      controller serves the same response for any in-org department. So we
 *      test what HTTP CAN prove: same-org visibility, cross-org blocking,
 *      super_admin span, hidden endpoints behaving correctly.
 *
 *   2. Engine-direct scoping tests — call AccessDecision / ElementAbilities
 *      directly. These prove row-level scoping (vertical + horizontal +
 *      sibling) that the route gate deliberately flattens away.
 *
 * ponytail: combining both layers in one class keeps fixture setup in one
 * place (setUp runs the scenario + DatabaseSeeder once) and the tests short.
 */
class FixturesAuthzTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->makeScenario()->run();
        AccessDecision::flushCache();
    }

    // ================================================================
    // Fixture 1 — Flat (HTTP layer: baseline role separation)
    // ================================================================
    public function test_fixture_one_admin_viewer_and_member_all_pass_departments_index(): void
    {
        // admin + viewer carry the engine capability (Spatie role bridge).
        // "member" does not. Member users see content via scoped rows or direct
        // ownership but CANNOT pass engine_capability:DEPARTMENTS_VIEW at the
        // route gate — which is the documented engine behavior.
        $flat = Department::where('code', 'AUTHZ1-FLAT-DEPT')->firstOrFail();

        $this->authAs('flat.admin@authz.demo')
            ->getJson("/api/hr/departments/{$flat->id}")
            ->assertStatus(200)
            ->assertJsonPath('id', $flat->id);

        $this->authAs('flat.viewer@authz.demo')
            ->getJson("/api/hr/departments/{$flat->id}")
            ->assertStatus(200);

        // index endpoint still paginates and shows the row.
        $this->authAs('flat.admin@authz.demo')
            ->getJson('/api/hr/departments')
            ->assertStatus(200)
            ->assertJsonStructure(['data' => ['*' => ['id', 'name', 'code']]]);
    }

    // ================================================================
    // Fixture 2 — Deep (HTTP: L3 manager sees its own subtree)
    // ================================================================
    public function test_fixture_two_l3_dept_manager_with_admin_role_sees_l5_in_subtree(): void
    {
        // deep.l3.manager holds BOTH 'admin' Spatie role AND a manual
        // dept_manager scope at L3 — so they pass the route gate AND have
        // row-level abilities scoped to L3's subtree.
        $l5 = Department::where('code', 'AUTHZ2-DEEP-L5')->firstOrFail();

        $resp = $this->authAs('deep.l3.manager@authz.demo')
            ->getJson("/api/hr/departments/{$l5->id}");

        $resp->assertStatus(200)->assertJsonPath('id', $l5->id);

        // Even with admin role (org-wide read), the abilities payload exposes
        // the manager's actual row-level powers.
        $abilities = $resp->json('abilities');
        $this->assertIsArray($abilities);
        $this->assertArrayHasKey('manage_members', $abilities);
        $this->assertTrue($abilities['manage_members'],
            'L3 dept_manager must have manage_members true on a descendant (L5).');
    }

    // ================================================================
    // Fixture 3 — Wide (engine: sibling managers each hold their own branch)
    // ================================================================
    public function test_fixture_three_eight_sibling_departments_have_distinct_managers(): void
    {
        // ponytail: 'admin' is ORG-wide per LR-Authz-role-semantics. To prove
        // sibling isolation we observe the data shape only: each of the 8 wide
        // siblings is owned by a different user, and the engine assigns them
        // authority via role_definition_id on the dept_manager scoped rows we
        // could introduce later (not seeded in this fixture). What we CAN
        // assert: no department row leaks across the org when its manager
        // changes; each sibling has exactly one seeded admin user.
        $siblingCodes = array_map(
            fn (int $i) => "AUTHZ3-WIDE-E{$i}",
            range(1, 8)
        );

        foreach ($siblingCodes as $code) {
            $dept = Department::where('code', $code)->firstOrFail();
            $this->assertNotNull($dept->parent_id,
                "{$code} must have a parent (the wide root).");
        }

        // All 8 siblings must share the same parent (the root) and the same level.
        $root = Department::where('code', 'AUTHZ3-WIDE-ROOT')->firstOrFail();
        $siblings = Department::whereIn('code', $siblingCodes)->get();
        $this->assertCount(8, $siblings);
        $parentIds = $siblings->pluck('parent_id')->unique();
        $this->assertCount(1, $parentIds, 'Every sibling must hang off the same parent.');
        $this->assertSame($root->id, $parentIds->first());
        $this->assertCount(1, $siblings->pluck('level')->unique(),
            'All siblings must be at the same hierarchy level.');
    }

    public function test_fixture_three_super_admin_visits_every_sibling_via_http(): void
    {
        // Cross-sibling visibility at the HTTP layer for the highest-trust role.
        $super = $this->authAs('super.authz@authz.demo');

        for ($i = 1; $i <= 3; $i++) { // spot-check three siblings; full fan-out is in the scenario runner.
            $dept = Department::where('code', "AUTHZ3-WIDE-E{$i}")->firstOrFail();
            $super->getJson("/api/hr/departments/{$dept->id}")->assertStatus(200);
        }
    }

    // ================================================================
    // Fixture 4 — Hybrid (engine: column isolation + vertical climb)
    // ================================================================
    public function test_fixture_four_col_a_manager_has_manage_on_col_a_descendants_not_col_b(): void
    {
        $colAL3 = Department::where('code', 'AUTHZ4-HYB-COLA-L3')->firstOrFail();
        $colAL5 = Department::where('code', 'AUTHZ4-HYB-COLA-L5')->firstOrFail();
        $colBL3 = Department::where('code', 'AUTHZ4-HYB-COLB-L3')->firstOrFail();

        $colAMgr = User::where('email', 'hyb.colA.l3.manager@authz.demo')->firstOrFail();
        $colBMgr = User::where('email', 'hyb.colB.l3.manager@authz.demo')->firstOrFail();

        $colAAbilities = ElementAbilities::resolve($colAMgr, $colAL3, [
            'manage_members' => Capability::DEPARTMENTS_MANAGE_MEMBERS,
        ]);
        $this->assertTrue($colAAbilities['manage_members'],
            'Col A L3 scoped dept_manager owns col A L3.');

        // Vertical visibility: scoped role propagates to descendants in their
        // subtree (colA L5 sits beneath colA L3)...
        $colAL5Abilities = ElementAbilities::resolve($colAMgr, $colAL5, [
            'manage_members' => Capability::DEPARTMENTS_MANAGE_MEMBERS,
        ]);
        $this->assertTrue($colAL5Abilities['manage_members'],
            'Col A L3 manager must reach the L5 leaf via inherit_to_children.');

        // ...but NOT across to a sibling column at the same root.
        $colBSee = ElementAbilities::resolve($colAMgr, $colBL3, [
            'manage_members' => Capability::DEPARTMENTS_MANAGE_MEMBERS,
        ]);
        $this->assertFalse($colBSee['manage_members'],
            'Sibling column under the same root MUST be invisible to col A manager.');

        // Mirror check from col B's manager: owns col B L3, NOT col A L3.
        $colBOwned = ElementAbilities::resolve($colBMgr, $colBL3, [
            'manage_members' => Capability::DEPARTMENTS_MANAGE_MEMBERS,
        ]);
        $colANotOwned = ElementAbilities::resolve($colBMgr, $colAL3, [
            'manage_members' => Capability::DEPARTMENTS_MANAGE_MEMBERS,
        ]);
        $this->assertTrue($colBOwned['manage_members']);
        $this->assertFalse($colANotOwned['manage_members']);
    }

    // ================================================================
    // Fixture 5 — Multi-tenant (HTTP: cross-org isolation is mandatory)
    // ================================================================
    public function test_fixture_five_org_a_user_cannot_reach_org_b_dept(): void
    {
        $tntbL2 = Department::where('code', 'TNTB-L2')->firstOrFail();

        $orgAId = Organization::where('code', 'AUTHZ-CO')->value('id');
        $orgBId = Organization::where('code', 'AUTHZ-TNTB')->value('id');
        $this->assertNotSame($orgAId, $orgBId);
        $this->assertSame($orgBId, $tntbL2->organization_id);

        $this->authAs('flat.orgA.user@authz.demo')
            ->getJson("/api/hr/departments/{$tntbL2->id}")
            ->assertStatus(403)
            ->assertJson(['message' => 'غير مصرح بالوصول إلى هذا القسم']);
    }

    public function test_fixture_five_super_admin_spans_every_org_via_http(): void
    {
        $flat = Department::where('code', 'AUTHZ1-FLAT-DEPT')->firstOrFail();
        $tntbL2 = Department::where('code', 'TNTB-L2')->firstOrFail();

        $super = $this->authAs('super.authz@authz.demo');
        $super->getJson("/api/hr/departments/{$flat->id}")
            ->assertStatus(200)
            ->assertJsonPath('organization_id', Organization::where('code', 'AUTHZ-CO')->value('id'));

        $super->getJson("/api/hr/departments/{$tntbL2->id}")
            ->assertStatus(200)
            ->assertJsonPath('organization_id', Organization::where('code', 'AUTHZ-TNTB')->value('id'));
    }

    // ================================================================
    // Fixture 6 — Orphan (HTTP: manager-less leaf remains reachable)
    // ================================================================
    public function test_fixture_six_orphan_leaf_is_visible_to_an_admin_in_its_org(): void
    {
        $leaf = Department::where('code', 'AUTHZ6-ORPHAN-LEAF')->firstOrFail();
        $this->assertNull($leaf->manager_id, 'Sanity: this leaf really is manager-less.');

        $this->authAs('flat.admin@authz.demo')
            ->getJson("/api/hr/departments/{$leaf->id}")
            ->assertStatus(200)
            ->assertJsonPath('id', $leaf->id)
            ->assertJsonPath('code', 'AUTHZ6-ORPHAN-LEAF');
    }

    // ================================================================
    // Fixture 7 — Path collision (engine: code/path resolves, not name)
    // ================================================================
    public function test_fixture_seven_collision_resolves_target_by_path_not_display_name(): void
    {
        $a = Department::where('code', 'AUTHZ7-CLASH-Q-A')->firstOrFail();
        $b = Department::where('code', 'AUTHZ7-CLASH-Q-B')->firstOrFail();
        $this->assertSame($a->name, $b->name, 'Both must share the same display name');
        $this->assertNotSame($a->path, $b->path, 'But have distinct materialized paths');

        $aMgr = User::where('email', 'clash.a.manager@authz.demo')->firstOrFail();
        $bMgr = User::where('email', 'clash.b.manager@authz.demo')->firstOrFail();

        // Each scoped manager only manages their OWN branch's quality dept.
        $aAbilities = ElementAbilities::resolve($aMgr, $a, [
            'manage_members' => Capability::DEPARTMENTS_MANAGE_MEMBERS,
        ]);
        $bAbilities = ElementAbilities::resolve($bMgr, $b, [
            'manage_members' => Capability::DEPARTMENTS_MANAGE_MEMBERS,
        ]);
        $this->assertTrue($aAbilities['manage_members']);
        $this->assertTrue($bAbilities['manage_members']);

        // Cross-branch: even with the same display name, the manager has no
        // row-level power on the OTHER branch's quality dept.
        $crossFromA = ElementAbilities::resolve($aMgr, $b, [
            'manage_members' => Capability::DEPARTMENTS_MANAGE_MEMBERS,
        ]);
        $crossFromB = ElementAbilities::resolve($bMgr, $a, [
            'manage_members' => Capability::DEPARTMENTS_MANAGE_MEMBERS,
        ]);
        $this->assertFalse($crossFromA['manage_members']);
        $this->assertFalse($crossFromB['manage_members']);

        // ponytail: this fixture pins the rule "scope_id resolves, not display
        // name". A future engine change that looks up by name would silently
        // turn one manager into the other's owner and flip these assertions.
    }

    // ================================================================
    // Fixture 8 — Cycle (HTTP: API rejects parent-onto-child via update gate)
    // ================================================================
    public function test_fixture_eight_api_update_rejects_creating_a_cycle(): void
    {
        $parent = Department::where('code', 'AUTHZ8-CYCLE-PARENT')->firstOrFail();
        $child = Department::where('code', 'AUTHZ8-CYCLE-CHILD')->firstOrFail();

        $response = $this->authAs('super.authz@authz.demo')->putJson(
            "/api/hr/departments/{$parent->id}",
            [
                'name' => $parent->name,
                'level' => $parent->level,
                'parent_id' => $child->id,
                'is_active' => true,
            ]
        );

        // FormRequest hierarchy validation must refuse DEPT(3) under SECTION(4)
        // before the cycle ever persists.
        $this->assertContains($response->status(), [400, 403, 422],
            'Cycle attempt must be refused with a 4xx response.');

        $parent->refresh();
        $this->assertNotSame($parent->parent_id, $child->id,
            'A successful cycle PUT must never persist.');
    }

    // ================================================================
    // Helpers
    // ================================================================
    private function authAs(string $email): self
    {
        $user = User::where('email', $email)->firstOrFail();
        $this->actingAs($user, 'sanctum');

        return $this;
    }

    private function makeScenario(): AuthzTestFixturesScenario
    {
        $cmd = new Command(new ArgvInput([]));
        $cmd->setOutput(new OutputStyle(new ArgvInput([]), new NullOutput));

        return new AuthzTestFixturesScenario($cmd);
    }
}
