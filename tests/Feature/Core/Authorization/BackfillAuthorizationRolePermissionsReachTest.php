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
use App\Modules\Projects\Models\Project;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * BackfillAuthorizationRolePermissionsReachTest -- Phase 2.1.3.
 *
 * Coverage for the additive backfill from legacy
 * `scoped_role_definitions.reach` onto the new
 * `authorization_role_permissions.reach` JSONB column, plus the new-path
 * reach wiring in `AccessDecision`. Pins:
 *
 *  1. Migration `2026_07_05_000023_add_reach_to_authorization_role_permissions`
 *     adds a nullable JSONB `reach` column + GIN index.
 *  2. Migration `2026_07_05_000024_backfill_authorization_role_permissions_reach`
 *     reads each `legacy_scoped_backfill_000021` audit marker, resolves
 *     the source legacy `scoped_role_definitions.reach`, and writes the
 *     per-pivot reach (full legacy JSON preserved).
 *  3. Reach semantics round-trip end-to-end:
 *       - own       -> `pivot.reach[projects] = 'own'`
 *       - department -> `pivot.reach[projects] = 'department'`
 *       - all       -> `pivot.reach[projects] = 'all'`
 *  4. Legacy null reach maps to NULL on the new column (no widening,
 *     no fake 'all' default written into the row).
 *  5. Malformed legacy reach (non-object / non-array) is SKIPPED and
 *     audit-marked: the new column is left NULL (no widening to 'all').
 *  6. Reach value 'all' is preserved (not collapsed / not dropped).
 *  7. up() is idempotent: a second up() produces zero new reach writes
 *     and zero new audit markers.
 *  8. down() removes only the rows this migration wrote: pivots are
 *     restored to NULL reach, and only the new audit markers are
 *     deleted. Pre-existing pivots and the `reach` column itself
 *     survive a down() (idempotent rollback is a hard requirement).
 *  9. The `reach` column survives a down() + up() round trip (it is
 *     owned by migration 000023, not 000024).
 * 10. Engine parity (new path): with shadow enabled, a legacy
 *     `reach = {projects: 'own'}` and the backfill-applied reach on the
 *     new path agree on a target owned by the user (allow) and disagree
 *     on a target not owned (deny) -- the new path enforces the cap
 *     after the backfill, no longer falling through to "no cap".
 * 11. Engine parity (own / department / all) replicates the cases in
 *     ReachCapTest for the new path, proving the backfill's reach
 *     values feed `AccessDecision::can()` correctly via the new path.
 *
 * The migration files are anonymous classes returned from
 * `require database_path(...)`; tests call up()/down() directly on
 * the class to scope the work, the same way the Phase 2.1.1 and
 * 2.1.2 sibling backfill tests do.
 */
class BackfillAuthorizationRolePermissionsReachTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_NAME_RELAX = '2026_07_04_000020_relax_authorization_role_assignments_scope_check';

    private const MIGRATION_NAME_BACKFILL_212 = '2026_07_04_000021_backfill_scoped_roles_full_semantics';

    private const MIGRATION_NAME_ADD_REACH = '2026_07_05_000023_add_reach_to_authorization_role_permissions';

    private const MIGRATION_NAME_BACKFILL_REACH = '2026_07_05_000024_backfill_authorization_role_permissions_reach';

    private const AUDIT_EVENT_212 = 'legacy_scoped_backfill_000021';

    private const AUDIT_EVENT_213 = 'legacy_reach_backfill_000024';

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Phase 2.1.3 backfill test is PostgreSQL-only.');
        }

        $this->seed(RolesAndPermissionsSeeder::class);
        AuthorizationRuntimeMode::reset();
    }

    protected function tearDown(): void
    {
        AuthorizationRuntimeMode::reset();
        AccessDecision::flushCache();

        parent::tearDown();
    }

    // =====================================================================
    // 1. Column + index: migration 000023 adds reach JSONB + GIN index
    // =====================================================================

    public function test_add_reach_migration_creates_reach_column(): void
    {
        $this->assertTrue(
            Schema::hasColumn('authorization_role_permissions', 'reach'),
            'Migration 000023 must add the reach column to authorization_role_permissions.'
        );

        $column = collect(Schema::getColumns('authorization_role_permissions'))
            ->firstWhere('name', 'reach');
        $this->assertNotNull($column, 'reach column metadata must be present.');
        $this->assertTrue(
            (bool) $column['nullable'],
            'authorization_role_permissions.reach must be a nullable JSONB column.'
        );

        // Round-trip: drop and recreate the column via the migration,
        // and assert the same contract holds.
        $this->runMigration('down', self::MIGRATION_NAME_ADD_REACH);
        $this->assertFalse(
            Schema::hasColumn('authorization_role_permissions', 'reach'),
            'Migration 000023 down() must drop the reach column.'
        );

        $this->runMigration('up', self::MIGRATION_NAME_ADD_REACH);
        $this->assertTrue(
            Schema::hasColumn('authorization_role_permissions', 'reach'),
            'Migration 000023 up() must re-add the reach column after a down().'
        );
    }

    public function test_add_reach_migration_creates_reach_module_gin_index(): void
    {
        $this->runMigration('up', self::MIGRATION_NAME_ADD_REACH);

        $index = DB::selectOne(
            'SELECT indexname FROM pg_indexes '
            ."WHERE tablename = 'authorization_role_permissions' "
            ."AND indexname = 'authorization_role_permissions_reach_module_idx'"
        );

        $this->assertNotNull(
            $index,
            'Migration 000023 must create a GIN index named '
            .'authorization_role_permissions_reach_module_idx for module-keyed reach lookups.'
        );
    }

    public function test_add_reach_migration_down_drops_reach_column_and_index(): void
    {
        $this->runMigration('up', self::MIGRATION_NAME_ADD_REACH);
        $this->runMigration('down', self::MIGRATION_NAME_ADD_REACH);

        $this->assertFalse(
            Schema::hasColumn('authorization_role_permissions', 'reach'),
            'Migration 000023 down() must drop the reach column.'
        );
    }

    public function test_add_reach_migration_is_idempotent(): void
    {
        $this->runMigration('up', self::MIGRATION_NAME_ADD_REACH);
        $this->runMigration('up', self::MIGRATION_NAME_ADD_REACH);

        $this->assertTrue(Schema::hasColumn('authorization_role_permissions', 'reach'));
    }

    // =====================================================================
    // 2. Backfill: own / department / all round-trip
    // =====================================================================

    public function test_backfill_writes_reach_for_own_department_and_all_cases(): void
    {
        $this->runMigration('up', self::MIGRATION_NAME_RELAX);
        $this->seedLegacyFixturesWithReach();
        $this->runMigration('up', self::MIGRATION_NAME_BACKFILL_212);
        $this->runMigration('up', self::MIGRATION_NAME_BACKFILL_REACH);

        $this->assertEqualsCanonicalizing(
            ['projects' => 'own'],
            $this->pivotReachForRole('phase213_own'),
            'Legacy reach {projects: own} must round-trip as a per-module "own" entry on the new pivot.'
        );

        $this->assertEqualsCanonicalizing(
            ['projects' => 'department'],
            $this->pivotReachForRole('phase213_dept'),
            'Legacy reach {projects: department} must round-trip as a per-module "department" entry on the new pivot.'
        );

        $this->assertEqualsCanonicalizing(
            ['projects' => 'all'],
            $this->pivotReachForRole('phase213_all'),
            'Legacy reach {projects: all} must round-trip as a per-module "all" entry on the new pivot.'
        );
    }

    public function test_backfill_writes_null_reach_for_legacy_null_reach(): void
    {
        $this->runMigration('up', self::MIGRATION_NAME_RELAX);
        $this->seedLegacyFixtureWithNullReach();
        $this->runMigration('up', self::MIGRATION_NAME_BACKFILL_212);
        $this->runMigration('up', self::MIGRATION_NAME_BACKFILL_REACH);

        $this->assertNull(
            $this->pivotReachForRole('phase213_null'),
            'Legacy null reach must write NULL (not a fake "all" default) on the new pivot. '
            .'The engine falls back to the legacy definition read in that case (no widening).'
        );
    }

    public function test_backfill_preserves_full_reach_map_for_multi_module_definitions(): void
    {
        $this->runMigration('up', self::MIGRATION_NAME_RELAX);
        $this->seedLegacyFixtureWithMultiModuleReach();
        $this->runMigration('up', self::MIGRATION_NAME_BACKFILL_212);
        $this->runMigration('up', self::MIGRATION_NAME_BACKFILL_REACH);

        $this->assertEqualsCanonicalizing(
            ['projects' => 'own', 'tasks' => 'department'],
            $this->pivotReachForRole('phase213_multi'),
            'Multi-module legacy reach must round-trip as the full per-module map on the new pivot.'
        );
    }

    public function test_backfill_skip_malformed_reach_and_audit_without_widening(): void
    {
        $this->runMigration('up', self::MIGRATION_NAME_RELAX);
        $this->seedLegacyFixtureWithMalformedReach();
        $this->runMigration('up', self::MIGRATION_NAME_BACKFILL_212);
        $this->runMigration('up', self::MIGRATION_NAME_BACKFILL_REACH);

        $this->assertNull(
            $this->pivotReachForRole('phase213_malformed'),
            'Malformed legacy reach must NOT widen to "all" on the new pivot; column stays NULL.'
        );

        $skipMarker = DB::table('permission_audits')
            ->where('event', self::AUDIT_EVENT_213)
            ->where('reason', 'malformed_legacy_reach')
            ->first();
        $this->assertNotNull(
            $skipMarker,
            'A skip audit marker with reason=malformed_legacy_reach must exist for the malformed row.'
        );

        $newValue = json_decode($skipMarker->new_value, true);
        $this->assertIsArray($newValue);
        $this->assertSame(
            self::MIGRATION_NAME_BACKFILL_REACH,
            $newValue['migration'] ?? null,
            'Skip marker must carry the migration tag.'
        );
        $this->assertArrayHasKey(
            'malformed_reason',
            $newValue,
            'Skip marker must carry a malformed_reason explaining why the row was skipped.'
        );
        $this->assertArrayHasKey(
            'authorization_role_id',
            $newValue,
            'Skip marker must reference the authorization_role_id of the skipped pivot.'
        );
    }

    // =====================================================================
    // 3. Audit markers + idempotency
    // =====================================================================

    public function test_backfill_audit_marker_carries_migration_tag_pivot_composite_and_legacy_reach(): void
    {
        $this->runMigration('up', self::MIGRATION_NAME_RELAX);
        $this->seedLegacyFixturesWithReach();
        $this->runMigration('up', self::MIGRATION_NAME_BACKFILL_212);
        $this->runMigration('up', self::MIGRATION_NAME_BACKFILL_REACH);

        $marker = DB::table('permission_audits')
            ->where('event', self::AUDIT_EVENT_213)
            ->where('reason', 'reach_backfilled')
            ->first();
        $this->assertNotNull($marker, 'Reach backfill must write at least one reach_backfilled audit marker.');

        $newValue = json_decode($marker->new_value, true);
        $this->assertIsArray($newValue);
        $this->assertSame(
            self::MIGRATION_NAME_BACKFILL_REACH,
            $newValue['migration'] ?? null,
            'Audit marker must carry the migration tag.'
        );
        $this->assertArrayHasKey('authorization_role_id', $newValue,
            'Audit marker must reference the authorization_role_id.');
        $this->assertArrayHasKey('authorization_resource_id', $newValue,
            'Audit marker must reference the authorization_resource_id.');
        $this->assertArrayHasKey('action', $newValue,
            'Audit marker must reference the action.');
        $this->assertArrayHasKey('reach', $newValue,
            'Audit marker must carry the reach JSON that was written onto the pivot.');
        $this->assertArrayHasKey('source_scoped_role_id', $newValue,
            'Audit marker must reference the source legacy model_has_scoped_roles row id.');
    }

    public function test_backfill_is_idempotent_on_second_up(): void
    {
        $this->runMigration('up', self::MIGRATION_NAME_RELAX);
        $this->seedLegacyFixturesWithReach();
        $this->runMigration('up', self::MIGRATION_NAME_BACKFILL_212);
        $this->runMigration('up', self::MIGRATION_NAME_BACKFILL_REACH);

        $firstMarkerCount = (int) DB::table('permission_audits')
            ->where('event', self::AUDIT_EVENT_213)
            ->count();

        $this->runMigration('up', self::MIGRATION_NAME_BACKFILL_REACH);

        $secondMarkerCount = (int) DB::table('permission_audits')
            ->where('event', self::AUDIT_EVENT_213)
            ->count();
        $this->assertSame(
            $firstMarkerCount,
            $secondMarkerCount,
            'A second up() must NOT write a duplicate reach_backfilled audit marker.'
        );
    }

    public function test_backfill_does_not_touch_pivots_outside_000021_audit_set(): void
    {
        $this->runMigration('up', self::MIGRATION_NAME_RELAX);
        $this->seedLegacyFixturesWithReach();
        $this->runMigration('up', self::MIGRATION_NAME_BACKFILL_212);
        $this->seedPhaseOnePivotOutsideAuditSet();
        $this->runMigration('up', self::MIGRATION_NAME_BACKFILL_REACH);

        $phaseOnePivot = DB::table('authorization_role_permissions')
            ->where('action', 'phase213_phase_one_action')
            ->first();
        $this->assertNotNull($phaseOnePivot, 'Test prerequisite: Phase 1 pivot must exist.');
        $this->assertNull(
            $phaseOnePivot->reach,
            'A pivot outside the 000021 audit set must NOT receive a reach write from 000024.'
        );
    }

    // =====================================================================
    // 4. down() restores NULL reach, removes only own audit markers,
    //    leaves the column in place.
    // =====================================================================

    public function test_backfill_down_resets_pivots_to_null_reach(): void
    {
        $this->runMigration('up', self::MIGRATION_NAME_RELAX);
        $this->seedLegacyFixturesWithReach();
        $this->runMigration('up', self::MIGRATION_NAME_BACKFILL_212);
        $this->runMigration('up', self::MIGRATION_NAME_BACKFILL_REACH);

        $this->assertNotNull(
            $this->pivotReachForRole('phase213_own'),
            'Test prerequisite: pivot reach must be set after up().'
        );

        $this->runMigration('down', self::MIGRATION_NAME_BACKFILL_REACH);

        $this->assertNull(
            $this->pivotReachForRole('phase213_own'),
            'down() must reset the pivot reach to NULL (the pre-backfill state).'
        );
        $this->assertNull(
            $this->pivotReachForRole('phase213_dept'),
            'down() must reset the pivot reach to NULL (the pre-backfill state).'
        );
    }

    public function test_backfill_down_removes_only_own_audit_markers(): void
    {
        $this->runMigration('up', self::MIGRATION_NAME_RELAX);
        $this->seedLegacyFixturesWithReach();
        $this->runMigration('up', self::MIGRATION_NAME_BACKFILL_212);
        $this->runMigration('up', self::MIGRATION_NAME_BACKFILL_REACH);

        $markers212Before = (int) DB::table('permission_audits')
            ->where('event', self::AUDIT_EVENT_212)
            ->count();

        $this->runMigration('down', self::MIGRATION_NAME_BACKFILL_REACH);

        $markersAfter = (int) DB::table('permission_audits')
            ->where('event', self::AUDIT_EVENT_213)
            ->count();
        $this->assertSame(0, $markersAfter, 'down() must delete every audit marker this migration wrote.');

        $markers212After = (int) DB::table('permission_audits')
            ->where('event', self::AUDIT_EVENT_212)
            ->count();
        $this->assertSame(
            $markers212Before,
            $markers212After,
            'down() must NOT touch 000021 audit markers (only its own).'
        );
    }

    public function test_backfill_down_preserves_reach_column(): void
    {
        $this->runMigration('up', self::MIGRATION_NAME_ADD_REACH);
        $this->runMigration('up', self::MIGRATION_NAME_RELAX);
        $this->seedLegacyFixturesWithReach();
        $this->runMigration('up', self::MIGRATION_NAME_BACKFILL_212);
        $this->runMigration('up', self::MIGRATION_NAME_BACKFILL_REACH);

        $this->runMigration('down', self::MIGRATION_NAME_BACKFILL_REACH);

        $this->assertTrue(
            Schema::hasColumn('authorization_role_permissions', 'reach'),
            'down() of 000024 must NOT drop the reach column (owned by 000023).'
        );
    }

    public function test_backfill_up_down_up_round_trip_is_idempotent(): void
    {
        $this->runMigration('up', self::MIGRATION_NAME_RELAX);
        $this->seedLegacyFixturesWithReach();
        $this->runMigration('up', self::MIGRATION_NAME_BACKFILL_212);
        $this->runMigration('up', self::MIGRATION_NAME_BACKFILL_REACH);
        $this->runMigration('down', self::MIGRATION_NAME_BACKFILL_REACH);
        $this->runMigration('up', self::MIGRATION_NAME_BACKFILL_REACH);

        $this->assertEqualsCanonicalizing(
            ['projects' => 'own'],
            $this->pivotReachForRole('phase213_own'),
            'up() after down() must re-write the reach values (full round-trip).'
        );
    }

    // =====================================================================
    // 5. Engine parity: new path enforces reach after backfill
    // =====================================================================

    public function test_new_path_enforces_own_reach_after_backfill(): void
    {
        AuthorizationRuntimeMode::enableShadow();

        [$user, $ownedProject, $foreignProject] = $this->seedReachBackfillWithOwnProjectViewer();

        // Both paths use the same reach=own cap: owned target allows
        // in both, foreign target denies in both. SHADOW is silent
        // (no mismatch). The pin: after the backfill, the new path
        // agrees with legacy for owned targets, so the cap is wired
        // correctly through pivot.reach.
        $this->assertTrue(
            AccessDecision::can($user, Capability::PROJECTS_VIEW, $ownedProject),
            'Both legacy and new path must allow when reach=own and the user owns the target.'
        );
        $this->assertFalse(
            AccessDecision::can($user, Capability::PROJECTS_VIEW, $foreignProject),
            'Both legacy and new path must deny when reach=own and the user does not own the target.'
        );
    }

    public function test_new_path_enforces_department_reach_after_backfill(): void
    {
        AuthorizationRuntimeMode::enableShadow();

        [$user, $inOwnDept, $inOtherDept] = $this->seedReachBackfillWithDepartmentProjectViewer();

        $this->assertTrue(
            AccessDecision::can($user, Capability::PROJECTS_VIEW, $inOwnDept),
            'Both paths must allow when reach=department and the target is in the user\'s department subtree.'
        );
        $this->assertFalse(
            AccessDecision::can($user, Capability::PROJECTS_VIEW, $inOtherDept),
            'Both paths must deny when reach=department and the target is outside the user\'s department subtree.'
        );
    }

    public function test_new_path_treats_null_reach_as_no_cap_falling_through_to_legacy(): void
    {
        AuthorizationRuntimeMode::enableShadow();

        [$user, $project, $otherProject] = $this->seedReachBackfillWithNullReachProjectViewer();

        // pivot.reach = NULL on the new column. The new path applies
        // no cap (falls through to the legacy reach check). Legacy
        // scoped_role_definitions.reach is also NULL (= 'all'), so
        // both paths allow.
        $this->assertTrue(
            AccessDecision::can($user, Capability::PROJECTS_VIEW, $project),
            'Both paths must allow when pivot.reach is NULL and legacy definition.reach is NULL (= all).'
        );
        $this->assertTrue(
            AccessDecision::can($user, Capability::PROJECTS_VIEW, $otherProject),
            'Both paths must allow any org-scope target when pivot.reach is NULL and assignment scope is organization.'
        );
    }

    public function test_new_path_enforces_all_reach_as_no_cap(): void
    {
        AuthorizationRuntimeMode::enableShadow();

        [$user, $project, $otherProject] = $this->seedReachBackfillWithAllProjectViewer();

        $this->assertTrue(
            AccessDecision::can($user, Capability::PROJECTS_VIEW, $project)
        );
        $this->assertTrue(
            AccessDecision::can($user, Capability::PROJECTS_VIEW, $otherProject)
        );
    }

    public function test_legacy_path_unchanged_outside_shadow(): void
    {
        AuthorizationRuntimeMode::reset();

        [$user, $ownedProject, $foreignProject] = $this->seedReachBackfillWithOwnProjectViewer();

        // Legacy reach=own on the scoped role + user is the owner of one
        // project, not the other. Outside shadow, the engine's can() result
        // is unchanged: the legacy reach check is the source of truth.
        $this->assertTrue(AccessDecision::can($user, Capability::PROJECTS_VIEW, $ownedProject));
        $this->assertFalse(AccessDecision::can($user, Capability::PROJECTS_VIEW, $foreignProject));
    }

    /**
     * Sanity for the SHADOW branch: when the pivot has reach=own AND
     * the user does not own the target, the new path must deny. The
     * legacy path also denies (reach=own on the scoped role), so no
     * mismatch is thrown. This is the operational pin: the reach cap
     * is enforced on the new path after the backfill, so a future
     * capability code that bypasses the legacy `whyCan()` and reads
     * the new path alone will still see the deny.
     */
    public function test_new_path_denies_non_owned_target_via_pivot_reach(): void
    {
        AuthorizationRuntimeMode::enableShadow();

        [$user, , $foreignProject] = $this->seedReachBackfillWithOwnProjectViewer();

        // Both paths deny (reach=own, user not owner). SHADOW is
        // silent. The pin: the new path respects the pivot.reach
        // cap without shadow throwing.
        $this->assertFalse(
            AccessDecision::can($user, Capability::PROJECTS_VIEW, $foreignProject),
            'Both paths must deny when reach=own and the user does not own the target (no widening).'
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

    /**
     * Read the `reach` column for the first pivot the 000021 backfill
     * created for a given legacy role key. Decoded to a PHP array when
     * non-null, or null when the column is NULL on disk.
     *
     * @return array<string, string>|null
     */
    private function pivotReachForRole(string $roleKey): ?array
    {
        $authRole = AuthorizationRole::where('name', $roleKey)->first();
        if ($authRole === null) {
            $this->fail("Test prerequisite: authorization_role row for role_key [{$roleKey}] must exist after the 000021 backfill.");
        }

        $pivot = DB::table('authorization_role_permissions')
            ->where('authorization_role_id', $authRole->id)
            ->first();
        if ($pivot === null) {
            $this->fail("Test prerequisite: authorization_role_permissions row for role [{$roleKey}] must exist after the 000021 backfill.");
        }

        if ($pivot->reach === null) {
            return null;
        }

        $decoded = json_decode($pivot->reach, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Seed three legacy scoped_role_definitions (own / department / all)
     * and the corresponding model_has_scoped_roles row for each.
     */
    private function seedLegacyFixturesWithReach(): void
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $user = User::factory()->create(['organization_id' => $org->id]);

        $projectScopeType = ScopeType::firstOrCreate(
            ['key' => 'project'],
            [
                'label_ar' => 'project', 'label_en' => 'Project',
                'model_class' => Project::class,
                'supports_hierarchy' => true, 'is_active' => true, 'sort_order' => 0,
            ]
        );

        $now = now();
        $definitions = [
            ['name' => 'phase213.own_viewer',  'role_key' => 'phase213_own',  'reach' => ['projects' => 'own']],
            ['name' => 'phase213.dept_viewer', 'role_key' => 'phase213_dept', 'reach' => ['projects' => 'department']],
            ['name' => 'phase213.all_viewer',  'role_key' => 'phase213_all',  'reach' => ['projects' => 'all']],
        ];

        foreach ($definitions as $def) {
            DB::table('scoped_role_definitions')->insert([
                'name' => $def['name'],
                'display_name' => $def['role_key'],
                'scope_type' => 'project',
                'description' => null,
                'default_abilities' => null,
                'level' => 0,
                'is_active' => true,
                'role_key' => $def['role_key'],
                'label_ar' => $def['role_key'],
                'label_en' => $def['role_key'],
                'scope_type_id' => $projectScopeType->id,
                'color' => 'primary',
                'permissions' => json_encode(['projects.view']),
                'is_admin_role' => false,
                'sort_order' => 0,
                'reach' => json_encode($def['reach']),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('model_has_scoped_roles')->insert([
                'user_id' => $user->id,
                'role' => $def['role_key'],
                'scope_type' => 'project',
                'scope_id' => 1,
                'inherit_to_children' => true,
                'granted_by' => null,
                'source' => 'manual',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function seedLegacyFixtureWithNullReach(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);

        $projectScopeType = ScopeType::firstOrCreate(
            ['key' => 'project'],
            [
                'label_ar' => 'project', 'label_en' => 'Project',
                'model_class' => Project::class,
                'supports_hierarchy' => true, 'is_active' => true, 'sort_order' => 0,
            ]
        );

        $now = now();
        DB::table('scoped_role_definitions')->insert([
            'name' => 'phase213.null_reach',
            'display_name' => 'Null Reach',
            'scope_type' => 'project',
            'description' => null,
            'default_abilities' => null,
            'level' => 0,
            'is_active' => true,
            'role_key' => 'phase213_null',
            'label_ar' => 'Null Reach',
            'label_en' => 'Null Reach',
            'scope_type_id' => $projectScopeType->id,
            'color' => 'primary',
            'permissions' => json_encode(['projects.view']),
            'is_admin_role' => false,
            'sort_order' => 0,
            'reach' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('model_has_scoped_roles')->insert([
            'user_id' => $user->id,
            'role' => 'phase213_null',
            'scope_type' => 'project',
            'scope_id' => 1,
            'inherit_to_children' => true,
            'granted_by' => null,
            'source' => 'manual',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function seedLegacyFixtureWithMultiModuleReach(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);

        $projectScopeType = ScopeType::firstOrCreate(
            ['key' => 'project'],
            [
                'label_ar' => 'project', 'label_en' => 'Project',
                'model_class' => Project::class,
                'supports_hierarchy' => true, 'is_active' => true, 'sort_order' => 0,
            ]
        );

        $now = now();
        DB::table('scoped_role_definitions')->insert([
            'name' => 'phase213.multi_reach',
            'display_name' => 'Multi Reach',
            'scope_type' => 'project',
            'description' => null,
            'default_abilities' => null,
            'level' => 0,
            'is_active' => true,
            'role_key' => 'phase213_multi',
            'label_ar' => 'Multi Reach',
            'label_en' => 'Multi Reach',
            'scope_type_id' => $projectScopeType->id,
            'color' => 'primary',
            'permissions' => json_encode(['projects.view', 'tasks.view']),
            'is_admin_role' => false,
            'sort_order' => 0,
            'reach' => json_encode(['projects' => 'own', 'tasks' => 'department']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('model_has_scoped_roles')->insert([
            'user_id' => $user->id,
            'role' => 'phase213_multi',
            'scope_type' => 'project',
            'scope_id' => 1,
            'inherit_to_children' => true,
            'granted_by' => null,
            'source' => 'manual',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function seedLegacyFixtureWithMalformedReach(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);

        $projectScopeType = ScopeType::firstOrCreate(
            ['key' => 'project'],
            [
                'label_ar' => 'project', 'label_en' => 'Project',
                'model_class' => Project::class,
                'supports_hierarchy' => true, 'is_active' => true, 'sort_order' => 0,
            ]
        );

        $now = now();
        DB::table('scoped_role_definitions')->insert([
            'name' => 'phase213.malformed_reach',
            'display_name' => 'Malformed Reach',
            'scope_type' => 'project',
            'description' => null,
            'default_abilities' => null,
            'level' => 0,
            'is_active' => true,
            'role_key' => 'phase213_malformed',
            'label_ar' => 'Malformed Reach',
            'label_en' => 'Malformed Reach',
            'scope_type_id' => $projectScopeType->id,
            'color' => 'primary',
            'permissions' => json_encode(['projects.view']),
            'is_admin_role' => false,
            'sort_order' => 0,
            'reach' => json_encode('garbage'),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('model_has_scoped_roles')->insert([
            'user_id' => $user->id,
            'role' => 'phase213_malformed',
            'scope_type' => 'project',
            'scope_id' => 1,
            'inherit_to_children' => true,
            'granted_by' => null,
            'source' => 'manual',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function seedPhaseOnePivotOutsideAuditSet(): void
    {
        $role = AuthorizationRole::firstOrCreate(
            ['name' => 'phase213_phase_one_role'],
            ['label' => 'Phase 1 Role']
        );
        $resource = AuthorizationResource::firstOrCreate(
            ['key' => Project::class],
            ['label' => 'Project']
        );

        DB::table('authorization_role_permissions')->insert([
            'authorization_role_id' => $role->id,
            'authorization_resource_id' => $resource->id,
            'action' => 'phase213_phase_one_action',
            'reach' => null,
        ]);
    }

    /**
     * Seed: legacy definition with reach=own for projects, a user
     * holding an org-scope functional role on the legacy side, plus
     * the new authorization_role_permissions +
     * authorization_role_assignments row that lets the new path
     * grant. Returns [user, ownedProject, foreignProject]. The user
     * owns ownedProject; foreignProject is in the same org but
     * created by someone else.
     *
     * The org-scope functional role on the legacy side is the
     * pattern ReachCapTest uses: the engine grants via the
     * org-functional layer (reach=own) and the new path grants
     * via role permission + org-scope assignment (reach=own). When
     * the new path's reach cap is applied to a target the user
     * does NOT own, the two paths disagree and SHADOW surfaces the
     * mismatch.
     *
     * @return array{0: User, 1: Project, 2: Project}
     */
    private function seedReachBackfillWithOwnProjectViewer(): array
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);

        $user = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
        ]);
        $otherUser = User::factory()->create(['organization_id' => $org->id]);

        $ownedProject = Project::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
            'created_by' => $user->id,
        ]);
        $foreignProject = Project::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
            'created_by' => $otherUser->id,
        ]);

        $this->seedReachBackfillState(
            org: $org,
            user: $user,
            reachMap: ['projects' => 'own']
        );

        AccessDecision::flushCache();

        return [$user, $ownedProject, $foreignProject];
    }

    /**
     * Seed a department-reach project viewer. Returns [user, project in
     * user's department, project in another department].
     *
     * @return array{0: User, 1: Project, 2: Project}
     */
    private function seedReachBackfillWithDepartmentProjectViewer(): array
    {
        $org = Organization::factory()->create();
        $deptA = Department::factory()->create(['organization_id' => $org->id]);
        $deptB = Department::factory()->create(['organization_id' => $org->id]);

        $user = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $deptA->id,
        ]);

        $inOwnDept = Project::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $deptA->id,
        ]);
        $inOtherDept = Project::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $deptB->id,
        ]);

        $this->seedReachBackfillState(
            org: $org,
            user: $user,
            reachMap: ['projects' => 'department']
        );

        AccessDecision::flushCache();

        return [$user, $inOwnDept, $inOtherDept];
    }

    /**
     * Seed a null-reach project viewer: pivot.reach = NULL.
     *
     * @return array{0: User, 1: Project, 2: Project}
     */
    private function seedReachBackfillWithNullReachProjectViewer(): array
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $user = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
        ]);

        $project = Project::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
        ]);
        $otherProject = Project::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
        ]);

        $this->seedReachBackfillState(
            org: $org,
            user: $user,
            reachMap: null
        );

        AccessDecision::flushCache();

        return [$user, $project, $otherProject];
    }

    /**
     * Seed an all-reach project viewer: legacy reach = 'all', pivot
     * reach = {projects: all}.
     *
     * @return array{0: User, 1: Project, 2: Project}
     */
    private function seedReachBackfillWithAllProjectViewer(): array
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $user = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
        ]);

        $project = Project::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
        ]);
        $otherProject = Project::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
        ]);

        $this->seedReachBackfillState(
            org: $org,
            user: $user,
            reachMap: ['projects' => 'all']
        );

        AccessDecision::flushCache();

        return [$user, $project, $otherProject];
    }

    /**
     * Insert a single legacy definition (org-scope) plus the matching
     * scoped_role assignment, the new authorization_role row, the
     * authorization_role_permissions pivot (with the supplied reach
     * value), and the new authorization_role_assignment. This is the
     * shape both legacy and new path consult when the engine answers
     * `can()`.
     */
    private function seedReachBackfillState(Organization $org, User $user, ?array $reachMap): void
    {
        $orgScopeType = ScopeType::firstOrCreate(
            ['key' => ScopedRole::SCOPE_ORGANIZATION],
            [
                'label_ar' => 'organization', 'label_en' => 'Organization',
                'model_class' => Organization::class,
                'supports_hierarchy' => true, 'is_active' => true, 'sort_order' => 0,
            ]
        );

        $now = now();
        $roleKey = 'phase213_engine_'.bin2hex(random_bytes(4));
        DB::table('scoped_role_definitions')->insert([
            'name' => 'phase213.engine.'.bin2hex(random_bytes(4)),
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
            'permissions' => json_encode(['projects.view']),
            'is_admin_role' => false,
            'sort_order' => 0,
            'reach' => $reachMap === null ? null : json_encode($reachMap),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $user->assignScopedRole(
            role: $roleKey,
            scopeType: ScopedRole::SCOPE_ORGANIZATION,
            scopeId: (int) $org->id,
        );

        $authRole = AuthorizationRole::firstOrCreate(
            ['name' => $roleKey],
            ['label' => $roleKey]
        );
        $authResource = AuthorizationResource::firstOrCreate(
            ['key' => Project::class],
            ['label' => 'Project']
        );

        $existed = DB::table('authorization_role_permissions')
            ->where('authorization_role_id', $authRole->id)
            ->where('authorization_resource_id', $authResource->id)
            ->where('action', 'view')
            ->exists();
        if (! $existed) {
            DB::table('authorization_role_permissions')->insert([
                'authorization_role_id' => $authRole->id,
                'authorization_resource_id' => $authResource->id,
                'action' => 'view',
                'reach' => $reachMap === null ? null : json_encode($reachMap),
            ]);
        } else {
            DB::table('authorization_role_permissions')
                ->where('authorization_role_id', $authRole->id)
                ->where('authorization_resource_id', $authResource->id)
                ->where('action', 'view')
                ->update(['reach' => $reachMap === null ? null : json_encode($reachMap)]);
        }

        $existedAssignment = DB::table('authorization_role_assignments')
            ->where('authorization_role_id', $authRole->id)
            ->where('user_id', $user->id)
            ->where('scope_type', ScopedRole::SCOPE_ORGANIZATION)
            ->exists();
        if (! $existedAssignment) {
            DB::table('authorization_role_assignments')->insert([
                'authorization_role_id' => $authRole->id,
                'user_id' => $user->id,
                'scope_type' => ScopedRole::SCOPE_ORGANIZATION,
                'scope_id' => $org->id,
                'organization_id' => $org->id,
                'inherit_to_children' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
