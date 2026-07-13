<?php

namespace Database\Seeders\Scenarios;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Authorization\Models\AuthorizationRoleAssignment;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * AuthzTestFixturesScenario
 *
 * Eight deliberately diverse department fixtures for exercising the unified
 * authorization engine. Each fixture targets a specific behavior:
 *
 *   1. Flat             baseline role separation (super_admin / admin / viewer / member)
 *   2. Deep             5-level vertical-visibility chain
 *   3. Wide             sibling isolation across many root children
 *   4. Hybrid           mixed depth + breadth (two unbalanced columns)
 *   5. Multi-Tenant     cross-org isolation (second organization)
 *   6. Orphan           manager-less leaf edge case
 *   7. Path Collision   two depts sharing the same display name in different branches
 *   8. Cycle attempt    negative test target — the seeder creates a safe reparentable
 *                       node; the cycle itself is asserted by the test class
 *
 * All fixtures live under one ORG_A (Authz Co.) for convenience, except fixture 5
 * which gets its own ORG_B (Tenant B) to make org isolation testable end-to-end.
 *
 * Usage:
 *   php artisan db:seed --class=DemoDataSeeder -- authz
 *
 * Reads from DEMO_SCENARIO env if no CLI argument is given:
 *   DEMO_SCENARIO=authz php artisan db:seed --class=DemoDataSeeder
 *
 * ponytail: the seeder is intentionally verbose and prints an ASCII tree per
 * fixture. Cost is bounded by fixture count (8) and runs once per db:seed.
 */
class AuthzTestFixturesScenario
{
    /** Department-id code map kept while building so users can join by code. */
    private array $deptCodes = [];

    /** All departments created in this run. */
    public array $departments = [];

    /** All users created in this run. */
    public array $users = [];

    /** Organizations created (ORG_A + ORG_B). */
    public array $organizations = [];

    public function __construct(private readonly Command $command) {}

    public function run(): void
    {
        $this->command->info('[Authz] Cleaning previous fixture data...');
        $this->cleanPreviousFixtureData();

        $this->command->info('[Authz] Verifying canonical roles...');
        $this->ensureCanonicalRoles();

        $this->command->info('[Authz] Creating orgs...');
        $this->createOrganizations();

        $this->command->info('[Authz] Fixture 1/8: Flat...');
        $this->buildFlatFixture();

        $this->command->info('[Authz] Fixture 2/8: Deep (5 levels)...');
        $this->buildDeepFixture();

        $this->command->info('[Authz] Fixture 3/8: Wide (8 siblings)...');
        $this->buildWideFixture();

        $this->command->info('[Authz] Fixture 4/8: Hybrid (unbalanced columns)...');
        $this->buildHybridFixture();

        $this->command->info('[Authz] Fixture 5/8: Multi-Tenant (org isolation)...');
        $this->buildMultiTenantFixture();

        $this->command->info('[Authz] Fixture 6/8: Orphan (no-manager leaf)...');
        $this->buildOrphanFixture();

        $this->command->info('[Authz] Fixture 7/8: Path Collision (same name, two branches)...');
        $this->buildPathCollisionFixture();

        $this->command->info('[Authz] Fixture 8/8: Cycle test target...');
        $this->buildCycleFixture();

        // Global super_admin is set up once per run for cross-org spanning checks.
        $this->ensureSuperAdmin();

        $this->command->info('');
        $this->command->info('Authz fixtures complete.');
        $this->command->info(sprintf('  Organizations : %d', count($this->organizations)));
        $this->command->info(sprintf('  Departments   : %d', count($this->departments)));
        $this->command->info(sprintf('  Users         : %d', count($this->users)));
        $this->command->info('');
        $this->command->info('Login: admin@admin.com / password  (global super_admin)');
        $this->command->info('Per-fixture logins follow the pattern: <role>.authz@demo.com / password');
    }

    /**
     * Drop only data created by previous runs of this scenario. We never touch
     * core roles/permissions/system settings or unrelated demo data.
     */
    private function cleanPreviousFixtureData(): void
    {
        DB::statement('SET session_replication_role = replica');

        $tablesToWipe = [
            'project_risks', 'project_expenses', 'stakeholders',
            'milestone_deliverables', 'milestones', 'tasks', 'comments',
            'attachments', 'project_activities', 'projects', 'programs',
            'portfolios', 'decisions', 'reviews', 'survey_responses',
            'survey_invitations', 'survey_sections', 'survey_fields',
            'surveys', 'data_imports', 'data_mappings', 'activity_logs',
        ];

        foreach ($tablesToWipe as $table) {
            if (DB::getSchemaBuilder()->hasTable($table)) {
                DB::table($table)->delete();
            }
        }

        if (DB::getSchemaBuilder()->hasTable('departments')) {
            DB::table('departments')
                ->where('code', 'like', 'AUTHZ%')
                ->orWhere('code', 'like', 'TNTB-%')
                ->delete();
        }

        if (DB::getSchemaBuilder()->hasTable('users')) {
            DB::table('users')
                ->where('email', 'like', '%@authz.demo')
                ->delete();
        }

        if (DB::getSchemaBuilder()->hasTable('organizations')) {
            DB::table('organizations')
                ->whereIn('code', ['AUTHZ-CO', 'AUTHZ-TNTB'])
                ->delete();
        }

        DB::statement('SET session_replication_role = origin');
        AccessDecision::flushCache();
    }

    private function ensureCanonicalRoles(): void
    {
        $required = ['super_admin', 'admin', 'viewer', 'dept_manager'];
        $existing = AuthorizationRole::query()->whereIn('name', $required)->pluck('name')->all();
        $missing = array_values(array_diff($required, $existing));

        if ($missing !== []) {
            throw new \RuntimeException('Missing canonical authorization roles: '.implode(', ', $missing));
        }
    }

    private function createOrganizations(): void
    {
        $this->organizations['A'] = Organization::create([
            'name' => 'Authz Test Co.',
            'code' => 'AUTHZ-CO',
            'description' => 'منظمة اختبار محرك الصلاحيات — تضم الفيكسترات 1–4 و 6–8',
            'is_active' => true,
        ]);

        $this->organizations['B'] = Organization::create([
            'name' => 'Tenant B',
            'code' => 'AUTHZ-TNTB',
            'description' => 'مستأجر منفصل لاختبار عزل المؤسسات (fixture 5)',
            'is_active' => true,
        ]);
    }

    // ================================================================
    // Fixture 1 — Flat
    //   Org A
    //   └── AUTHZ1-FLAT-DEPT  (Operations)
    //       └── 5 users (1 super_admin, 1 admin, 1 viewer, 2 members)
    //
    // Tests: baseline role separation; visibility/can() inside a single dept.
    // ================================================================
    private function buildFlatFixture(): void
    {
        $orgId = $this->organizations['A']->id;

        $root = $this->mkDept('AUTHZ1-FLAT-DEPT', 'العمليات (Flat)', Department::LEVEL_TOP_MANAGEMENT, null, $orgId);

        $this->mkUser('flat.admin@authz.demo', 'مدير Flat', $root->id, 'admin', $orgId);
        $this->mkUser('flat.viewer@authz.demo', 'مشاهد Flat', $root->id, 'viewer', $orgId);
        $this->mkUser('flat.member1@authz.demo', 'عضو 1', $root->id, 'member', $orgId);
        $this->mkUser('flat.member2@authz.demo', 'عضو 2', $root->id, 'member', $orgId);

        $this->printTree($root, '1. Flat');
    }

    // ================================================================
    // Fixture 2 — Deep (5 levels)
    //   Org A
    //   └── AUTHZ2-DEEP-L1 (CEO Office)
    //       └── AUTHZ2-DEEP-L2 (Executive)
    //           └── AUTHZ2-DEEP-L3 (Operations)
    //               └── AUTHZ2-DEEP-L4 (IT Section)
    //                   └── AUTHZ2-DEEP-L5 (Infra Unit)
    //
    // Tests: vertical oversight chain; whether L2 sees L5, L3 sees L5, etc.
    // ================================================================
    private function buildDeepFixture(): void
    {
        $orgId = $this->organizations['A']->id;
        $l1 = $this->mkDept('AUTHZ2-DEEP-L1', 'مكتب CEO', Department::LEVEL_TOP_MANAGEMENT, null, $orgId);
        $l2 = $this->mkDept('AUTHZ2-DEEP-L2', 'إدارة تنفيذية', Department::LEVEL_EXECUTIVE, $l1->id, $orgId);
        $l3 = $this->mkDept('AUTHZ2-DEEP-L3', 'إدارة العمليات', Department::LEVEL_DEPARTMENT, $l2->id, $orgId);
        $l4 = $this->mkDept('AUTHZ2-DEEP-L4', 'قسم تقنية المعلومات', Department::LEVEL_SECTION, $l3->id, $orgId);
        $l5 = $this->mkDept('AUTHZ2-DEEP-L5', 'وحدة البنية التحتية', Department::LEVEL_UNIT, $l4->id, $orgId);

        // Manager at L3 should see L4 and L5 thanks to vertical visibility.
        $l3Mgr = $this->mkUser('deep.l3.manager@authz.demo', 'مدير L3', $l3->id, 'admin', $orgId);
        $this->mkUser('deep.l4.member@authz.demo', 'عضو L4', $l4->id, 'member', $orgId);
        $this->mkUser('deep.l5.member@authz.demo', 'عضو L5', $l5->id, 'member', $orgId);

        // Grant the L3 admin user an explicit dept-scoped dept_manager role at L3
        // so we can test the engine's scoped-role path on a manual source.
        $this->grantDeptManager($l3Mgr, $l3, source: 'manual');

        $this->printTree($l1, '2. Deep');
    }

    // ================================================================
    // Fixture 3 — Wide (8 sibling roots)
    //   Org A
    //   └── AUTHZ3-WIDE-ROOT
    //       ├── AUTHZ3-WIDE-E1 .. E8  (8 EXEC siblings)
    //       └── member at each
    //
    // Tests: horizontal isolation between EXEC siblings; no cross-sibling leakage.
    // ================================================================
    private function buildWideFixture(): void
    {
        $orgId = $this->organizations['A']->id;
        $root = $this->mkDept('AUTHZ3-WIDE-ROOT', 'الإدارة العامة (Wide)', Department::LEVEL_TOP_MANAGEMENT, null, $orgId);

        for ($i = 1; $i <= 8; $i++) {
            $sib = $this->mkDept("AUTHZ3-WIDE-E{$i}", "إدارة {$i} (شقيقة)", Department::LEVEL_EXECUTIVE, $root->id, $orgId);
            $this->mkUser("wide.e{$i}.admin@authz.demo", "مدير إدارة {$i}", $sib->id, 'admin', $orgId);
        }

        $this->printTree($root, '3. Wide');
    }

    // ================================================================
    // Fixture 4 — Hybrid (two unbalanced columns under shared executive)
    //   Org A
    //   └── AUTHZ4-HYB-L1
    //       ├── AUTHZ4-HYB-COLA-L2
    //       │   ├── AUTHZ4-HYB-COLA-L3
    //       │   │   ├── AUTHZ4-HYB-COLA-L4A
    //       │   │   └── AUTHZ4-HYB-COLA-L4B
    //       │   │       └── AUTHZ4-HYB-COLA-L5 (leaf)
    //       │   └── AUTHZ4-HYB-COLB-L3 (leaf)
    //       └── AUTHZ4-HYB-COLB-L2 (EXEC, single dept)
    //           └── AUTHZ4-HYB-COLB-L3 (DEPT, leaf)
    //
    // Tests: depth asymmetry within the same org; mixed visibility boundaries.
    // ================================================================
    private function buildHybridFixture(): void
    {
        $orgId = $this->organizations['A']->id;
        $l1 = $this->mkDept('AUTHZ4-HYB-L1', 'إدارة تنفيذية مشتركة', Department::LEVEL_TOP_MANAGEMENT, null, $orgId);

        $colA = $this->mkDept('AUTHZ4-HYB-COLA-L2', 'عمود A', Department::LEVEL_EXECUTIVE, $l1->id, $orgId);
        $colB = $this->mkDept('AUTHZ4-HYB-COLB-L2', 'عمود B', Department::LEVEL_EXECUTIVE, $l1->id, $orgId);

        $colAL3 = $this->mkDept('AUTHZ4-HYB-COLA-L3', 'عمود A — إدارة', Department::LEVEL_DEPARTMENT, $colA->id, $orgId);
        $colBL3 = $this->mkDept('AUTHZ4-HYB-COLB-L3', 'عمود B — إدارة', Department::LEVEL_DEPARTMENT, $colB->id, $orgId);

        $colAL4A = $this->mkDept('AUTHZ4-HYB-COLA-L4A', 'عمود A — قسم 1', Department::LEVEL_SECTION, $colAL3->id, $orgId);
        $colAL4B = $this->mkDept('AUTHZ4-HYB-COLA-L4B', 'عمود A — قسم 2', Department::LEVEL_SECTION, $colAL3->id, $orgId);
        $colAL5 = $this->mkDept('AUTHZ4-HYB-COLA-L5', 'عمود A — وحدة', Department::LEVEL_UNIT, $colAL4B->id, $orgId);

        // ponytail: scoped managers (NO org-wide admin role) so the engine's
        // vertical-vs-horizontal resolution is what binds the visibility —
        // not the flat admin role which would short-circuit everything.
        $colAUser = $this->mkUser('hyb.colA.l3.manager@authz.demo', 'مدير عمود A L3', $colAL3->id, null, $orgId);
        $this->grantDeptManager($colAUser, $colAL3);

        $this->mkUser('hyb.colA.l5.member@authz.demo', 'عضو L5', $colAL5->id, 'member', $orgId);

        $colBUser = $this->mkUser('hyb.colB.l3.manager@authz.demo', 'مدير عمود B L3', $colBL3->id, null, $orgId);
        $this->grantDeptManager($colBUser, $colBL3);

        $this->printTree($l1, '4. Hybrid');
    }

    // ================================================================
    // Fixture 5 — Multi-Tenant (cross-org isolation)
    //   ORG A (Authz Co.) AND ORG B (Tenant B) — separate top-level trees.
    //   Tenant B gets a small tree to prove ORG A users do not see ORG B data.
    // ================================================================
    private function buildMultiTenantFixture(): void
    {
        $orgA = $this->organizations['A']->id;
        $orgB = $this->organizations['B']->id;

        // Tenant B standalone tree.
        $tntbRoot = $this->mkDept('TNTB-L1', 'إدارة Tenant B', Department::LEVEL_TOP_MANAGEMENT, null, $orgB);
        $tntbL2 = $this->mkDept('TNTB-L2', 'قسم عمليات Tenant B', Department::LEVEL_DEPARTMENT, $tntbRoot->id, $orgB);

        $this->mkUser('tntb.admin@authz.demo', 'مدير Tenant B', $tntbRoot->id, 'admin', $orgB);
        $this->mkUser('tntb.member@authz.demo', 'عضو Tenant B', $tntbL2->id, 'member', $orgB);

        // One user in ORG A who must NOT see TNTB-L2:
        $this->mkUser('flat.orgA.user@authz.demo', 'عضو ORG A', $this->deptCode('AUTHZ1-FLAT-DEPT'), 'member', $orgA);

        $this->command->warn(sprintf(
            '   [Tenant] ORG A id=%d, ORG B id=%d  (cross-org membership is the failure mode)',
            $orgA, $orgB
        ));
    }

    // ================================================================
    // Fixture 6 — Orphan (manager-less leaf)
    //   Org A
    //   └── AUTHZ6-ORPHAN-ROOT
    //       └── AUTHZ6-ORPHAN-LEAF  (no manager_id)
    //
    // Tests: how the engine handles a node with no department_manager.
    // ================================================================
    private function buildOrphanFixture(): void
    {
        $orgId = $this->organizations['A']->id;
        $root = $this->mkDept('AUTHZ6-ORPHAN-ROOT', 'إدارة نشطة', Department::LEVEL_TOP_MANAGEMENT, null, $orgId);
        $leaf = $this->mkDept('AUTHZ6-ORPHAN-LEAF', 'وحدة قديمة (بلا مدير)', Department::LEVEL_UNIT, $root->id, $orgId);

        // One member attached to the orphan leaf — they should still resolve access via direct membership.
        $this->mkUser('orphan.member@authz.demo', 'عضو يتيم', $leaf->id, 'member', $orgId);

        // Intentionally NO dept_manager role assignment for the leaf, and no manager_id on the leaf.
        $this->printTree($root, '6. Orphan');
    }

    // ================================================================
    // Fixture 7 — Path Collision (two branches both contain "قسم الجودة")
    //   Org A
    //   └── AUTHZ7-COLLISION-ROOT
    //       ├── AUTHZ7-COLLISION-A   (branch A)
    //       │   └── both named "قسم الجودة" — code AUTHZ7-CLASH-Q-A
    //       └── AUTHZ7-COLLISION-B   (branch B)
    //           └── both named "قسم الجودة" — code AUTHZ7-CLASH-Q-B
    //
    // Tests: code-vs-name resolution; rename doesn't move rights.
    // ================================================================
    private function buildPathCollisionFixture(): void
    {
        $orgId = $this->organizations['A']->id;
        $root = $this->mkDept('AUTHZ7-COLLISION-ROOT', 'الإدارة', Department::LEVEL_TOP_MANAGEMENT, null, $orgId);

        $branchA = $this->mkDept('AUTHZ7-COLLISION-A', 'فرع شمال', Department::LEVEL_DEPARTMENT, $root->id, $orgId);
        $branchB = $this->mkDept('AUTHZ7-COLLISION-B', 'فرع جنوب', Department::LEVEL_DEPARTMENT, $root->id, $orgId);

        // Same display name, different code.
        $qaInA = $this->mkDept('AUTHZ7-CLASH-Q-A', 'قسم الجودة', Department::LEVEL_SECTION, $branchA->id, $orgId);
        $qaInB = $this->mkDept('AUTHZ7-CLASH-Q-B', 'قسم الجودة', Department::LEVEL_SECTION, $branchB->id, $orgId);

        // ponytail: scoped-only managers (no global admin role) so the engine
        // resolves visibility strictly through the materialized-path membership.
        $aMgr = $this->mkUser('clash.a.manager@authz.demo', 'مدير جودة A', $qaInA->id, null, $orgId);
        $this->grantDeptManager($aMgr, $qaInA);

        $bMgr = $this->mkUser('clash.b.manager@authz.demo', 'مدير جودة B', $qaInB->id, null, $orgId);
        $this->grantDeptManager($bMgr, $qaInB);

        $this->printTree($root, '7. Path Collision');
    }

    // ================================================================
    // Fixture 8 — Cycle test target
    //   Org A
    //   └── AUTHZ8-CYCLE-PARENT
    //       └── AUTHZ8-CYCLE-CHILD
    //
    // Seeder produces a normal parent/child. The test attempts to set
    // AUTHZ8-CYCLE-PARENT.parent_id = AUTHZ8-CYCLE-CHILD.id and asserts the
    // system rejects it (FK, model guard, or service guard, whichever exists).
    // ================================================================
    private function buildCycleFixture(): void
    {
        $orgId = $this->organizations['A']->id;
        $parent = $this->mkDept('AUTHZ8-CYCLE-PARENT', 'قسم الأب', Department::LEVEL_DEPARTMENT, null, $orgId);
        $child = $this->mkDept('AUTHZ8-CYCLE-CHILD', 'قسم الابن', Department::LEVEL_SECTION, $parent->id, $orgId);

        $this->mkUser('cycle.parent.manager@authz.demo', 'مدير الأب', $parent->id, 'admin', $orgId);
        $this->mkUser('cycle.child.manager@authz.demo', 'مدير الابن', $child->id, 'admin', $orgId);

        // Track both for the test to mutate.
        $this->deptCodes['AUTHZ8-CYCLE-PARENT_FRESH_ID'] = $parent->id;
        $this->deptCodes['AUTHZ8-CYCLE-CHILD_FRESH_ID'] = $child->id;

        $this->printTree($parent, '8. Cycle');
    }

    /**
     * Create a department. The materialized path is auto-populated by
     * DepartmentObserver; we just insert and let the observer do its work.
     */
    private function mkDept(string $code, string $name, int $level, ?int $parentId, int $orgId): Department
    {
        // Re-use by code when present so a re-run is idempotent.
        $existing = Department::withTrashed()->where('code', $code)->first();
        if ($existing) {
            $this->departments[] = $existing;
            $this->deptCodes[$code] = $existing->id;

            return $existing;
        }

        $dept = Department::create([
            'code' => $code,
            'name' => $name,
            'level' => $level,
            'parent_id' => $parentId,
            'organization_id' => $orgId,
            'is_active' => true,
        ]);

        $this->departments[] = $dept;
        $this->deptCodes[$code] = $dept->id;

        return $dept;
    }

    private function mkUser(string $email, string $name, int $deptId, ?string $flatRole, int $orgId): User
    {
        $existing = User::withTrashed()->where('email', $email)->first();
        if ($existing) {
            $existing->update([
                'department_id' => $deptId,
                'organization_id' => $orgId,
                'is_active' => true,
                'deleted_at' => null,
            ]);
        } else {
            $existing = User::create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make('password'),
                'department_id' => $deptId,
                'organization_id' => $orgId,
                'is_active' => true,
            ]);
        }

        if ($flatRole !== null) {
            $canonicalRoleName = in_array($flatRole, ['project_manager', 'member'], true) ? 'viewer' : $flatRole;
            $scopeType = $canonicalRoleName === 'super_admin' ? AuthorizationRoleAssignment::SCOPE_ALL : AuthorizationRoleAssignment::SCOPE_ORGANIZATION;
            $this->assignCanonicalRole(
                user: $existing,
                roleName: $canonicalRoleName,
                scopeType: $scopeType,
                scopeId: $scopeType === AuthorizationRoleAssignment::SCOPE_ALL ? null : $orgId,
                organizationId: $orgId,
                inheritToChildren: true,
            );
        }

        $this->users[] = $existing;

        return $existing;
    }

    /** Grant an explicit canonical department-manager assignment. */
    private function grantDeptManager(User $user, Department $dept, string $source = 'manual'): void
    {
        $this->assignCanonicalRole(
            user: $user,
            roleName: 'dept_manager',
            scopeType: AuthorizationRoleAssignment::SCOPE_DEPARTMENT,
            scopeId: $dept->id,
            organizationId: $dept->organization_id,
            inheritToChildren: true,
            source: $source,
        );
    }

    private function ensureSuperAdmin(): void
    {
        // Pin a single super_admin we can login as from the test class.
        $email = 'super.authz@authz.demo';
        $deptId = $this->deptCodes['AUTHZ1-FLAT-DEPT'] ?? null;
        $orgId = $this->organizations['A']->id;

        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => 'سوبر Authz',
                'password' => Hash::make('password'),
                'department_id' => $deptId,
                'organization_id' => $orgId,
                'is_active' => true,
            ]
        );

        $this->assignCanonicalRole(
            user: $user,
            roleName: 'super_admin',
            scopeType: AuthorizationRoleAssignment::SCOPE_ALL,
            scopeId: null,
            organizationId: $orgId,
            inheritToChildren: true,
        );

        $this->users[] = $user;
    }

    private function assignCanonicalRole(
        User $user,
        string $roleName,
        string $scopeType,
        ?int $scopeId,
        ?int $organizationId,
        bool $inheritToChildren,
        string $source = 'migration',
    ): void {
        $role = AuthorizationRole::query()->where('name', $roleName)->firstOrFail();

        AuthorizationRoleAssignment::query()->updateOrCreate(
            [
                'authorization_role_id' => $role->id,
                'user_id' => $user->id,
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
            ],
            [
                'organization_id' => $organizationId,
                'inherit_to_children' => $inheritToChildren,
                'expires_at' => null,
                'source' => $source,
                'granted_by' => null,
            ],
        );

        AccessDecision::flushUserCache($user->id);
    }

    private function deptCode(string $code): int
    {
        return (int) ($this->deptCodes[$code] ?? throw new \RuntimeException("Missing dept code: $code"));
    }

    /**
     * Render a small ASCII tree for the fixture rooted at $root so the seeder
     * output doubles as a visual report of what was created.
     */
    private function printTree(Department $root, string $label): void
    {
        $rows = $this->collectTreeRows($root, 0);
        $this->command->info("   ┌── $label");

        foreach ($rows as $row) {
            $indent = str_repeat('   │   ', $row['depth']);
            $this->command->info(sprintf(
                '   %s├── %s [%s] (level %d, id %d)',
                $indent,
                $row['name'],
                $row['code'],
                $row['level'],
                $row['id']
            ));
        }

        $this->command->info('');
    }

    /**
     * Pre-order DFS walk over freshly created nodes for printing. We iterate
     * in-memory instead of re-reading so the tree reflects just-built structure
     * even when the observer has not yet propagated path materializations.
     */
    private function collectTreeRows(Department $root, int $depth): array
    {
        $byParent = [];
        foreach ($this->departments as $dept) {
            $pid = $dept->parent_id ?? 0;
            $byParent[$pid][] = $dept;
        }

        $rows = [];
        $this->walkTree($root, $depth, $byParent, $rows);

        return $rows;
    }

    /**
     * Recursive helper for collectTreeRows. Captures rows + their depth.
     */
    private function walkTree(Department $node, int $depth, array $byParent, array &$rows): void
    {
        $rows[] = [
            'name' => $node->name,
            'code' => $node->code,
            'level' => $node->level,
            'id' => $node->id,
            'depth' => $depth,
        ];

        foreach ($byParent[$node->id] ?? [] as $child) {
            $this->walkTree($child, $depth + 1, $byParent, $rows);
        }
    }
}
