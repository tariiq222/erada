<?php

namespace Tests\Feature\Authz;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Authorization\Models\AuthorizationRoleAssignment;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\Scenarios\AuthzTestFixturesScenario;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

/**
 * Smoke test for AuthzTestFixturesScenario.
 *
 * Runs the scenario against the test database and exercises a few targeted
 * expectations per fixture. The point is to keep the fixture shape pinned
 * and to verify the seed run is idempotent.
 *
 * Test DB target (per LR-test-db-targeting): docker `app php artisan test` will
 * hit the dev database; override via APP_BASE_PATH + DB_HOST/DB_PORT/DB_DATABASE
 * env if running outside docker.
 */
class AuthzTestFixturesScenarioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        AccessDecision::flushCache();
    }

    public function test_scenario_builds_eight_fixtures_and_idempotent_rerun(): void
    {
        $scenario = $this->makeScenario();
        $scenario->run();

        $firstRunCounts = [
            'orgs' => Organization::whereIn('code', ['AUTHZ-CO', 'AUTHZ-TNTB'])->count(),
            'depts' => Department::where(function ($q) {
                $q->where('code', 'like', 'AUTHZ%')->orWhere('code', 'like', 'TNTB-%');
            })->count(),
            'users' => User::where('email', 'like', '%@authz.demo')->count(),
        ];

        $this->assertSame(2, $firstRunCounts['orgs'], 'Both Authz organizations must exist');
        $this->assertGreaterThanOrEqual(20, $firstRunCounts['depts'], 'Eight fixtures must produce at least 20 depts');
        $this->assertGreaterThanOrEqual(15, $firstRunCounts['users'], 'Every fixture must seed at least one user');

        // Re-run and re-count — counts must NOT grow on rerun (idempotent).
        $scenario->run();

        $this->assertSame($firstRunCounts['orgs'], Organization::whereIn('code', ['AUTHZ-CO', 'AUTHZ-TNTB'])->count());
        $this->assertSame($firstRunCounts['depts'], Department::where(function ($q) {
            $q->where('code', 'like', 'AUTHZ%')->orWhere('code', 'like', 'TNTB-%');
        })->count());
    }

    public function test_fixture_one_flat_assigns_admin_role(): void
    {
        $this->makeScenario()->run();

        $flat = User::where('email', 'flat.admin@authz.demo')->firstOrFail();
        $this->assertContains('admin', $flat->canonicalRoleNames());

        $viewer = User::where('email', 'flat.viewer@authz.demo')->firstOrFail();
        $this->assertContains('viewer', $viewer->canonicalRoleNames());
    }

    public function test_fixture_two_deep_attaches_a_dept_scoped_manager(): void
    {
        $this->makeScenario()->run();

        $l3 = Department::where('code', 'AUTHZ2-DEEP-L3')->firstOrFail();
        $mgr = User::where('email', 'deep.l3.manager@authz.demo')->firstOrFail();

        $scoped = AuthorizationRoleAssignment::query()
            ->where('user_id', $mgr->id)
            ->where('scope_type', AuthorizationRoleAssignment::SCOPE_DEPARTMENT)
            ->where('scope_id', $l3->id)
            ->whereHas('role', fn ($query) => $query->where('name', 'dept_manager'))
            ->first();

        $this->assertNotNull($scoped, 'L3 manager must hold a canonical department-scoped dept_manager assignment');
        $this->assertSame('manual', $scoped->source);
    }

    public function test_fixture_five_org_isolation_blocks_cross_tenant_visibility(): void
    {
        $this->makeScenario()->run();

        $tntb = User::where('email', 'tntb.member@authz.demo')->firstOrFail();
        $tntbDept = Department::where('code', 'AUTHZ1-FLAT-DEPT')->firstOrFail();
        $tntbDeptInB = Department::where('code', 'TNTB-L2')->firstOrFail();

        // An ORG A user must NOT be told they can view anything in ORG B.
        $userInA = User::where('email', 'flat.orgA.user@authz.demo')->firstOrFail();
        $this->assertNotSame($userInA->organization_id, $tntbDeptInB->organization_id);

        // Direct-membership assertion: a member's capabilities are scoped to their org/dept only.
        $this->assertFalse(AccessDecision::can($userInA, Capability::DEPARTMENTS_VIEW, $tntbDeptInB));
        $this->assertTrue(AccessDecision::can($userInA, Capability::DEPARTMENTS_VIEW, $tntbDept));
        $this->assertTrue(AccessDecision::can($tntb, Capability::DEPARTMENTS_VIEW, $tntbDeptInB));
    }

    public function test_fixture_six_orphan_leaf_has_no_manager_id(): void
    {
        $this->makeScenario()->run();

        $leaf = Department::where('code', 'AUTHZ6-ORPHAN-LEAF')->firstOrFail();
        $this->assertNull($leaf->manager_id);

        $child = User::where('email', 'orphan.member@authz.demo')->firstOrFail();
        $this->assertSame($leaf->id, $child->department_id);
    }

    public function test_fixture_seven_path_collision_keeps_two_distinct_dept_rows(): void
    {
        $this->makeScenario()->run();

        $a = Department::where('code', 'AUTHZ7-CLASH-Q-A')->firstOrFail();
        $b = Department::where('code', 'AUTHZ7-CLASH-Q-B')->firstOrFail();

        $this->assertSame($a->name, $b->name, 'Both must share the display name');
        $this->assertNotSame($a->id, $b->id, 'But be different department rows');
        $this->assertNotSame($a->parent_id, $b->parent_id);

        // Their paths must be distinct (the materialized column is what scopes visibility).
        $this->assertNotSame($a->path, $b->path);
    }

    public function test_fixture_eight_cycle_attempt_via_model_save_does_not_silently_break_tree(): void
    {
        $this->makeScenario()->run();

        $parent = Department::where('code', 'AUTHZ8-CYCLE-PARENT')->firstOrFail();
        $child = Department::where('code', 'AUTHZ8-CYCLE-CHILD')->firstOrFail();

        // ponytail: RefreshDatabase wraps this test in a transaction. A direct
        // try/catch around $parent->update() poisons that outer txn with Postgres'
        // "current transaction is aborted" the moment the update raises.
        // Wrapping the attempt in DB::transaction() turns it into a savepoint:
        // the inner attempt rolls back on failure, the outer txn stays usable,
        // and Eloquent refresh() works after the catch.
        $parentSnapshot = (int) $parent->parent_id;
        $childSnapshot = (int) $child->parent_id;

        try {
            DB::transaction(function () use ($parent, $child) {
                $parent->update(['parent_id' => $child->id]);
            });
        } catch (\Throwable $e) {
            // expected; engine may reject via observer / guard service.
        }

        $parent->refresh();
        $child->refresh();

        $this->assertSame($parentSnapshot, (int) $parent->parent_id,
            'A parent reparented onto its child must NOT persist a cycle.');
        $this->assertSame($childSnapshot, (int) $child->parent_id,
            'The child row must remain unchanged.');
    }

    public function test_super_admin_seed_user_exists_and_spans_orgs(): void
    {
        $this->makeScenario()->run();

        $super = User::where('email', 'super.authz@authz.demo')->firstOrFail();
        $this->assertContains('super_admin', $super->canonicalRoleNames());
    }

    /**
     * Build a scenario instance with a bound Command facade so artisan output
     * is suppressed in test runs. Tests assert on data, not on stdout.
     */
    private function makeScenario(): AuthzTestFixturesScenario
    {
        $cmd = new Command(new ArgvInput([]));
        // ponytail: assign a NullOutput via OutputStyle so info()/line() don't
        // dereference a null $this->output during a phpunit run.
        $cmd->setOutput(new OutputStyle(new ArgvInput([]), new NullOutput));

        return new AuthzTestFixturesScenario($cmd);
    }
}
