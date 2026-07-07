<?php

namespace Tests\Feature\Core\Authorization;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\AuthorizationRuntimeMode;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Authorization\Models\AuthorizationResource;
use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\ScopedRole;
use App\Modules\Core\Models\ScopeType;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\OVR\Enums\ReportStatus;
use App\Modules\OVR\Enums\SeverityLevel;
use App\Modules\OVR\Models\IncidentReport;
use App\Modules\OVR\Models\IncidentType;
use App\Modules\Projects\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * AdminRoleUnifiedAuthzTest -- Phase 2.1.4a.
 *
 * Ports the legacy `scoped_role_definitions.is_admin_role` shortcut onto
 * the unified authorization engine's new path (`hasNewPermission`).
 *
 * Pins (one test per pin, named to map 1:1 to the brief's "Definition of done"):
 *
 *  A. SCHEMA: migration 2026_07_05_000025 adds `authorization_roles.is_admin_role`
 *     as a boolean DEFAULT false column, idempotent on a second up() and droppable
 *     on down().
 *  B. MODEL: the AuthorizationRole Eloquent model exposes is_admin_role via
 *     fillable + boolean cast (so downstream admin / cast layers see a real bool).
 *  C. BACKFILL: migration 2026_07_05_000026 copies scoped_role_definitions.is_admin_role
 *     onto matching authorization_roles rows by `role_key`/`name`, leaves non-admin
 *     rows untouched, skips+audits when no definition matches, and is idempotent.
 *  D. NEW PATH PARITY: a user whose authorization_role_assignments row references an
 *     admin role (is_admin_role=true) is GRANTED every capability by the new path
 *     (AccessDecision::hasNewPermission), mirroring legacy definitionGrantsCapability
 *     shortcut -- even when the role has NO specific authorization_role_permissions row.
 *  E. OVR CONFIDENTIAL NON-WIDENING: the same admin role does NOT grant
 *     `Capability::OVR_CONFIDENTIAL` -- the can_view_confidential rule from
 *     AUTHZ-DECISIONS.md is preserved on the new path. is_admin_role alone is a
 *     deny for OVR confidential.
 *  F. SCOPE GATE: an admin role assignment whose scope does NOT apply to the target
 *     (e.g. assigned to a different organization) does NOT grant. The same
 *     `assignmentScopeApplies` gate the legacy path applies still applies here.
 *
 * The migration files are anonymous classes returned from
 * `require database_path(...)`; tests call up()/down() directly on the class
 * to scope the work, the same way the Phase 2.1.1 / 2.1.2 / 2.1.3 sibling
 * backfill tests do.
 */
class AdminRoleUnifiedAuthzTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_NAME_ADD = '2026_07_05_000025_add_is_admin_role_to_authorization_roles';

    private const MIGRATION_NAME_BACKFILL = '2026_07_05_000026_backfill_authorization_roles_is_admin_role';

    private const AUDIT_EVENT = 'legacy_is_admin_role_backfill_000026';

    private const AUDIT_REASON_WRITTEN = 'is_admin_role_backfilled';

    private const AUDIT_REASON_SKIPPED = 'unmappable_is_admin_role';

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Phase 2.1.4a admin-role unification test is PostgreSQL-only.');
        }
    }

    protected function tearDown(): void
    {
        AccessDecision::flushCache();
        AuthorizationRuntimeMode::reset();

        parent::tearDown();
    }

    // =====================================================================
    // A. SCHEMA: migration 000025 adds is_admin_role boolean column.
    // =====================================================================

    public function test_add_is_admin_role_migration_creates_column_with_default_false(): void
    {
        $this->runMigration('up', self::MIGRATION_NAME_ADD);

        $this->assertTrue(
            Schema::hasColumn('authorization_roles', 'is_admin_role'),
            'Migration 000025 must add the is_admin_role column to authorization_roles.'
        );

        // Probe the column metadata directly through PostgreSQL so the
        // assertion does not depend on how Laravel normalizes the
        // `column_default` value (boolean default reads back as the
        // string 'false' in `information_schema.columns`).
        $columnRow = DB::selectOne(
            'SELECT data_type, column_default, is_nullable '
            .'FROM information_schema.columns '
            ."WHERE table_name = 'authorization_roles' AND column_name = 'is_admin_role'"
        );

        $this->assertNotNull($columnRow, 'is_admin_role column metadata must be present.');
        $this->assertSame(
            'boolean',
            (string) $columnRow->data_type,
            'authorization_roles.is_admin_role must be a boolean column.'
        );
        $this->assertSame(
            'NO',
            strtoupper((string) $columnRow->is_nullable),
            'authorization_roles.is_admin_role must be NOT NULL once a default is in place.'
        );
        $this->assertSame(
            'false',
            strtolower(trim((string) $columnRow->column_default)),
            'authorization_roles.is_admin_role must default to false (non-widening default).'
        );
    }

    public function test_add_is_admin_role_migration_is_idempotent_on_second_up(): void
    {
        $this->runMigration('up', self::MIGRATION_NAME_ADD);
        $this->runMigration('up', self::MIGRATION_NAME_ADD);

        $this->assertTrue(
            Schema::hasColumn('authorization_roles', 'is_admin_role'),
            'Second up() must NOT crash; the column is preserved.'
        );
    }

    public function test_add_is_admin_role_migration_down_drops_column(): void
    {
        $this->runMigration('up', self::MIGRATION_NAME_ADD);
        $this->runMigration('down', self::MIGRATION_NAME_ADD);

        $this->assertFalse(
            Schema::hasColumn('authorization_roles', 'is_admin_role'),
            'Migration 000025 down() must drop the is_admin_role column.'
        );

        // Round-trip: re-add the column with up() to prove up() still works after down().
        $this->runMigration('up', self::MIGRATION_NAME_ADD);
        $this->assertTrue(
            Schema::hasColumn('authorization_roles', 'is_admin_role'),
            'up() must re-add the column after a down() (full round-trip).'
        );
    }

    // =====================================================================
    // B. MODEL: AuthorizationRole exposes is_admin_role via fillable + cast.
    // =====================================================================

    public function test_model_exposes_is_admin_role_via_fillable_and_boolean_cast(): void
    {
        $role = new AuthorizationRole;
        $this->assertContains('is_admin_role', $role->getFillable(),
            'AuthorizationRole fillable must include is_admin_role so the migration / seeder can write it.');

        $instance = new AuthorizationRole;
        $instance->setRawAttributes(['is_admin_role' => 1], true);
        $this->assertTrue(
            (bool) $instance->is_admin_role,
            'AuthorizationRole must cast is_admin_role to a boolean (1 -> true).'
        );

        $instance->setRawAttributes(['is_admin_role' => 0], true);
        $this->assertFalse(
            (bool) $instance->is_admin_role,
            'AuthorizationRole must cast is_admin_role to a boolean (0 -> false).'
        );

        $instance->setRawAttributes(['is_admin_role' => null], true);
        $this->assertNull(
            $instance->is_admin_role,
            'NULL is_admin_role must round-trip as null (not coerced to false at the model layer).'
        );
    }

    // =====================================================================
    // C. BACKFILL: 000026 copies is_admin_role from scoped_role_definitions.
    // =====================================================================

    public function test_backfill_marks_admin_roles_from_legacy_definition(): void
    {
        $this->seedRoleKeyDef('phase214_admin', isAdminRole: true);
        $this->seedRoleKeyDef('phase214_member', isAdminRole: false);

        $adminRow = $this->seedAuthorizationRole('phase214_admin');
        $memberRow = $this->seedAuthorizationRole('phase214_member');

        $this->runMigration('up', self::MIGRATION_NAME_BACKFILL);

        $adminRow->refresh();
        $memberRow->refresh();

        $this->assertTrue(
            (bool) $adminRow->is_admin_role,
            'AuthorizationRole for legacy is_admin_role=true definition must be backfilled to is_admin_role=true.'
        );
        $this->assertFalse(
            (bool) $memberRow->is_admin_role,
            'AuthorizationRole for legacy is_admin_role=false definition must NOT be widened to is_admin_role=true.'
        );
    }

    public function test_backfill_skips_and_audits_when_no_legacy_definition_matches(): void
    {
        // AuthorizationRole row exists, but there is NO matching
        // scoped_role_definitions row by role_key/name. The migration
        // must skip + audit, leaving is_admin_role at the column default
        // (false), not silently widen to true.
        $orphan = $this->seedAuthorizationRole('phase214_orphan');

        $before = (int) DB::table('permission_audits')
            ->where('event', self::AUDIT_EVENT)
            ->count();

        $this->runMigration('up', self::MIGRATION_NAME_BACKFILL);

        $orphan->refresh();
        $this->assertFalse(
            (bool) $orphan->is_admin_role,
            'A role with no matching scoped_role_definitions must NOT be widened to is_admin_role=true.'
        );

        $skipMarker = DB::table('permission_audits')
            ->where('event', self::AUDIT_EVENT)
            ->where('reason', self::AUDIT_REASON_SKIPPED)
            ->first();
        $this->assertNotNull(
            $skipMarker,
            'A skip audit marker must be written for a role with no matching legacy definition.'
        );
        $newValue = json_decode($skipMarker->new_value, true);
        $this->assertIsArray($newValue);
        $this->assertSame(
            self::MIGRATION_NAME_BACKFILL,
            $newValue['migration'] ?? null,
            'Skip marker must carry the migration tag.'
        );
        $this->assertSame(
            'phase214_orphan',
            $newValue['authorization_role_name'] ?? null,
            'Skip marker must carry the authorization_role_name for traceability.'
        );

        $after = (int) DB::table('permission_audits')
            ->where('event', self::AUDIT_EVENT)
            ->count();
        $this->assertGreaterThan(
            $before,
            $after,
            'A skip audit marker must be written (count must grow by at least one).'
        );
    }

    public function test_backfill_is_idempotent_on_second_up(): void
    {
        $this->seedRoleKeyDef('phase214_admin_idem', isAdminRole: true);
        $this->seedAuthorizationRole('phase214_admin_idem');

        $this->runMigration('up', self::MIGRATION_NAME_BACKFILL);
        $firstMarkerCount = (int) DB::table('permission_audits')
            ->where('event', self::AUDIT_EVENT)
            ->count();

        $this->runMigration('up', self::MIGRATION_NAME_BACKFILL);
        $secondMarkerCount = (int) DB::table('permission_audits')
            ->where('event', self::AUDIT_EVENT)
            ->count();

        $this->assertSame(
            $firstMarkerCount,
            $secondMarkerCount,
            'Second up() must NOT write a duplicate is_admin_role_backfilled audit marker.'
        );
    }

    public function test_backfill_audit_marker_carries_migration_tag_and_legacy_definition(): void
    {
        $this->seedRoleKeyDef('phase214_tagged', isAdminRole: true);
        $this->seedAuthorizationRole('phase214_tagged');

        $this->runMigration('up', self::MIGRATION_NAME_BACKFILL);

        $marker = DB::table('permission_audits')
            ->where('event', self::AUDIT_EVENT)
            ->where('reason', self::AUDIT_REASON_WRITTEN)
            ->first();
        $this->assertNotNull(
            $marker,
            'A is_admin_role_backfilled audit marker must exist for a successful backfill row.'
        );

        $newValue = json_decode($marker->new_value, true);
        $this->assertIsArray($newValue);
        $this->assertSame(
            self::MIGRATION_NAME_BACKFILL,
            $newValue['migration'] ?? null,
            'Audit marker must carry the migration tag.'
        );
        $this->assertSame(
            'phase214_tagged',
            $newValue['authorization_role_name'] ?? null,
            'Audit marker must reference the authorization_role name.'
        );
        $this->assertArrayHasKey(
            'authorization_role_id',
            $newValue,
            'Audit marker must reference the authorization_role_id.'
        );
        $this->assertArrayHasKey(
            'legacy_definition_id',
            $newValue,
            'Audit marker must reference the source scoped_role_definitions.id for traceability.'
        );
    }

    public function test_backfill_down_resets_only_own_audit_rows(): void
    {
        $this->seedRoleKeyDef('phase214_down', isAdminRole: true);
        $this->seedAuthorizationRole('phase214_down');

        $this->runMigration('up', self::MIGRATION_NAME_BACKFILL);

        $roleBefore = DB::table('authorization_roles')->where('name', 'phase214_down')->first();
        $this->assertTrue(
            (bool) $roleBefore->is_admin_role,
            'Test prerequisite: role must have is_admin_role=true after up().'
        );

        $this->runMigration('down', self::MIGRATION_NAME_BACKFILL);

        $roleAfter = DB::table('authorization_roles')->where('name', 'phase214_down')->first();
        $this->assertFalse(
            (bool) $roleAfter->is_admin_role,
            'down() must reset the role is_admin_role=false (pre-backfill state).'
        );

        $remaining = (int) DB::table('permission_audits')
            ->where('event', self::AUDIT_EVENT)
            ->count();
        $this->assertSame(
            0,
            $remaining,
            'down() must delete every audit marker this migration wrote.'
        );
    }

    // =====================================================================
    // D. NEW PATH PARITY: admin role grants every capability.
    // =====================================================================

    public function test_new_path_grants_capability_via_admin_role_assignment_without_specific_permission_row(): void
    {
        AuthorizationRuntimeMode::enableShadow();

        [$user, $project] = $this->seedAdminRoleWithNoPermissionRow();

        // The admin role assignment grants ALL capabilities on the new
        // path even though there is NO specific authorization_role_permissions
        // row for (Project, view). The legacy path grants via the
        // is_admin_role=true definition; this test pins the new path
        // doing the same so a future code path that bypasses the legacy
        // whyCan() walk and reads the new path alone still gets the grant.
        $this->assertTrue(
            AccessDecision::can($user, Capability::PROJECTS_VIEW, $project),
            'Admin role assignment must grant PROJECTS_VIEW on the new path even with no permission row.'
        );
        $this->assertTrue(
            AccessDecision::can($user, Capability::PROJECTS_EDIT, $project),
            'Admin role assignment must grant PROJECTS_EDIT on the new path (every capability).'
        );
        $this->assertTrue(
            AccessDecision::can($user, Capability::TASKS_CREATE, $project),
            'Admin role assignment must grant TASKS_CREATE on the new path (cross-resource grant).'
        );
    }

    public function test_new_path_denies_capability_when_admin_role_assignment_scope_does_not_apply(): void
    {
        AuthorizationRuntimeMode::enableShadow();

        [$user, $project] = $this->seedAdminRoleAssignedToDifferentOrg();

        // The user holds the admin role assignment, but it points at a
        // DIFFERENT organization than the target's. The scope gate must
        // deny -- same gate the rest of the new path applies. is_admin_role
        // must not silently widen across organizations.
        $this->assertFalse(
            AccessDecision::can($user, Capability::PROJECTS_VIEW, $project),
            'Admin role assignment must NOT grant when its scope does not apply to the target.'
        );
    }

    // =====================================================================
    // E. OVR CONFIDENTIAL NON-WIDENING: admin role alone does NOT grant
    //    Capability::OVR_CONFIDENTIAL. AUTHZ-DECISIONS.md rule preserved.
    // =====================================================================

    public function test_new_path_does_not_grant_ovr_confidential_via_admin_role_alone(): void
    {
        AuthorizationRuntimeMode::enableShadow();

        [$user, $report] = $this->seedAdminUserWithConfidentialIncident();

        // PIN: is_admin_role=true does NOT grant Capability::OVR_CONFIDENTIAL
        // for the incident report. The can_view_confidential rule from
        // AUTHZ-DECISIONS.md is preserved on the new path --
        // is_admin_role=true is NOT sufficient to read a confidential OVR
        // incident.
        $this->assertFalse(
            AccessDecision::can($user, Capability::OVR_CONFIDENTIAL, $report),
            'is_admin_role=true alone must NOT grant Capability::OVR_CONFIDENTIAL on the new path. '
            .'AUTHZ-DECISIONS.md carve-out preserved.'
        );

        // Sanity: legacy and new path AGREE on the OVR_CONFIDENTIAL deny
        // (both deny; SHADOW is silent). This proves my admin gate
        // carve-out does not regress the legacy sensitive-override
        // semantics.
        $this->assertFalse(
            AccessDecision::can($user, Capability::OVR_VIEW, $report),
            'Both legacy and new path must deny OVR view of a confidential report when the user has no can_view_confidential grant.'
        );
    }

    public function test_new_path_does_not_grant_ovr_view_confidential_via_admin_role_alone(): void
    {
        AuthorizationRuntimeMode::enableShadow();

        [$user, $report] = $this->seedAdminUserWithConfidentialIncident();

        // PIN: is_admin_role=true does NOT grant the LEGACY OVR confidential
        // capability string (Capability::OVR_VIEW_CONFIDENTIAL =
        // 'ovr.view_confidential') on the new path either. The
        // can_view_confidential rule from AUTHZ-DECISIONS.md applies to the
        // capability CONCEPT, not to a single string spelling -- the admin
        // shortcut must exclude BOTH the current 'ovr.confidential' and the
        // legacy 'ovr.view_confidential' names so a future refactor of the
        // inner sensitive gate inside adminRoleGrantsTarget cannot silently
        // re-introduce the widening.
        $this->assertFalse(
            AccessDecision::can($user, Capability::OVR_VIEW_CONFIDENTIAL, $report),
            'is_admin_role=true alone must NOT grant Capability::OVR_VIEW_CONFIDENTIAL on the new path. '
            .'AUTHZ-DECISIONS.md carve-out applies to both capability strings.'
        );
    }

    public function test_new_path_grants_ovr_confidential_when_admin_role_holds_explicit_permission_row(): void
    {
        AuthorizationRuntimeMode::enableShadow();

        // Counter-test: an admin role that ALSO carries the explicit
        // `ovr.confidential` capability row MUST grant OVR_CONFIDENTIAL
        // on the new path. This proves the carve-out is a narrow
        // exception, not a blanket revoke of the admin grant.
        //
        // The fixture seeds BOTH sides so SHADOW is silent (no parity
        // mismatch artifact of test wiring):
        //   - legacy: scoped_role_definitions.permissions contains
        //     'ovr.view_confidential' (the legacy Capability name the
        //     mayAccessSensitive predicate still consults), AND a
        //     model_has_scoped_roles row so the engine's legacy
        //     mayAccessSensitive opens the sensitive gate.
        //   - new path: an authorization_role_permissions pivot row
        //     with action='confidential' on the IncidentReport
        //     resource.
        // Both paths grant; the assertion is satisfied and SHADOW does
        // not throw.
        [$user, $report] = $this->seedAdminRoleWithOvrConfidentialPermissionRow();

        $this->assertTrue(
            AccessDecision::can($user, Capability::OVR_CONFIDENTIAL, $report),
            'An admin role that ALSO carries the explicit ovr.confidential permission row must grant OVR_CONFIDENTIAL.'
        );
    }

    /**
     * TDD pin: the OVR_VIEW_CONFIDENTIAL call-site exclusion in
     * `hasNewPermission` is observable ONLY when the inner sensitive
     * gate in `adminRoleGrantsTarget` does NOT already deny. The
     * existing test above uses a confidential report where the inner
     * gate already closes -- that test passes for the wrong reason
     * (or would, if the inner gate were ever refactored). This test
     * uses a NON-confidential report so `isSensitive()` returns
     * false, the inner gate is bypassed entirely, and the call-site
     * exclusion is the ONLY thing that can deny the admin shortcut.
     *
     * The user is given ONLY the new path admin grant (no legacy
     * `scoped_role_definitions` admin role), so the legacy path
     * denies too. With BOTH exclusions present, legacy=false and
     * new-path=false -> SHADOW silent, can() returns false. Remove
     * the `OVR_VIEW_CONFIDENTIAL` exclusion and the new path
     * grants via the admin shortcut while the legacy path still
     * denies -> SHADOW throws AuthzShadowMismatchException -> this
     * test fails with that exception. That is the RED proof.
     */
    public function test_new_path_ovr_view_confidential_exclusion_is_observable_independent_of_inner_sensitive_gate(): void
    {
        AuthorizationRuntimeMode::enableShadow();

        [$user, $report] = $this->seedAdminUserWithNonConfidentialIncident();

        $this->assertFalse(
            AccessDecision::can($user, Capability::OVR_VIEW_CONFIDENTIAL, $report),
            'is_admin_role=true alone must NOT grant Capability::OVR_VIEW_CONFIDENTIAL on the new path '
            .'even when the inner sensitive gate is bypassed (non-confidential target). The call-site '
            .'OVR_VIEW_CONFIDENTIAL exclusion in hasNewPermission is the only thing that keeps the admin '
            .'shortcut from widening to the legacy capability string.'
        );
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function runMigration(string $direction, string $migrationName): void
    {
        $migration = require database_path('migrations/'.$migrationName.'.php');
        $migration->{$direction}();
    }

    private function seedRoleKeyDef(string $roleKey, bool $isAdminRole): int
    {
        $projectScopeType = ScopeType::firstOrCreate(
            ['key' => ScopedRole::SCOPE_ORGANIZATION],
            [
                'label_ar' => 'organization', 'label_en' => 'Organization',
                'model_class' => Organization::class,
                'supports_hierarchy' => true, 'is_active' => true, 'sort_order' => 0,
            ]
        );

        return (int) DB::table('scoped_role_definitions')->insertGetId([
            'name' => 'organization.'.$roleKey,
            'display_name' => $roleKey,
            'scope_type' => ScopedRole::SCOPE_ORGANIZATION,
            'description' => null,
            'default_abilities' => null,
            'level' => 0,
            'is_active' => true,
            'role_key' => $roleKey,
            'label_ar' => $roleKey,
            'label_en' => $roleKey,
            'scope_type_id' => $projectScopeType->id,
            'color' => 'primary',
            'permissions' => json_encode(['projects.view']),
            'is_admin_role' => $isAdminRole,
            'sort_order' => 0,
            'reach' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedAuthorizationRole(string $roleKey): AuthorizationRole
    {
        return AuthorizationRole::firstOrCreate(
            ['name' => $roleKey],
            ['label' => $roleKey]
        );
    }

    /**
     * Seed: admin role assignment for a user on their own org, BUT
     * the authorization_role_permissions table has NO row for
     * (Project, view). The new path's admin grant must still let this
     * user view projects.
     *
     * The fixture also seeds a matching
     * `scoped_role_definitions` + `model_has_scoped_roles` pair so the
     * legacy path grants too -- without that pair, SHADOW would catch
     * a real legacy-denies / new-allows mismatch which is the artifact
     * of the test wiring, not of the engine change.
     *
     * @return array{0: User, 1: Project}
     */
    private function seedAdminRoleWithNoPermissionRow(): array
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);

        $roleKey = 'phase214_engine_admin_'.bin2hex(random_bytes(4));

        // Legacy side: seed a scoped_role_definitions row with
        // is_admin_role=true (so the legacy definitionGrantsCapability
        // shortcut fires) and a model_has_scoped_roles row scoped to
        // the org.
        $this->seedRoleKeyDef($roleKey, isAdminRole: true);
        $user = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
        ]);
        $user->assignScopedRole(
            role: $roleKey,
            scopeType: ScopedRole::SCOPE_ORGANIZATION,
            scopeId: (int) $org->id,
        );

        // New path side: same role on authorization_roles with
        // is_admin_role=true, plus an assignment scoped to the org.
        $adminRole = AuthorizationRole::firstOrCreate(
            ['name' => $roleKey],
            ['label' => $roleKey]
        );
        if (! $adminRole->is_admin_role) {
            $adminRole->is_admin_role = true;
            $adminRole->save();
        }

        AuthorizationResource::firstOrCreate(
            ['key' => Project::class],
            ['label' => 'Project']
        );

        DB::table('authorization_role_assignments')->insert([
            'authorization_role_id' => (int) $adminRole->id,
            'user_id' => (int) $user->id,
            'scope_type' => ScopedRole::SCOPE_ORGANIZATION,
            'scope_id' => (int) $org->id,
            'organization_id' => (int) $org->id,
            'inherit_to_children' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $project = Project::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
        ]);

        // Intentionally NO authorization_role_permissions row for
        // (Project, view) -- the admin gate is the only grant path on
        // the new side.

        AccessDecision::flushCache();

        return [$user, $project];
    }

    /**
     * Seed: user holds an admin role assignment pointing at a DIFFERENT
     * org than the target's. The scope gate must deny on the new path
     * even though is_admin_role=true.
     *
     * @return array{0: User, 1: Project}
     */
    private function seedAdminRoleAssignedToDifferentOrg(): array
    {
        $roleKey = 'phase214_engine_admin_crossorg_'.bin2hex(random_bytes(4));

        $adminRole = AuthorizationRole::firstOrCreate(
            ['name' => $roleKey],
            ['label' => $roleKey]
        );
        if (! $adminRole->is_admin_role) {
            $adminRole->is_admin_role = true;
            $adminRole->save();
        }

        AuthorizationResource::firstOrCreate(
            ['key' => Project::class],
            ['label' => 'Project']
        );

        $userOrg = Organization::factory()->create();
        $otherOrg = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $otherOrg->id]);

        $user = User::factory()->create([
            'organization_id' => $userOrg->id,
            'department_id' => null,
        ]);

        // Assignment points at $userOrg, but the target lives in $otherOrg.
        DB::table('authorization_role_assignments')->insert([
            'authorization_role_id' => (int) $adminRole->id,
            'user_id' => (int) $user->id,
            'scope_type' => ScopedRole::SCOPE_ORGANIZATION,
            'scope_id' => (int) $userOrg->id,
            'organization_id' => (int) $userOrg->id,
            'inherit_to_children' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $project = Project::factory()->create([
            'organization_id' => $otherOrg->id,
            'department_id' => $dept->id,
        ]);

        AccessDecision::flushCache();

        return [$user, $project];
    }

    /**
     * Seed: user with admin role assignment, confidential incident
     * report. The user is NOT the reporter and NOT assigned to the
     * report so the owner-floor does not silently grant access.
     *
     * @return array{0: User, 1: IncidentReport}
     */
    private function seedAdminUserWithConfidentialIncident(): array
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);

        $roleKey = 'phase214_engine_ovr_admin_'.bin2hex(random_bytes(4));

        // Reporter is someone OTHER than the user under test so the
        // owner-floor does not silently grant confidential access.
        $reporter = User::factory()->create(['organization_id' => $org->id, 'department_id' => $dept->id]);
        $user = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
        ]);

        // Legacy side: a scoped_role_definitions with is_admin_role=true
        // and a model_has_scoped_roles assignment. The legacy
        // definitionGrantsCapability shortcut will return true for the
        // non-confidential capability (PROJECTS_VIEW / OVR_VIEW it
        // routes through ...). The OVR_CONFIDENTIAL capability still
        // passes through the policy layer which honors the
        // can_view_confidential rule -- admin alone does NOT grant
        // confidential on the legacy path. We deliberately do NOT set
        // can_view_confidential=true here so the legacy path also
        // denies confidential, matching the new path's carve-out.
        $this->seedRoleKeyDef($roleKey, isAdminRole: true);
        $user->assignScopedRole(
            role: $roleKey,
            scopeType: ScopedRole::SCOPE_ORGANIZATION,
            scopeId: (int) $org->id,
        );

        // New path side: same role on authorization_roles with
        // is_admin_role=true.
        $adminRole = AuthorizationRole::firstOrCreate(
            ['name' => $roleKey],
            ['label' => $roleKey]
        );
        if (! $adminRole->is_admin_role) {
            $adminRole->is_admin_role = true;
            $adminRole->save();
        }

        AuthorizationResource::firstOrCreate(
            ['key' => IncidentReport::class],
            ['label' => 'IncidentReport']
        );
        AuthorizationResource::firstOrCreate(
            ['key' => Project::class],
            ['label' => 'Project']
        );

        DB::table('authorization_role_assignments')->insert([
            'authorization_role_id' => (int) $adminRole->id,
            'user_id' => (int) $user->id,
            'scope_type' => ScopedRole::SCOPE_ORGANIZATION,
            'scope_id' => (int) $org->id,
            'organization_id' => (int) $org->id,
            'inherit_to_children' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $incidentType = IncidentType::create([
            'name' => 'Test Incident',
            'name_ar' => 'حادث اختبار',
            'is_active' => true,
        ]);

        $report = IncidentReport::create([
            'organization_id' => $org->id,
            'reporter_id' => $reporter->id,
            'reporter_name' => $reporter->name,
            'reporter_email' => $reporter->email,
            'reporter_department_id' => $dept->id,
            'incident_datetime' => now(),
            'is_patient_related' => false,
            'informed_authority' => false,
            'incident_type_id' => $incidentType->id,
            'incident_description' => 'phase214a confidential test report',
            'immediate_action_required' => false,
            'severity_level' => SeverityLevel::High,
            'status' => ReportStatus::New,
            'is_confidential' => true,
        ]);

        AccessDecision::flushCache();

        return [$user, $report];
    }

    /**
     * Seed: user with admin role assignment AND an explicit
     * `ovr.confidential` authorization_role_permissions row. The
     * carve-out must let this user see confidential incidents.
     *
     * Legacy side is also seeded so SHADOW stays silent: a
     * scoped_role_definitions row carrying both `is_admin_role=true`
     * AND `permissions = ['ovr.view_confidential']` (the legacy
     * capability string the sensitive-gate predicate still uses),
     * plus a model_has_scoped_roles assignment.
     *
     * @return array{0: User, 1: IncidentReport}
     */
    private function seedAdminRoleWithOvrConfidentialPermissionRow(): array
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);

        $roleKey = 'phase214_engine_admin_explicit_ovr_'.bin2hex(random_bytes(4));
        $adminRole = AuthorizationRole::firstOrCreate(
            ['name' => $roleKey],
            ['label' => $roleKey]
        );
        if (! $adminRole->is_admin_role) {
            $adminRole->is_admin_role = true;
            $adminRole->save();
        }

        $ovrResource = AuthorizationResource::firstOrCreate(
            ['key' => IncidentReport::class],
            ['label' => 'IncidentReport']
        );

        // Explicit `ovr.confidential` pivot row for this admin role
        // on the new path.
        DB::table('authorization_role_permissions')->insert([
            'authorization_role_id' => (int) $adminRole->id,
            'authorization_resource_id' => (int) $ovrResource->id,
            'action' => 'confidential',
        ]);

        // Legacy side: scoped_role_definitions with the legacy capability
        // string + is_admin_role=true, plus a model_has_scoped_roles
        // row. The mayAccessSensitive predicate opens the sensitive
        // gate via the legacy permissions[].
        $orgScopeType = ScopeType::firstOrCreate(
            ['key' => ScopedRole::SCOPE_ORGANIZATION],
            [
                'label_ar' => 'organization', 'label_en' => 'Organization',
                'model_class' => Organization::class,
                'supports_hierarchy' => true, 'is_active' => true, 'sort_order' => 0,
            ]
        );
        DB::table('scoped_role_definitions')->insert([
            // scoped_role_definitions.name is varchar(50); keep the
            // prefix short enough that the combined key fits.
            'name' => 'org.phase214_explicit_'.bin2hex(random_bytes(4)),
            'display_name' => $roleKey,
            'scope_type' => ScopedRole::SCOPE_ORGANIZATION,
            'description' => null,
            'default_abilities' => null,
            'level' => 0,
            'is_active' => true,
            'role_key' => $roleKey,
            'label_ar' => $roleKey,
            'label_en' => $roleKey,
            'scope_type_id' => $orgScopeType->id,
            'color' => 'primary',
            'permissions' => json_encode([Capability::OVR_VIEW_CONFIDENTIAL]),
            'is_admin_role' => true,
            'sort_order' => 0,
            'reach' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
        ]);
        $user->assignScopedRole(
            role: $roleKey,
            scopeType: ScopedRole::SCOPE_ORGANIZATION,
            scopeId: (int) $org->id,
        );

        DB::table('authorization_role_assignments')->insert([
            'authorization_role_id' => (int) $adminRole->id,
            'user_id' => (int) $user->id,
            'scope_type' => ScopedRole::SCOPE_ORGANIZATION,
            'scope_id' => (int) $org->id,
            'organization_id' => (int) $org->id,
            'inherit_to_children' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $incidentType = IncidentType::create([
            'name' => 'Test Incident 2',
            'name_ar' => 'حادث اختبار 2',
            'is_active' => true,
        ]);

        $reporter = User::factory()->create(['organization_id' => $org->id, 'department_id' => $dept->id]);

        $report = IncidentReport::create([
            'organization_id' => $org->id,
            'reporter_id' => $reporter->id,
            'reporter_name' => $reporter->name,
            'reporter_email' => $reporter->email,
            'reporter_department_id' => $dept->id,
            'incident_datetime' => now(),
            'is_patient_related' => false,
            'informed_authority' => false,
            'incident_type_id' => $incidentType->id,
            'incident_description' => 'phase214a confidential test report',
            'immediate_action_required' => false,
            'severity_level' => SeverityLevel::High,
            'status' => ReportStatus::New,
            'is_confidential' => true,
        ]);

        AccessDecision::flushCache();

        return [$user, $report];
    }

    /**
     * Seed: user with ONLY the new path admin role assignment (no
     * legacy `scoped_role_definitions` admin role, no
     * `model_has_scoped_roles` row), and a NON-confidential incident
     * report (`is_confidential = false`).
     *
     * The two key choices:
     *   1. NO legacy admin role. The legacy `whyCan()` path has no
     *      admin grant to fall back on, so it returns `false` via
     *      the `none` layer. That makes a SHADOW mismatch observable
     *      if the new path grants.
     *   2. NON-confidential report. `IncidentReport::isSensitive()`
     *      returns false, so the inner sensitive gate inside
     *      `adminRoleGrantsTarget` is bypassed. The call-site
     *      `OVR_VIEW_CONFIDENTIAL` exclusion in `hasNewPermission` is
     *      therefore the ONLY thing that can keep the admin shortcut
     *      from granting the legacy capability string -- the
     *      observable TDD pin.
     *
     * @return array{0: User, 1: IncidentReport}
     */
    private function seedAdminUserWithNonConfidentialIncident(): array
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);

        $roleKey = 'phase214_engine_ovr_admin_nonconf_'.bin2hex(random_bytes(4));

        // Reporter is someone OTHER than the user under test so the
        // owner-floor does not silently grant access.
        $reporter = User::factory()->create(['organization_id' => $org->id, 'department_id' => $dept->id]);
        $user = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
        ]);

        // INTENTIONALLY no seedRoleKeyDef() call and no
        // assignScopedRole() call -- the user holds NO legacy admin
        // role, so the legacy path denies this OVR_VIEW_CONFIDENTIAL
        // call. This is what makes the new-path exclusion observable
        // under SHADOW: any new-path grant surfaces as a mismatch.

        // New path side: same role on authorization_roles with
        // is_admin_role=true, plus an org-scope assignment whose scope
        // applies to the target.
        $adminRole = AuthorizationRole::firstOrCreate(
            ['name' => $roleKey],
            ['label' => $roleKey]
        );
        if (! $adminRole->is_admin_role) {
            $adminRole->is_admin_role = true;
            $adminRole->save();
        }

        AuthorizationResource::firstOrCreate(
            ['key' => IncidentReport::class],
            ['label' => 'IncidentReport']
        );

        DB::table('authorization_role_assignments')->insert([
            'authorization_role_id' => (int) $adminRole->id,
            'user_id' => (int) $user->id,
            'scope_type' => ScopedRole::SCOPE_ORGANIZATION,
            'scope_id' => (int) $org->id,
            'organization_id' => (int) $org->id,
            'inherit_to_children' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $incidentType = IncidentType::create([
            'name' => 'Test Incident Non-Confidential',
            'name_ar' => 'حادث اختبار غير سري',
            'is_active' => true,
        ]);

        // KEY: is_confidential=false so isSensitive() returns false
        // and the inner sensitive gate in adminRoleGrantsTarget is
        // bypassed. The call-site OVR_VIEW_CONFIDENTIAL exclusion in
        // hasNewPermission is therefore the sole defense.
        $report = IncidentReport::create([
            'organization_id' => $org->id,
            'reporter_id' => $reporter->id,
            'reporter_name' => $reporter->name,
            'reporter_email' => $reporter->email,
            'reporter_department_id' => $dept->id,
            'incident_datetime' => now(),
            'is_patient_related' => false,
            'informed_authority' => false,
            'incident_type_id' => $incidentType->id,
            'incident_description' => 'phase214a non-confidential test report',
            'immediate_action_required' => false,
            'severity_level' => SeverityLevel::High,
            'status' => ReportStatus::New,
            'is_confidential' => false,
        ]);

        AccessDecision::flushCache();

        return [$user, $report];
    }
}
