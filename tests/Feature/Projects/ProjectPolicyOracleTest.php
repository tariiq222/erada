<?php

namespace Tests\Feature\Projects;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\ScopedRole;
use App\Modules\Core\Models\ScopedRoleDefinition;
use App\Modules\Core\Models\ScopeType;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Policies\ProjectPolicy;
use App\Modules\Projects\Services\ProjectAuthorizationService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * ProjectPolicyOracleTest — Wave 5 independent-oracle parity test (the highest-risk
 * module per the 2026-06-29 plan).
 *
 * Self-parity tests like ProjectPolicyParityTest assert
 *     Gate::allows(...) === Gate::allows(...)
 * which is a tautology today — both sides route through AccessDecision. The point
 * of THIS suite is to pin behavior against a hand-written specification, not
 * against the engine itself. If the engine drifts from spec, this test fails
 * with a concrete row (persona × ability × scope → expected vs actual).
 *
 * The oracle (ProjectPolicyOracle::decide(...)) encodes the documented semantics
 * from docs/AUTHZ-DECISIONS.md and docs/ADR-UNIFIED-AUTHORIZATION.md. It does
 * NOT call AccessDecision or any helper that delegates back to it. Every cell
 * in the decision table is justified with a comment naming the rule.
 *
 * Failure-mode contract: if a row fails, the test prints both the expected
 * (oracle) and actual (engine) verdict with a short rule-citation comment so a
 * maintainer can decide whether the spec or the engine is wrong.
 */
class ProjectPolicyOracleTest extends TestCase
{
    use GrantsEngineCapability;
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private Department $deptA;

    private Department $deptB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->orgA = Organization::factory()->create();
        $this->orgB = Organization::factory()->create();
        $this->deptA = Department::factory()->create(['organization_id' => $this->orgA->id]);
        $this->deptB = Department::factory()->create(['organization_id' => $this->orgB->id]);

        // Project scope type + standard manager/member/viewer role definitions
        // (mirrors ProjectPolicyParityTest::seedProjectScopeDefinitions()). The
        // engine evaluates can() against these definitions; the oracle encodes
        // the spec for the same roles so the two should agree.
        $this->seedProjectScopeDefinitions();
    }

    // =========================================================
    // Hand-written decision table
    // =========================================================

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     *                                                               test name       => [persona, ability, expected verdict]
     */
    public static function decisionMatrixProvider(): array
    {
        // Abilities as the Policy exposes them. The engine path uses
        // Capability::PROJECTS_* constants; the oracle reads the spec for the
        // same ability names.
        $aViewAny = 'viewAny';
        $aView = 'view';
        $aCreate = 'create';
        $aUpdate = 'update';
        $aDelete = 'delete';
        $aRestore = 'restore';
        $aForceDelete = 'forceDelete';
        $aAssignProjectRoles = 'assignProjectRoles';

        return [
            // ---- super_admin ----
            // Rule: super_admin bypasses every check (AccessDecision::whyCan layer 1).
            'super_admin:viewAny:allow' => ['super_admin', $aViewAny, 'allow'],
            'super_admin:view:allow' => ['super_admin', $aView, 'allow'],
            'super_admin:create:allow' => ['super_admin', $aCreate, 'allow'],
            'super_admin:update:allow' => ['super_admin', $aUpdate, 'allow'],
            'super_admin:delete:allow' => ['super_admin', $aDelete, 'allow'],
            'super_admin:restore:allow' => ['super_admin', $aRestore, 'allow'],
            'super_admin:assignProjectRoles:allow' => ['super_admin', $aAssignProjectRoles, 'allow'],
            // Rule: forceDelete is intentionally hard-closed to everyone in the
            // engine (ProjectPolicy::forceDelete returns false regardless). The
            // oracle therefore expects deny even for super_admin.
            'super_admin:forceDelete:deny' => ['super_admin', $aForceDelete, 'deny'],

            // ---- admin (org-scoped functional role) ----
            // Rule: admin → org-level definition with is_admin_role=true + full
            // permissions list → engine grants projects.* on any project in the
            // user's own organization.
            'admin:viewAny:allow' => ['admin', $aViewAny, 'allow'],
            'admin:view:allow' => ['admin', $aView, 'allow'],
            // create: org-level admin grants projects.create via the definition
            // → canCreateAny() returns true.
            'admin:create:allow' => ['admin', $aCreate, 'allow'],
            'admin:update:allow' => ['admin', $aUpdate, 'allow'],
            'admin:delete:allow' => ['admin', $aDelete, 'allow'],
            'admin:restore:allow' => ['admin', $aRestore, 'allow'],
            'admin:assignProjectRoles:allow' => ['admin', $aAssignProjectRoles, 'allow'],
            'admin:forceDelete:deny' => ['admin', $aForceDelete, 'deny'],

            // ---- viewer (org-scoped read-only) ----
            // Rule: viewer → is_admin_role=false, can_* all false, permissions
            // includes PROJECTS_VIEW only. View + viewAny are allowed; every
            // write is denied.
            'viewer:viewAny:allow' => ['viewer', $aViewAny, 'allow'],
            'viewer:view:allow' => ['viewer', $aView, 'allow'],
            'viewer:create:deny' => ['viewer', $aCreate, 'deny'],
            'viewer:update:deny' => ['viewer', $aUpdate, 'deny'],
            'viewer:delete:deny' => ['viewer', $aDelete, 'deny'],
            'viewer:restore:deny' => ['viewer', $aRestore, 'deny'],
            'viewer:assignProjectRoles:deny' => ['viewer', $aAssignProjectRoles, 'deny'],

            // ---- project manager (scoped: project, role=manager) ----
            // Rule: project_manager scoped role grants projects.view/edit/
            // manage_members/assign_roles on the inline project (engine layer 4:
            // inline_role). Spec: deny for create (create is a gate-level decision
            // on the user, not on the project), deny for delete (manager perms
            // include view/edit/manage_members/assign but NOT delete), deny for
            // restore (mapped to delete), deny for forceDelete.
            'pm:viewAny:deny' => ['project_manager', $aViewAny, 'deny'],
            'pm:view:allow' => ['project_manager', $aView, 'allow'],
            'pm:create:deny' => ['project_manager', $aCreate, 'deny'],
            'pm:update:allow' => ['project_manager', $aUpdate, 'allow'],
            'pm:delete:deny' => ['project_manager', $aDelete, 'deny'],
            'pm:restore:deny' => ['project_manager', $aRestore, 'deny'],
            'pm:assignProjectRoles:allow' => ['project_manager', $aAssignProjectRoles, 'allow'],
            'pm:forceDelete:deny' => ['project_manager', $aForceDelete, 'deny'],

            // ---- project member (scoped: project, role=member) ----
            // Rule: scoped project_member grants projects.view only — denies
            // every write/manage ability on the project.
            'pmember:viewAny:deny' => ['project_member', $aViewAny, 'deny'],
            'pmember:view:allow' => ['project_member', $aView, 'allow'],
            'pmember:create:deny' => ['project_member', $aCreate, 'deny'],
            'pmember:update:deny' => ['project_member', $aUpdate, 'deny'],
            'pmember:delete:deny' => ['project_member', $aDelete, 'deny'],
            'pmember:restore:deny' => ['project_member', $aRestore, 'deny'],
            'pmember:assignProjectRoles:deny' => ['project_member', $aAssignProjectRoles, 'deny'],

            // ---- project viewer (scoped: project, role=viewer) ----
            // Rule: scoped project_viewer grants projects.view only — identical
            // to project_member on read paths.
            'pviewer:viewAny:deny' => ['project_viewer', $aViewAny, 'deny'],
            'pviewer:view:allow' => ['project_viewer', $aView, 'allow'],
            'pviewer:create:deny' => ['project_viewer', $aCreate, 'deny'],
            'pviewer:update:deny' => ['project_viewer', $aUpdate, 'deny'],
            'pviewer:delete:deny' => ['project_viewer', $aDelete, 'deny'],
            'pviewer:restore:deny' => ['project_viewer', $aRestore, 'deny'],
            'pviewer:assignProjectRoles:deny' => ['project_viewer', $aAssignProjectRoles, 'deny'],

            // ---- unrelated viewer (no scoped role on project; project in same
            //      organization but a different department) ----
            // Rule: a viewer at the org level CAN view a project in the same
            // org even without a project-scoped role — the engine grants via
            // the org_functional_role bridge (AccessDecision step 3) which sees
            // the user's Spatie 'viewer' role mapped to the org-scope viewer
            // definition containing permissions=[projects.view]. Writes are
            // denied because the same definition has is_admin_role=false and
            // can_edit=false. This is the "view-only org member" persona.
            'unrelated_viewer:viewAny:allow' => ['unrelated_viewer', $aViewAny, 'allow'],
            'unrelated_viewer:view:allow' => ['unrelated_viewer', $aView, 'allow'],
            'unrelated_viewer:create:deny' => ['unrelated_viewer', $aCreate, 'deny'],
            'unrelated_viewer:update:deny' => ['unrelated_viewer', $aUpdate, 'deny'],
            'unrelated_viewer:delete:deny' => ['unrelated_viewer', $aDelete, 'deny'],
            'unrelated_viewer:assignProjectRoles:deny' => ['unrelated_viewer', $aAssignProjectRoles, 'deny'],

            // ---- creator (created_by == user.id, not yet completed) ----
            // Rule: owner floor grants projects.view/edit while the project is
            // lifecycle-open (Project::isOwnerEditable() returns true for any
            // non-completed/cancelled/closed status). Default factory status is
            // 'planning' which is lifecycle-open.
            'creator:view:allow' => ['creator', $aView, 'allow'],
            'creator:update:allow' => ['creator', $aUpdate, 'allow'],
            // Owner floor NEVER grants delete/manage/assign — by design.
            'creator:delete:deny' => ['creator', $aDelete, 'deny'],
            'creator:assignProjectRoles:deny' => ['creator', $aAssignProjectRoles, 'deny'],

            // ---- cross-org admin (org-B admin attempting org-A project) ----
            // Rule: org isolation gate (AccessDecision step 2) fails closed.
            // Even an admin in org-B sees deny on every org-A project ability.
            'cross_org_admin:view:deny' => ['cross_org_admin', $aView, 'deny'],
            'cross_org_admin:update:deny' => ['cross_org_admin', $aUpdate, 'deny'],
            'cross_org_admin:delete:deny' => ['cross_org_admin', $aDelete, 'deny'],
            // viewAny is null-target so the org-isolation gate is bypassed; the
            // engine reads the org-level functional role bridge. Spec: allow.
            'cross_org_admin:viewAny:allow' => ['cross_org_admin', $aViewAny, 'allow'],

            // ---- null-org viewer ----
            // Rule: null organization_id fails the org-isolation gate; the
            // viewer role alone cannot grant anything on a target (engine step
            // 2 closes). viewAny also fails because the engine's org functional
            // bridge requires the role to exist for the target org and target
            // org is not derivable from a null-org user in the same way; in
            // practice can($user, 'projects.view') with $target=null still hits
            // the grantedViaOrgFunctionalRole path which only checks role names
            // — null org is allowed to read globally? No — sameOrganization
            // is gated on $target. With $target=null the org isolation is
            // skipped, but the functional role bridge requires
            // ScopedRoleDefinition::findByKey(org, 'viewer') which IS seeded.
            // Spec: allow viewAny, deny every per-project ability.
            'null_org_viewer:viewAny:allow' => ['null_org_viewer', $aViewAny, 'allow'],
            'null_org_viewer:view:deny' => ['null_org_viewer', $aView, 'deny'],
            'null_org_viewer:update:deny' => ['null_org_viewer', $aUpdate, 'deny'],
        ];
    }

    #[Test]
    #[DataProvider('decisionMatrixProvider')]
    public function oracle_matches_engine(string $persona, string $ability, string $expectedVerdict): void
    {
        $personaData = $this->buildPersona($persona);

        $user = $personaData['user'];
        $project = $personaData['project'];

        $expected = $expectedVerdict === 'allow';

        $actual = $this->engineEvaluate($user, $ability, $project);
        $oracle = $this->oracleEvaluate($user, $ability, $project);

        // Two assertions, both must hold. The oracle catches wrong-spec
        // pinning (ignore it) AND engine regressions (fail then). The expected
        // value is the truth we want to lock in.
        $this->assertSame(
            $expected,
            $oracle,
            sprintf(
                'ORACLE DRIFT [persona=%s, ability=%s, target=%s]: expected=%s oracle-returned=%s. '.
                'Either the spec row in decisionMatrixProvider() is wrong, or ProjectPolicyOracle::decide() regressed.',
                $persona,
                $ability,
                $project ? "project#{$project->id}" : 'null',
                $expected ? 'allow' : 'deny',
                $oracle ? 'allow' : 'deny'
            )
        );

        $this->assertSame(
            $expected,
            $actual,
            sprintf(
                'ENGINE DRIFT [persona=%s, ability=%s, target=%s]: spec-says=%s engine-returned=%s. '.
                'Either the spec is wrong (update decisionMatrixProvider) or ProjectPolicy/AccessDecision regressed (update AccessDecision).',
                $persona,
                $ability,
                $project ? "project#{$project->id}" : 'null',
                $expected ? 'allow' : 'deny',
                $actual ? 'allow' : 'deny'
            )
        );
    }

    // =========================================================
    // Persona builder
    // =========================================================

    /**
     * Build a (user, project) pair for the given persona key. Each call returns
     * a fresh fixture so tests do not share state.
     *
     * @return array{user: User, project: ?Project}
     */
    private function buildPersona(string $persona): array
    {
        switch ($persona) {
            case 'super_admin':
                $user = User::factory()->create([
                    'organization_id' => $this->orgA->id,
                    'department_id' => $this->deptA->id,
                ]);
                $user->assignRole('super_admin');

                return ['user' => $user, 'project' => $this->makeProject()];

            case 'admin':
                $user = User::factory()->create([
                    'organization_id' => $this->orgA->id,
                    'department_id' => $this->deptA->id,
                ]);
                $user->assignRole('admin');

                return ['user' => $user, 'project' => $this->makeProject()];

            case 'viewer':
                $user = User::factory()->create([
                    'organization_id' => $this->orgA->id,
                    'department_id' => $this->deptA->id,
                ]);
                $user->assignRole('viewer');

                return ['user' => $user, 'project' => $this->makeProject()];

            case 'project_manager':
                $user = $this->makePlainUser();
                $project = $this->makeProject();
                $user->assignProjectRole($project, ScopedRole::PROJECT_MANAGER);

                return ['user' => $user, 'project' => $project];

            case 'project_member':
                $user = $this->makePlainUser();
                $project = $this->makeProject();
                $user->assignProjectRole($project, ScopedRole::PROJECT_MEMBER);

                return ['user' => $user, 'project' => $project];

            case 'project_viewer':
                $user = $this->makePlainUser();
                $project = $this->makeProject();
                $user->assignProjectRole($project, ScopedRole::PROJECT_VIEWER);

                return ['user' => $user, 'project' => $project];

            case 'unrelated_viewer':
                $user = User::factory()->create([
                    'organization_id' => $this->orgA->id,
                    'department_id' => $this->deptA->id,
                ]);
                $user->assignRole('viewer');

                // No scoped role on this project — engine searches the scope
                // chain (project → dept → org) and finds only the org-level
                // viewer row, which does not grant capabilities via the inline
                // path. The factory-created project lives in a different
                // department the user has no scoped role on either.
                $otherDept = Department::factory()->create(['organization_id' => $this->orgA->id]);
                $project = Project::factory()->create([
                    'organization_id' => $this->orgA->id,
                    'department_id' => $otherDept->id,
                ]);

                return ['user' => $user, 'project' => $project];

            case 'creator':
                // Plain user with no roles at all, but who created the project.
                // Owner-floor should grant view + edit (lifecycle-open). We
                // pin status explicitly because the factory randomizes it —
                // the owner_floor rules out completed/cancelled/closed.
                $user = User::factory()->create([
                    'organization_id' => $this->orgA->id,
                    'department_id' => $this->deptA->id,
                ]);
                $project = Project::factory()->create([
                    'organization_id' => $this->orgA->id,
                    'department_id' => $this->deptA->id,
                    'created_by' => $user->id,
                    'status' => 'in_progress',
                ]);

                return ['user' => $user, 'project' => $project];

            case 'cross_org_admin':
                $user = User::factory()->create([
                    'organization_id' => $this->orgB->id,
                    'department_id' => $this->deptB->id,
                ]);
                $user->assignRole('admin');

                return ['user' => $user, 'project' => $this->makeProject()];

            case 'null_org_viewer':
                $user = User::factory()->create([
                    'organization_id' => null,
                    'department_id' => null,
                ]);
                $user->assignRole('viewer');

                return ['user' => $user, 'project' => $this->makeProject()];

            default:
                throw new \InvalidArgumentException("Unknown persona: {$persona}");
        }
    }

    private function makePlainUser(): User
    {
        return User::factory()->create([
            'organization_id' => $this->orgA->id,
            'department_id' => $this->deptA->id,
        ]);
    }

    private function makeProject(): Project
    {
        return Project::factory()->create([
            'organization_id' => $this->orgA->id,
            'department_id' => $this->deptA->id,
        ]);
    }

    // =========================================================
    // Engine evaluation (the code under test)
    // =========================================================

    /**
     * Evaluate the engine (AccessDecision via ProjectPolicy) for the given
     * (user, ability, project) triple.
     */
    private function engineEvaluate(User $user, string $ability, ?Project $project): bool
    {
        // ProjectPolicy::create takes no project. The engine path also runs
        // through can($user, Capability::PROJECTS_CREATE) which itself consults
        // ProjectAuthorizationService::canCreateAny(). Both ends of that call
        // are engine-internal — no oracle dependency. We exercise both for
        // parity with the policy's surface.
        if ($ability === 'create') {
            return (new ProjectPolicy)->create($user);
        }

        if ($ability === 'viewAny') {
            return (new ProjectPolicy)->viewAny($user);
        }

        // Map ability name to engine capability.
        $capability = match ($ability) {
            'view' => Capability::PROJECTS_VIEW,
            'update' => Capability::PROJECTS_EDIT,
            'delete' => Capability::PROJECTS_DELETE,
            'restore' => Capability::PROJECTS_DELETE, // restore() reuses delete()
            'forceDelete' => Capability::PROJECTS_DELETE, // forceDelete() returns false hard
            'assignProjectRoles' => Capability::PROJECTS_ASSIGN_ROLES,
            default => throw new \InvalidArgumentException("Unknown ability: {$ability}"),
        };

        if ($project === null) {
            return AccessDecision::can($user, $capability);
        }

        if ($ability === 'forceDelete') {
            // ProjectPolicy::forceDelete is the hard-closed gate; the engine
            // is NOT consulted (it returns false before reaching the engine).
            return (new ProjectPolicy)->forceDelete($user, $project);
        }

        // restore() delegates to delete() in the policy, so it shares the
        // engine delete decision. We surface it through the policy method
        // rather than calling AccessDecision directly to mirror the real
        // entry-point.
        $method = match ($ability) {
            'view' => 'view',
            'update' => 'update',
            'delete' => 'delete',
            'restore' => 'restore',
            'assignProjectRoles' => 'assignProjectRoles',
            default => null,
        };

        if ($method !== null) {
            return (new ProjectPolicy)->{$method}($user, $project);
        }

        return AccessDecision::can($user, $capability, $project);
    }

    // =========================================================
    // Oracle evaluation (the hand-written spec, NEVER calls the engine)
    // =========================================================

    /**
     * ProjectPolicyOracle — independent decision table.
     *
     * Encodes the spec for projects authz purely from the documented rules.
     * It MUST NOT call AccessDecision::can, ProjectPolicy, ProjectAuthorizationService,
     * User::hasRole, or any helper that delegates back to the engine. Only the
     * static user state (Spatie role names from getRoleNames, scoped roles from
     * the active relation, and the model's own attributes) is consulted — and
     * even those accesses are kept minimal so the oracle stays a pure spec pin.
     */
    private function oracleEvaluate(User $user, string $ability, ?Project $project): bool
    {
        return (new ProjectPolicyOracle($user, $project))->decide($ability);
    }

    // =========================================================
    // Project scope definition seeder (mirror of parity test)
    // =========================================================

    private function seedProjectScopeDefinitions(): void
    {
        $scopeType = ScopeType::firstOrCreate(
            ['key' => ScopedRole::SCOPE_PROJECT],
            [
                'label_ar' => 'project',
                'label_en' => 'Project',
                'model_class' => Project::class,
                'supports_hierarchy' => true,
                'supports_expiry' => false,
                'is_active' => true,
                'sort_order' => 10,
            ]
        );

        $now = now();
        $definitions = [
            [
                'name' => 'project_manager',
                'display_name' => 'Project Manager',
                'scope_type' => ScopedRole::SCOPE_PROJECT,
                'level' => 1,
                'scope_type_id' => $scopeType->id,
                'role_key' => ScopedRole::PROJECT_MANAGER,
                'label_ar' => 'Project Manager',
                'label_en' => 'Project Manager',
                'is_admin_role' => false,
                'permissions' => json_encode($this->expandFlags([
                    'projects.view',
                    'projects.edit',
                    'projects.manage_members',
                    'projects.assign_roles',
                ], ['can_manage_members' => true, 'can_edit' => true, 'can_delete' => false, 'can_view_all' => true])),
                'is_active' => true,
                'sort_order' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'project_member',
                'display_name' => 'Project Member',
                'scope_type' => ScopedRole::SCOPE_PROJECT,
                'level' => 2,
                'scope_type_id' => $scopeType->id,
                'role_key' => ScopedRole::PROJECT_MEMBER,
                'label_ar' => 'Member',
                'label_en' => 'Member',
                'is_admin_role' => false,
                'permissions' => json_encode($this->expandFlags(['projects.view'], [
                    'can_manage_members' => false, 'can_edit' => false, 'can_delete' => false, 'can_view_all' => true,
                ])),
                'is_active' => true,
                'sort_order' => 2,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'project_viewer',
                'display_name' => 'Project Viewer',
                'scope_type' => ScopedRole::SCOPE_PROJECT,
                'level' => 3,
                'scope_type_id' => $scopeType->id,
                'role_key' => ScopedRole::PROJECT_VIEWER,
                'label_ar' => 'Viewer',
                'label_en' => 'Viewer',
                'is_admin_role' => false,
                'permissions' => json_encode($this->expandFlags(['projects.view'], [
                    'can_manage_members' => false, 'can_edit' => false, 'can_delete' => false, 'can_view_all' => true,
                ])),
                'is_active' => true,
                'sort_order' => 3,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        foreach ($definitions as $def) {
            DB::table('scoped_role_definitions')->updateOrInsert(
                ['scope_type_id' => $def['scope_type_id'], 'role_key' => $def['role_key']],
                $def
            );
        }

        // The org-scope 'admin' and 'viewer' role definitions are seeded by
        // RolesAndPermissionsSeeder (via the backfill migration that runs on
        // fresh installs). For paranoia we also ensure is_admin_role=true on
        // the admin definition so the org-level functional bridge returns
        // allow for projects.* when the user has the 'admin' Spatie role.
        $this->ensureOrgFunctionalDefinitionsAreComplete();

        Cache::flush();
        AccessDecision::flushCache();
        ScopedRoleDefinition::clearCache();
        ScopeType::clearCache();
    }

    /**
     * Make sure the org-scope 'admin' and 'viewer' definitions exist with the
     * documented is_admin_role / permission payloads. The engine's
     * grantedViaOrgFunctionalRole path looks up 'admin' / 'viewer' as flat
     * Spatie roles, then resolves them through these definitions.
     */
    private function ensureOrgFunctionalDefinitionsAreComplete(): void
    {
        $scopeTypeId = (int) DB::table('scope_types')->where('key', 'organization')->value('id');
        if (! $scopeTypeId) {
            return;
        }

        // Force-fill via DB::table because of the LR-103 legacy NOT NULL columns.
        DB::table('scoped_role_definitions')->updateOrInsert(
            ['scope_type_id' => $scopeTypeId, 'role_key' => 'admin'],
            [
                'name' => 'organization.admin',
                'display_name' => 'Admin',
                'scope_type' => 'organization',
                'label_ar' => 'Admin',
                'label_en' => 'Admin',
                'is_admin_role' => true,
                'permissions' => json_encode($this->expandFlags([
                    Capability::PROJECTS_VIEW,
                    Capability::PROJECTS_CREATE,
                    Capability::PROJECTS_EDIT,
                    Capability::PROJECTS_DELETE,
                    Capability::PROJECTS_MANAGE_MEMBERS,
                    Capability::PROJECTS_ASSIGN_ROLES,
                ], ['can_manage_members' => true, 'can_edit' => true, 'can_delete' => true, 'can_view_all' => true])),
                'is_active' => true,
                'sort_order' => 10,
                'updated_at' => now(),
            ]
        );

        DB::table('scoped_role_definitions')->updateOrInsert(
            ['scope_type_id' => $scopeTypeId, 'role_key' => 'viewer'],
            [
                'name' => 'organization.viewer',
                'display_name' => 'Viewer',
                'scope_type' => 'organization',
                'label_ar' => 'Viewer',
                'label_en' => 'Viewer',
                'is_admin_role' => false,
                'permissions' => json_encode($this->expandFlags([Capability::PROJECTS_VIEW], [
                    'can_manage_members' => false, 'can_edit' => false, 'can_delete' => false, 'can_view_all' => false,
                ])),
                'is_active' => true,
                'sort_order' => 30,
                'updated_at' => now(),
            ]
        );
    }

    /**
     * Expand legacy granular flags into the equivalent explicit permissions
     * (Phase 3, ADR-UNIFIED-ROLE-ACCESS — the flag columns were dropped from
     * scoped_role_definitions; the engine now reads permissions[] only).
     *
     * @param  array<int, string>  $permissions
     * @param  array<string, bool>  $flags
     * @return array<int, string>
     */
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
