<?php

namespace Tests\Feature\Core\Authorization;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\AuthorizationRuntimeMode;
use App\Modules\Core\Authorization\AuthzShadowMismatchException;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Authorization\Models\AuthorizationRecordRule;
use App\Modules\Core\Authorization\Models\AuthorizationResource;
use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Authorization\Models\AuthorizationRoleAssignment;
use App\Modules\Core\Authorization\Models\AuthorizationRolePermission;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\ScopedRole;
use App\Modules\Core\Models\ScopedRoleDefinition;
use App\Modules\Core\Models\ScopeType;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * AccessDecisionShadowTest -- Phase 1 Tasks 1.1.4 + 1.1.5 (limited SHADOW branch).
 *
 * Drives the SHADOW runtime-mode branch in AccessDecision::can() that runs the
 * new authorization_role_permissions + authorization_record_rules path
 * alongside the legacy engine. Shadow is enabled per test by calling
 * AuthorizationRuntimeMode::enableShadow() and reset to disabled in
 * tearDown so each test starts from a clean slate.
 *
 * Limited scope (the slice this phase ships):
 *  - Target-bound only: target === null skips shadow comparison.
 *  - super_admin short-circuit is shadow-skipped.
 *  - Supported scopes: 'all' and 'organization' only. Other scope types
 *    are treated as not applicable in this slice (new-path denies).
 *  - No audit writes; the new path is compare-only.
 *  - Action suffix = segment after the last dot of the capability string
 *    (e.g. 'projects.view' -> 'view'), matching the plan seed-map direction.
 */
class AccessDecisionShadowTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Organization $otherOrg;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('AccessDecision shadow test is PostgreSQL-only.');
        }

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->org = Organization::factory()->create();
        $this->otherOrg = Organization::factory()->create();

        // Each test starts in a known runtime-mode state regardless of the
        // order PHPUnit runs the methods in.
        AuthorizationRuntimeMode::reset();
    }

    protected function tearDown(): void
    {
        AuthorizationRuntimeMode::reset();
        AccessDecision::flushCache();

        parent::tearDown();
    }

    // =====================================================================
    // 1. Shadow disabled by default does not throw when new tables empty.
    // =====================================================================

    #[Test]
    public function shadow_disabled_by_default_does_not_throw_with_empty_new_tables(): void
    {
        $this->assertFalse(
            AuthorizationRuntimeMode::isShadow(),
            'Shadow must be OFF by default (no flag, no config).'
        );

        $user = User::factory()->create(['organization_id' => $this->org->id]);
        $project = Project::factory()->create([
            'organization_id' => $this->org->id,
        ]);

        // No authz_* rows exist; default legacy decision returns false.
        $this->assertFalse(
            AccessDecision::can($user, Capability::PROJECTS_VIEW, $project)
        );
    }

    // =====================================================================
    // 2. Shadow enabled throws when legacy allows but new path denies
    //    (no authorization_role_permissions row exists for the user).
    // =====================================================================

    #[Test]
    public function shadow_throws_when_legacy_allows_but_new_path_denies_missing_permission(): void
    {
        AuthorizationRuntimeMode::enableShadow();

        [$user, $project] = $this->createProjectViewerWithLegacyGrant();

        // No authorization_role_permissions row -> new path denies.
        // Legacy still grants via the scoped role -> mismatch -> throw.
        $this->expectException(AuthzShadowMismatchException::class);

        try {
            AccessDecision::can($user, Capability::PROJECTS_VIEW, $project);
        } catch (AuthzShadowMismatchException $e) {
            $this->assertSame(Capability::PROJECTS_VIEW, $e->capability);
            $this->assertTrue($e->legacyDecision);
            $this->assertFalse($e->newPathDecision);

            throw $e;
        }
    }

    // =====================================================================
    // 3. Shadow passes when new path agrees on a target-bound grant
    //    with scope_type=organization.
    // =====================================================================

    #[Test]
    public function shadow_passes_when_new_path_agrees_on_organization_scope_grant(): void
    {
        AuthorizationRuntimeMode::enableShadow();

        [$user, $project] = $this->createProjectViewerWithLegacyGrant();

        $resource = $this->makeResource(Project::class);
        $role = $this->makeRole('proj_viewer_shadow_ok');
        $this->attachPermission($role, $resource, 'view');
        $this->assignRole($user, $role, 'organization', $this->org->id, $this->org->id);

        // Legacy grants via scoped role; new path grants via role permission +
        // org scope match. Both allow -> no throw.
        $this->assertTrue(
            AccessDecision::can($user, Capability::PROJECTS_VIEW, $project)
        );
    }

    // =====================================================================
    // 4. Shadow throws when a record rule excludes the target.
    // =====================================================================

    #[Test]
    public function shadow_throws_when_record_rule_excludes_target(): void
    {
        AuthorizationRuntimeMode::enableShadow();

        [$user, $project] = $this->createProjectViewerWithLegacyGrant();

        $resource = $this->makeResource(Project::class);
        $role = $this->makeRole('proj_viewer_rule_excludes');
        $this->attachPermission($role, $resource, 'view');
        $this->assignRole($user, $role, 'organization', $this->org->id, $this->org->id);

        AuthorizationRecordRule::create([
            'authorization_role_id' => $role->id,
            'authorization_resource_id' => $resource->id,
            'action' => 'view',
            'domain_json' => [
                'operator' => 'neq',
                'column' => 'id',
                'value' => (int) $project->id,
            ],
            'enabled' => true,
            'priority' => 10,
        ]);

        // Legacy allows (scoped role); new path permission+scope allows, but
        // the record rule excludes the target -> new path denies -> mismatch.
        $this->expectException(AuthzShadowMismatchException::class);

        AccessDecision::can($user, Capability::PROJECTS_VIEW, $project);
    }

    // =====================================================================
    // 5. Shadow passes when a record rule admits the target.
    // =====================================================================

    #[Test]
    public function shadow_passes_when_record_rule_admits_target(): void
    {
        AuthorizationRuntimeMode::enableShadow();

        [$user, $project] = $this->createProjectViewerWithLegacyGrant();

        $resource = $this->makeResource(Project::class);
        $role = $this->makeRole('proj_viewer_rule_admits');
        $this->attachPermission($role, $resource, 'view');
        $this->assignRole($user, $role, 'organization', $this->org->id, $this->org->id);

        AuthorizationRecordRule::create([
            'authorization_role_id' => $role->id,
            'authorization_resource_id' => $resource->id,
            'action' => 'view',
            'domain_json' => [
                'operator' => 'eq',
                'column' => 'id',
                'value' => (int) $project->id,
            ],
            'enabled' => true,
            'priority' => 10,
        ]);

        // Both legacy and new path allow -> no throw.
        $this->assertTrue(
            AccessDecision::can($user, Capability::PROJECTS_VIEW, $project)
        );
    }

    // =====================================================================
    // 6. Shadow skipped when target is null.
    // =====================================================================

    #[Test]
    public function shadow_skipped_when_target_is_null(): void
    {
        AuthorizationRuntimeMode::enableShadow();

        [$user, $roleDefinition] = $this->createOrgAdminWithLegacyGrant();
        $user->assignScopedRole(
            role: $roleDefinition->role_key,
            scopeType: ScopedRole::SCOPE_ORGANIZATION,
            scopeId: $this->org->id,
        );

        // target = null. Shadow branch must NOT run; legacy returns true via
        // the org-functional layer.
        $this->assertTrue(
            AccessDecision::can($user, Capability::PROJECTS_VIEW, null)
        );
    }

    // =====================================================================
    // 7. Shadow skipped for super_admin short-circuit.
    // =====================================================================

    #[Test]
    public function shadow_skipped_for_super_admin(): void
    {
        AuthorizationRuntimeMode::enableShadow();

        $superAdmin = User::factory()->create(['organization_id' => $this->org->id]);
        $superAdmin->assignRole('super_admin');
        $project = Project::factory()->create([
            'organization_id' => $this->otherOrg->id, // cross-org to prove short-circuit
        ]);

        // super_admin short-circuits before shadow comparison.
        $this->assertTrue(
            AccessDecision::can($superAdmin, Capability::PROJECTS_VIEW, $project)
        );
    }

    // =====================================================================
    // 8. Cross-org: legacy deny + new-path deny does not throw.
    // =====================================================================

    #[Test]
    public function shadow_does_not_throw_when_both_paths_deny_on_cross_org(): void
    {
        AuthorizationRuntimeMode::enableShadow();

        $user = User::factory()->create(['organization_id' => $this->org->id]);
        $foreignProject = Project::factory()->create([
            'organization_id' => $this->otherOrg->id,
        ]);

        // user in $this->org, target in $otherOrg.
        // Legacy: org_isolation_denied -> false.
        // New path: no resource, or no permission -> false.
        // Both deny -> no throw.
        $this->assertFalse(
            AccessDecision::can($user, Capability::PROJECTS_VIEW, $foreignProject)
        );
    }

    // =====================================================================
    // 9. Runtime mode resets between tests.
    // =====================================================================

    #[Test]
    public function runtime_mode_starts_disabled_at_test_boundary(): void
    {
        // setUp() should have reset the static runtime-mode service.
        $this->assertFalse(AuthorizationRuntimeMode::isShadow());

        AuthorizationRuntimeMode::enableShadow();
        $this->assertTrue(AuthorizationRuntimeMode::isShadow());

        AuthorizationRuntimeMode::disableShadow();
        $this->assertFalse(AuthorizationRuntimeMode::isShadow());

        AuthorizationRuntimeMode::reset();
        $this->assertFalse(AuthorizationRuntimeMode::isShadow());
    }

    // =====================================================================
    // 10. Shadow is compare-only: no audit rows are written even when a
    //     mismatch throws. The audit table exists (per Phase 1 Task 1.1.1
    //     migration), but the limited SHADOW branch must NOT populate it.
    //     This pins the no-audit-writes invariant from the plan.
    // =====================================================================

    #[Test]
    public function shadow_does_not_write_audit_rows_on_mismatch(): void
    {
        $this->assertTrue(
            \Schema::hasTable('authorization_decision_audits'),
            'authorization_decision_audits table must exist for this regression pin to be meaningful.'
        );

        // Establish a baseline. Shadow is OFF and no call has happened yet.
        $baseline = (int) DB::table('authorization_decision_audits')->count();

        AuthorizationRuntimeMode::enableShadow();

        [$user, $project] = $this->createProjectViewerWithLegacyGrant();

        // Legacy grants via scoped role; new path has no role_permission row
        // -> mismatch -> throws. The shadow branch is compare-only.
        try {
            AccessDecision::can($user, Capability::PROJECTS_VIEW, $project);
            $this->fail('Expected AuthzShadowMismatchException was not thrown.');
        } catch (AuthzShadowMismatchException) {
            // Expected.
        }

        $this->assertSame(
            $baseline,
            (int) DB::table('authorization_decision_audits')->count(),
            'Shadow mismatch must not write to authorization_decision_audits (compare-only).'
        );
    }

    // =====================================================================
    // 11. Still-unsupported assignment scopes (Phase 2.1.2 slice):
    //     cluster, hospital, team, own. Phase 2.1.2 added full support
    //     for {organization, department, project, program, portfolio, kpi,
    //     meeting, survey} via ScopeAssignmentResolver, but the remaining
    //     Phase 1 future-scope types stay fail-closed. Even when a user
    //     holds a role_permission for (resource, action), an assignment
    //     with one of these scope_types must NOT grant via the new path --
    //     hasNewPermission/assignmentScopeApplies returns false, so
    //     new-path denies; legacy may grant (e.g. via a department-scoped
    //     scoped_role), which then surfaces as a mismatch (the desired
    //     shadow signal). The assertion pins that new-path does NOT widen.
    // =====================================================================

    /**
     * @return array<string, array{0: string}>
     */
    public static function unsupportedAssignmentScopesProvider(): array
    {
        return [
            'team' => ['team'],
            'cluster' => ['cluster'],
            'hospital' => ['hospital'],
            'own' => ['own'],
        ];
    }

    #[Test]
    public function shadow_does_not_widen_access_for_unsupported_assignment_scopes(): void
    {
        AuthorizationRuntimeMode::enableShadow();

        [$user, $project] = $this->createProjectViewerWithLegacyGrant();

        $resource = $this->makeResource(Project::class);
        $role = $this->makeRole('proj_viewer_unsupported_scope');

        // Attach a permission that matches the capability's action suffix
        // ('view') so hasNewPermission() reaches assignmentScopeApplies()
        // (which is the function under test here).
        $this->attachPermission($role, $resource, 'view');

        foreach (self::unsupportedAssignmentScopesProvider() as $label => [$scopeType]) {
            // 'own' (and 'all') are CHECK-constrained to scope_id IS NULL per the
            // authorization_role_assignments migration; every other scope_type
            // requires a NOT NULL scope_id. Pick an integer the CHECK accepts
            // for each branch so the INSERT itself does not fail before the
            // shadow logic runs.
            $scopeId = match ($scopeType) {
                'own' => null,
                default => $this->org->id,
            };

            $this->assignRole($user, $role, $scopeType, $scopeId, $this->org->id);

            // Legacy grants via the project-scoped scoped_role (set up in
            // createProjectViewerWithLegacyGrant). New path finds the role
            // permission row, then assignmentScopeApplies() rejects the
            // unsupported scope -> new path denies -> mismatch -> throw.
            // The pin is the EXCEPTION TYPE: a mismatch proves new-path
            // denied (did NOT widen). An unintended allow would surface as
            // a no-throw PASS and break this test.
            try {
                AccessDecision::can($user, Capability::PROJECTS_VIEW, $project);
                $this->fail(
                    "Unsupported scope [{$scopeType}] must not widen access; "
                    .'expected AuthzShadowMismatchException, got a silent allow.'
                );
            } catch (AuthzShadowMismatchException $e) {
                $this->assertFalse(
                    $e->newPathDecision,
                    "Unsupported scope [{$scopeType}] must not widen new-path decision to allow."
                );
                $this->assertTrue(
                    $e->legacyDecision,
                    "Legacy path must still grant for [{$scopeType}] (so the mismatch is real)."
                );
            }

            // Clean up the unsupported-scope assignment so the next loop
            // iteration starts fresh and the loop can also exercise the
            // 'own' scope which is NULL-id in the schema.
            AuthorizationRoleAssignment::query()
                ->where('user_id', $user->id)
                ->where('authorization_role_id', $role->id)
                ->where('scope_type', $scopeType)
                ->delete();
        }
    }

    // =====================================================================
    // 11b. Department scope is NOW supported (Phase 2.1.2). A
    //      department-scoped assignment on the project's department must
    //      grant via the new path so it agrees with the legacy grant
    //      (legacy allows via the project-scoped scoped_role, new path
    //      allows via role_permission + department scope match). Shadow
    //      must NOT throw.
    // =====================================================================

    #[Test]
    public function shadow_passes_when_department_scope_grant_matches_target_department(): void
    {
        AuthorizationRuntimeMode::enableShadow();

        [$user, $project] = $this->createProjectViewerWithLegacyGrant();

        $resource = $this->makeResource(Project::class);
        $role = $this->makeRole('proj_viewer_dept_scope_supported');
        $this->attachPermission($role, $resource, 'view');

        // The project's department is the one the factory created in
        // createProjectViewerWithLegacyGrant; we re-fetch the project so
        // we know the exact department_id the resolver will see.
        $project->refresh();
        $this->assertNotNull($project->department_id, 'Test prerequisite: project must live in a department.');

        AuthorizationRoleAssignment::create([
            'authorization_role_id' => $role->id,
            'user_id' => $user->id,
            'scope_type' => AuthorizationRoleAssignment::SCOPE_DEPARTMENT,
            'scope_id' => (int) $project->department_id,
            'organization_id' => $this->org->id,
        ]);

        // Legacy allows (project-scoped scoped_role); new path allows
        // (role_permission + department scope matches project.department_id).
        // Both allow -> shadow silent.
        $this->assertTrue(
            AccessDecision::can($user, Capability::PROJECTS_VIEW, $project)
        );
    }

    // =====================================================================
    // 12. Supported 'all' assignment scope in the limited SHADOW slice.
    //     An authorization_role_permission row + an assignment with
    //     scope_type='all' must grant via the new path so it agrees with
    //     the legacy grant. Shadow must NOT throw.
    // =====================================================================

    #[Test]
    public function shadow_passes_when_new_path_grants_via_all_assignment_scope(): void
    {
        AuthorizationRuntimeMode::enableShadow();

        [$user, $project] = $this->createProjectViewerWithLegacyGrant();

        $resource = $this->makeResource(Project::class);
        $role = $this->makeRole('proj_viewer_all_scope');
        $this->attachPermission($role, $resource, 'view');

        // 'all' is CHECK-constrained to scope_id IS NULL per the migration.
        AuthorizationRoleAssignment::create([
            'authorization_role_id' => $role->id,
            'user_id' => $user->id,
            'scope_type' => AuthorizationRoleAssignment::SCOPE_ALL,
            'scope_id' => null,
            'organization_id' => null,
        ]);

        // Legacy grants via scoped role; new path grants via role permission +
        // 'all' scope (unconditional). Both allow -> no throw.
        $this->assertTrue(
            AccessDecision::can($user, Capability::PROJECTS_VIEW, $project)
        );
    }

    // =====================================================================
    // 13. Organization-scope assignment with a cross-org target: both
    //     legacy and new path deny -> shadow does NOT throw. The legacy
    //     deny comes from the org_isolation_denied layer; the new-path
    //     deny comes from assignmentScopeApplies() finding an org mismatch
    //     on the assignment. Pin the SHADOW invariant that a deny/deny
    //     pair is silent.
    // =====================================================================

    #[Test]
    public function shadow_does_not_throw_when_organization_assignment_mismatches_target_org(): void
    {
        AuthorizationRuntimeMode::enableShadow();

        [$user] = $this->createProjectViewerWithLegacyGrant();

        $resource = $this->makeResource(Project::class);
        $role = $this->makeRole('proj_viewer_org_mismatch_role');
        $this->attachPermission($role, $resource, 'view');

        // Assignment scoped to the USER's org. The probe target below lives in
        // $this->otherOrg, so the new-path scope check must deny.
        AuthorizationRoleAssignment::create([
            'authorization_role_id' => $role->id,
            'user_id' => $user->id,
            'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
            'scope_id' => $this->org->id,
            'organization_id' => $this->org->id,
        ]);

        $foreignProject = Project::factory()->create([
            'organization_id' => $this->otherOrg->id,
            'department_id' => Department::factory()->create([
                'organization_id' => $this->otherOrg->id,
            ])->id,
        ]);

        // Legacy: org_isolation_denied (target in other org) -> false.
        // New path: role permission found, but assignmentScopeApplies()
        // sees org mismatch -> false.
        // Both deny -> shadow does NOT throw.
        $this->assertFalse(
            AccessDecision::can($user, Capability::PROJECTS_VIEW, $foreignProject)
        );
    }

    // =====================================================================
    // 14. Missing AuthorizationResource row: legacy allows but the new
    //     path cannot resolve the resource key -> it denies. Shadow must
    //     surface this as a mismatch with newPathDecision=false.
    // =====================================================================

    #[Test]
    public function shadow_throws_when_legacy_allows_but_resource_row_missing(): void
    {
        AuthorizationRuntimeMode::enableShadow();

        [$user, $project] = $this->createProjectViewerWithLegacyGrant();

        // Defensive: make sure NO AuthorizationResource row exists for Project.
        // firstOrCreate() in the helper would otherwise auto-create one and
        // hide the missing-resource branch we want to exercise.
        $existing = AuthorizationResource::where('key', Project::class)->first();
        if ($existing !== null) {
            $existing->delete();
            AccessDecision::flushCache();
        }

        $this->assertNull(
            AuthorizationResource::where('key', Project::class)->first(),
            'Test prerequisite: no AuthorizationResource row for Project.'
        );

        // Legacy grants (scoped role); new path resource lookup fails -> deny.
        $this->expectException(AuthzShadowMismatchException::class);

        try {
            AccessDecision::can($user, Capability::PROJECTS_VIEW, $project);
        } catch (AuthzShadowMismatchException $e) {
            $this->assertSame(Capability::PROJECTS_VIEW, $e->capability);
            $this->assertTrue($e->legacyDecision, 'Legacy path must still allow.');
            $this->assertFalse($e->newPathDecision, 'New path must deny when no resource row exists.');

            throw $e;
        }
    }

    // =====================================================================
    // 15. Cache invalidation: a role-permission + assignment written
    //     AFTER an initial shadow call must invalidate the per-user
    //     role-permissions cache so the second shadow call sees the new
    //     state. The AuthorizationRolePermission + AuthorizationRoleAssignment
    //     model `saved` hooks fire AccessDecision::flushCache() (LR-008:
    //     never re-introduce a stale grant). The test pins that the
    //     shadow branch's cache is observable from a second can() call.
    // =====================================================================

    #[Test]
    public function shadow_picks_up_role_permission_added_after_initial_call(): void
    {
        AuthorizationRuntimeMode::enableShadow();

        [$user, $project] = $this->createProjectViewerWithLegacyGrant();

        // First call: legacy grants via scoped role; new path has no matching
        // permission -> mismatch -> throw. This call memoizes the empty
        // $rolePermissionsCache[$userId] entry.
        try {
            AccessDecision::can($user, Capability::PROJECTS_VIEW, $project);
            $this->fail('Expected AuthzShadowMismatchException on the first call.');
        } catch (AuthzShadowMismatchException $e) {
            $this->assertTrue($e->legacyDecision);
            $this->assertFalse($e->newPathDecision);
        }

        // Seed the resource (idempotent) and a matching role permission +
        // assignment. The model `saved` hooks flush AccessDecision's cache,
        // so the second can() call must re-read the now-populated
        // authorization_role_permissions / authorization_role_assignments rows.
        $resource = $this->makeResource(Project::class);
        $role = $this->makeRole('proj_viewer_cache_invalidation');
        $this->attachPermission($role, $resource, 'view');
        $this->assignRole($user, $role, 'organization', $this->org->id, $this->org->id);

        // Second call: legacy grants; new path now finds a role permission
        // for (Project, 'view') and an assignment whose scope_type='organization'
        // matches the target's org_id -> grants. Both allow -> no throw.
        $this->assertTrue(
            AccessDecision::can($user, Capability::PROJECTS_VIEW, $project),
            'Second shadow call must observe the freshly inserted role permission '
            .'+ assignment and avoid throwing (cache invalidation by model hooks).'
        );
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /**
     * Build a user + project where the legacy engine grants
     * Capability::PROJECTS_VIEW on the project via a scoped role.
     *
     * @return array{0: User, 1: Project}
     */
    private function createProjectViewerWithLegacyGrant(): array
    {
        $department = Department::factory()->create(['organization_id' => $this->org->id]);
        $project = Project::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $department->id,
        ]);

        [$user, $roleDefinition] = $this->makeScopeTypeAndRoleDefinition(
            scopeKey: 'project',
            roleKey: 'proj_viewer_legacy_'.bin2hex(random_bytes(4)),
            isAdminRole: false,
            canViewAll: true,
        );

        $user->assignScopedRole(
            role: $roleDefinition->role_key,
            scopeType: 'project',
            scopeId: $project->id,
        );

        return [$user, $project];
    }

    /**
     * Build a user + org-scoped role where the legacy engine grants
     * Capability::PROJECTS_VIEW via the org-functional layer (target=null).
     *
     * @return array{0: User, 1: ScopedRoleDefinition}
     */
    private function createOrgAdminWithLegacyGrant(): array
    {
        return $this->makeScopeTypeAndRoleDefinition(
            scopeKey: ScopedRole::SCOPE_ORGANIZATION,
            roleKey: 'proj_org_admin_legacy_'.bin2hex(random_bytes(4)),
            isAdminRole: true,
            canViewAll: true,
        );
    }

    /**
     * Create a ScopeType + ScopedRoleDefinition (force-filled via DB::table to
     * work around the legacy NOT NULL columns per LR-103) and return a user
     * in the test's primary org.
     *
     * @return array{0: User, 1: ScopedRoleDefinition}
     */
    private function makeScopeTypeAndRoleDefinition(
        string $scopeKey,
        string $roleKey,
        bool $isAdminRole = false,
        bool $canEdit = false,
        bool $canDelete = false,
        bool $canViewAll = false,
        bool $canManageMembers = false,
    ): array {
        $scopeType = ScopeType::firstOrCreate(
            ['key' => $scopeKey],
            [
                'label_ar' => $scopeKey,
                'label_en' => $scopeKey,
                'model_class' => Project::class,
                'supports_hierarchy' => true,
                'is_active' => true,
                'sort_order' => 0,
            ]
        );

        // Mirror AccessDecisionTest::createScopeTypeAndRoleDefinition: derive
        // permissions[] from the legacy granular flags via the action-suffix
        // expansion the engine uses, then force-fill via DB::table because
        // ScopedRoleDefinition::$fillable omits the legacy NOT NULL columns.
        $byAction = fn (array $actions): array => array_values(array_filter(
            Capability::all(),
            function (string $capability) use ($actions) {
                $action = str_contains($capability, '.')
                    ? substr($capability, strrpos($capability, '.') + 1)
                    : $capability;

                return in_array($action, $actions, true);
            }
        ));

        $permissions = [];
        if ($canEdit) {
            $permissions = array_merge($permissions, $byAction(['edit', 'update']));
        }
        if ($canDelete) {
            $permissions = array_merge($permissions, $byAction(['delete', 'remove']));
        }
        if ($canViewAll) {
            $permissions = array_merge($permissions, $byAction(['view', 'view_all', 'view_reports']));
        }
        if ($canManageMembers) {
            $permissions = array_merge($permissions, $byAction(['manage_members', 'assign_roles']));
        }
        $permissions = array_values(array_unique($permissions));

        $attributes = [
            'scope_type_id' => $scopeType->id,
            'role_key' => $roleKey,
            'display_name' => $roleKey,
            'label_ar' => $roleKey,
            'label_en' => $roleKey,
            'is_admin_role' => $isAdminRole,
            'permissions' => json_encode($permissions),
            'is_active' => true,
            'sort_order' => 0,
            'updated_at' => now(),
        ];

        $existingId = DB::table('scoped_role_definitions')
            ->where('name', $roleKey)
            ->where('scope_type', $scopeKey)
            ->value('id');

        if ($existingId) {
            DB::table('scoped_role_definitions')->where('id', $existingId)->update($attributes);
        } else {
            $attributes['name'] = $roleKey;
            $attributes['scope_type'] = $scopeKey;
            $attributes['created_at'] = now();
            $existingId = DB::table('scoped_role_definitions')->insertGetId($attributes);
        }

        $user = User::factory()->create(['organization_id' => $this->org->id]);

        return [$user, ScopedRoleDefinition::find($existingId)];
    }

    private function makeResource(string $fqcn): AuthorizationResource
    {
        return AuthorizationResource::firstOrCreate(
            ['key' => $fqcn],
            ['label' => class_basename($fqcn)],
        );
    }

    private function makeRole(string $name): AuthorizationRole
    {
        return AuthorizationRole::firstOrCreate(
            ['name' => $name],
            ['label' => $name],
        );
    }

    private function attachPermission(
        AuthorizationRole $role,
        AuthorizationResource $resource,
        string $action,
    ): void {
        AuthorizationRolePermission::firstOrCreate([
            'authorization_role_id' => $role->id,
            'authorization_resource_id' => $resource->id,
            'action' => $action,
        ]);
    }

    private function assignRole(
        User $user,
        AuthorizationRole $role,
        string $scopeType,
        ?int $scopeId,
        ?int $organizationId,
    ): void {
        AuthorizationRoleAssignment::firstOrCreate([
            'authorization_role_id' => $role->id,
            'user_id' => $user->id,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'organization_id' => $organizationId,
        ]);
    }
}
