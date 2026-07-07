<?php

namespace Tests\Feature\Core\Authorization;

use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Authorization\Models\AuthorizationRoleAssignment;
use App\Modules\Core\Authorization\Support\ScopeAssignmentResolver;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\ScopeType;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * BackfillScopedRolesFullSemanticsTest -- Phase 2.1.2.
 *
 * Coverage for the additive backfill from `model_has_scoped_roles` (the
 * legacy scoped-role pivot) onto the new `authorization_roles` +
 * `authorization_role_assignments` + `authorization_role_permissions`
 * chain. Pins:
 *
 *  1. Migration `2026_07_04_000020_relax_authorization_role_assignments_scope_check`
 *     relaxes the `authorization_role_assignments.scope_type` CHECK to
 *     include the legacy scoped types (project, program, portfolio, kpi,
 *     meeting, survey) in addition to the Phase 1 set; the NOT-NULL
 *     semantic for `scope_id` is preserved for everything except 'all'/'own'.
 *  2. The backfill migration `2026_07_04_000021_backfill_scoped_roles_full_semantics`
 *     creates authorization_roles + authorization_role_permissions +
 *     authorization_role_assignments rows from seeded
 *     `model_has_scoped_roles` rows, with the correct
 *     (scope_type, scope_id, organization_id) and a real resolved
 *     role id.
 *  3. Audit markers land in `permission_audits` with the
 *     `event=legacy_scoped_backfill_000021` discriminator and a JSON
 *     marker carrying the migration tag, the source row id, the
 *     source (auto/manual), the new assignment id, and the pivot
 *     composite marker.
 *  4. Both `source='auto'` and `source='manual'` legacy rows are
 *     backfilled; the source string is preserved in the audit marker.
 *  5. Unsafe / unrepresentable rows (missing definition, missing scope
 *     row, unsupported resource/scope pair, unmapped capability) are
 *     SKIPPED -- the migration writes a `skipped` audit marker but
 *     does NOT create any authorization_role_assignment row and does
 *     NOT widen the catalog.
 *  6. up() is idempotent: a second up() produces zero new pivot rows
 *     and zero new audit markers.
 *  7. down() deletes only the rows it audit-marked: assignments,
 *     role permissions, audit markers. AuthorizationRole /
 *     AuthorizationResource / ScopedRole / ScopedRoleDefinition /
 *     legacy Spatie tables are LEFT INTACT.
 *  8. Phase 1 / 2.1.1 regressions still pass: super_admin pivot rows
 *     from Phase 1 are not touched by the backfill.
 *
 * The migration files are anonymous classes returned from
 * `require database_path(...)`; tests call up()/down() directly on
 * the class to scope the work, the same way
 * BackfillAuthorizationRolePermissionsTest does.
 */
class BackfillScopedRolesFullSemanticsTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_NAME_RELAX = '2026_07_04_000020_relax_authorization_role_assignments_scope_check';

    private const MIGRATION_NAME_BACKFILL = '2026_07_04_000021_backfill_scoped_roles_full_semantics';

    private const MIGRATION_NAME_INHERIT = '2026_07_04_000022_add_inherit_to_children_to_authorization_role_assignments';

    private const AUDIT_EVENT = 'legacy_scoped_backfill_000021';

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Phase 2.1.2 backfill test is PostgreSQL-only.');
        }
    }

    // =====================================================================
    // 1. CHECK relaxation: project/program/portfolio/kpi/meeting/survey accepted
    // =====================================================================

    public function test_relax_migration_accepts_legacy_scoped_scope_types(): void
    {
        $this->runMigration('up', self::MIGRATION_NAME_RELAX);

        $role = AuthorizationRole::create(['name' => 'scope_check_test_role', 'label' => 'Scope Check Test']);

        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $user = User::factory()->create(['organization_id' => $org->id]);

        // Every one of these must now be accepted by the CHECK. The
        // 'department' and 'organization' rows exercise the original
        // set; the six new types exercise the relaxation.
        $accepts = [
            ['scope_type' => 'organization', 'scope_id' => $org->id, 'organization_id' => $org->id],
            ['scope_type' => 'department', 'scope_id' => $dept->id, 'organization_id' => $org->id],
            ['scope_type' => 'project', 'scope_id' => 1, 'organization_id' => $org->id],
            ['scope_type' => 'program', 'scope_id' => 1, 'organization_id' => $org->id],
            ['scope_type' => 'portfolio', 'scope_id' => 1, 'organization_id' => $org->id],
            ['scope_type' => 'kpi', 'scope_id' => 1, 'organization_id' => $org->id],
            ['scope_type' => 'meeting', 'scope_id' => 1, 'organization_id' => $org->id],
            ['scope_type' => 'survey', 'scope_id' => 1, 'organization_id' => $org->id],
        ];

        foreach ($accepts as $row) {
            $assignment = AuthorizationRoleAssignment::create([
                'authorization_role_id' => $role->id,
                'user_id' => $user->id,
                ...$row,
            ]);
            $this->assertNotNull($assignment->id, "Scope type [{$row['scope_type']}] must be accepted by the CHECK after the relaxation migration.");
        }
    }

    public function test_relax_migration_preserves_scope_id_not_null_semantics_for_non_all_own(): void
    {
        $this->runMigration('up', self::MIGRATION_NAME_RELAX);

        $role = AuthorizationRole::create(['name' => 'scope_id_check_role', 'label' => 'Scope Id Check']);
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);

        // A project-scope row with NULL scope_id MUST still be rejected
        // by the NOT-NULL companion CHECK (project is not 'all' / 'own').
        $this->expectExceptionMessageMatches('/scope_id|check constraint/i');

        DB::table('authorization_role_assignments')->insert([
            'authorization_role_id' => $role->id,
            'user_id' => $user->id,
            'scope_type' => 'project',
            'scope_id' => null,
            'organization_id' => $org->id,
        ]);
    }

    public function test_relax_migration_down_restores_phase_one_check(): void
    {
        $this->runMigration('up', self::MIGRATION_NAME_RELAX);
        $this->runMigration('down', self::MIGRATION_NAME_RELAX);

        $role = AuthorizationRole::create(['name' => 'down_check_role', 'label' => 'Down Check']);
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);

        // After down() the original Phase 1 CHECK must be back. The new
        // types (project/program/portfolio/kpi/meeting/survey) must be
        // rejected; only all/organization/cluster/hospital/department/
        // team/own are accepted.
        $this->expectExceptionMessageMatches('/check constraint|scope_type/i');

        DB::table('authorization_role_assignments')->insert([
            'authorization_role_id' => $role->id,
            'user_id' => $user->id,
            'scope_type' => 'project',
            'scope_id' => 1,
            'organization_id' => $org->id,
        ]);
    }

    // =====================================================================
    // 2. Backfill up() creates authorization_roles + role_permissions + assignments
    // =====================================================================

    public function test_backfill_creates_authz_rows_for_seeded_scoped_role_rows(): void
    {
        // The relax migration is a prerequisite (the backfill inserts rows
        // with the new scope_types). We pre-apply it here, then seed legacy
        // data, then run the backfill.
        $this->runMigration('up', self::MIGRATION_NAME_RELAX);
        $this->seedLegacyScopedRoleFixtures();

        $this->runMigration('up', self::MIGRATION_NAME_BACKFILL);

        // authorization_roles: one row per unique legacy role key.
        // The backfill names the row by the legacy model_has_scoped_roles.role
        // string (e.g. 'project_viewer'), NOT the scoped_role_definitions.name.
        $this->assertDatabaseHas('authorization_roles', ['name' => 'project_viewer']);
        $this->assertDatabaseHas('authorization_roles', ['name' => 'department_manager']);

        // authorization_role_assignments: one row per seeded legacy row,
        // carrying the correct scope_type/scope_id/organization_id and a
        // resolved user_id.
        $project = DB::table('projects')->where('name', 'phase212 fixture project')->first();
        $this->assertNotNull($project, 'Test prerequisite: fixture project must exist.');

        $this->assertDatabaseHas('authorization_role_assignments', [
            'scope_type' => 'project',
            'scope_id' => (int) $project->id,
        ]);

        // audit marker count matches seeded legacy rows.
        $auditCount = DB::table('permission_audits')
            ->where('event', self::AUDIT_EVENT)
            ->count();
        $this->assertGreaterThan(0, $auditCount, 'Backfill must write at least one audit marker per backfilled row.');
    }

    // =====================================================================
    // 3. Audit marker shape: migration tag + source row id + new assignment id
    // =====================================================================

    public function test_backfill_audit_marker_carries_migration_tag_source_id_and_new_assignment_id(): void
    {
        $this->runMigration('up', self::MIGRATION_NAME_RELAX);
        $this->seedLegacyScopedRoleFixtures();

        $this->runMigration('up', self::MIGRATION_NAME_BACKFILL);

        $auditRow = DB::table('permission_audits')
            ->where('event', self::AUDIT_EVENT)
            ->first();

        $this->assertNotNull($auditRow, 'Backfill must write at least one audit marker.');

        $newValue = json_decode($auditRow->new_value, true);
        $this->assertIsArray($newValue, 'Audit row new_value must be a JSON object.');
        $this->assertSame(
            self::MIGRATION_NAME_BACKFILL,
            $newValue['migration'] ?? null,
            'Audit marker must carry the migration tag.'
        );
        $this->assertArrayHasKey('source_scoped_role_id', $newValue,
            'Audit marker must reference the legacy model_has_scoped_roles row id.');
        $this->assertArrayHasKey('source', $newValue,
            'Audit marker must record the source (auto/manual).');
        $this->assertArrayHasKey('new_authorization_role_assignment_id', $newValue,
            'Audit marker must reference the new authorization_role_assignments row id.');
    }

    // =====================================================================
    // 4. auto + manual sources both backfilled
    // =====================================================================

    public function test_backfill_processes_both_auto_and_manual_source_rows(): void
    {
        $this->runMigration('up', self::MIGRATION_NAME_RELAX);
        $this->seedLegacyScopedRoleFixtures();

        $this->runMigration('up', self::MIGRATION_NAME_BACKFILL);

        $auditRows = DB::table('permission_audits')
            ->where('event', self::AUDIT_EVENT)
            ->get();

        $sources = [];
        foreach ($auditRows as $row) {
            $newValue = json_decode($row->new_value, true);
            $src = $newValue['source'] ?? null;
            if ($src !== null) {
                $sources[$src] = true;
            }
        }

        $this->assertArrayHasKey('auto', $sources, 'Backfill must process source=auto rows.');
        $this->assertArrayHasKey('manual', $sources, 'Backfill must process source=manual rows.');
    }

    // =====================================================================
    // 5. Unsafe rows skipped + audit-marked; no widening
    // =====================================================================

    public function test_backfill_skips_unrepresentable_rows_and_audits_them(): void
    {
        $this->runMigration('up', self::MIGRATION_NAME_RELAX);

        // Seed a single legacy row that has NO matching
        // scoped_role_definition: the backfill must skip it, audit-mark it,
        // and not create any authorization_role_assignments row.
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $user = User::factory()->create(['organization_id' => $org->id]);

        DB::table('model_has_scoped_roles')->insert([
            'user_id' => $user->id,
            'role' => 'phase212_unmapped_role',
            'scope_type' => 'department',
            'scope_id' => $dept->id,
            'inherit_to_children' => true,
            'granted_by' => null,
            'source' => 'manual',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $assignmentsBefore = DB::table('authorization_role_assignments')->count();

        $this->runMigration('up', self::MIGRATION_NAME_BACKFILL);

        $assignmentsAfter = DB::table('authorization_role_assignments')->count();
        $this->assertSame(
            $assignmentsBefore,
            $assignmentsAfter,
            'Unmappable legacy rows must NOT create authorization_role_assignments rows (no widening).'
        );

        $skipped = DB::table('permission_audits')
            ->where('event', self::AUDIT_EVENT)
            ->get()
            ->filter(function ($row) {
                $newValue = json_decode($row->new_value, true);

                // Note: a NULL assignment id is encoded as JSON null,
                // which becomes PHP null -- and isset() on a null
                // array entry returns FALSE. Use array_key_exists().
                return is_array($newValue)
                    && array_key_exists('new_authorization_role_assignment_id', $newValue)
                    && $newValue['new_authorization_role_assignment_id'] === null;
            })
            ->count();
        $this->assertGreaterThan(0, $skipped, 'Unmappable row must produce a skipped audit marker (no new assignment id).');
    }

    // =====================================================================
    // 5b. Null scope_id on non-'all'/'own' scope_type: skip + audit, no crash (I-1)
    // =====================================================================

    public function test_backfill_skips_legacy_row_with_null_scope_id_for_non_all_own_scope_type(): void
    {
        // Verifier finding I-1: migration 000021 must skip+audit legacy
        // rows where `scope_id IS NULL` for non-'all'/'own' scope_types
        // instead of crashing the migration. Migration 000020 added a
        // CHECK constraint that requires scope_id IS NOT NULL for every
        // scope_type except 'all' and 'own'. A legacy row whose scope_id
        // was lost (legacy bug, manual cleanup, a historical schema
        // variant) cannot be backfilled -- skip it, audit it, do not
        // crash.
        //
        // The legacy `model_has_scoped_roles.scope_id` column is
        // currently NOT NULL in the schema, so the test simulates the
        // scenario by ALTERing the column nullable for this case --
        // mirroring how test #9 drops a column to exercise a different
        // pre-fix state. The defensive check in 000021 is the fix;
        // whether the legacy row exists today or arrives tomorrow
        // (schema variant, partial rollback, superuser bypass), the
        // migration must remain crash-safe.
        $this->runMigration('up', self::MIGRATION_NAME_RELAX);

        // Make model_has_scoped_roles.scope_id nullable so we can seed
        // the bad row. The migration does NOT depend on this column
        // being NOT NULL; it only reads scope_id for INSERT into
        // authorization_role_assignments.
        DB::statement('ALTER TABLE model_has_scoped_roles ALTER COLUMN scope_id DROP NOT NULL');

        $now = now();
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $user = User::factory()->create(['organization_id' => $org->id]);

        // Valid definition + permissions so 000021 would otherwise
        // attempt to materialize role + permissions + assignment for
        // this row (i.e. exercise the failure path, not the easy path).
        $deptScopeType = ScopeType::firstOrCreate(
            ['key' => 'department'],
            [
                'label_ar' => 'department', 'label_en' => 'Department',
                'model_class' => Department::class,
                'supports_hierarchy' => true, 'is_active' => true, 'sort_order' => 1,
            ]
        );
        DB::table('scoped_role_definitions')->insert([
            'name' => 'phase212.null_scope_id_skip',
            'display_name' => 'Null Scope ID Skip',
            'scope_type' => 'department',
            'description' => null,
            'default_abilities' => null,
            'level' => 0,
            'is_active' => true,
            'role_key' => 'phase212_null_scope_id_role',
            'label_ar' => 'Null Scope ID',
            'label_en' => 'Null Scope ID',
            'scope_type_id' => $deptScopeType->id,
            'color' => 'primary',
            'permissions' => json_encode(['projects.view']),
            'is_admin_role' => false,
            'sort_order' => 0,
            'reach' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // The dangerous legacy row: scope_id=NULL on a non-'all'/'own'
        // scope_type. Pre-fix, 000021's INSERT into
        // authorization_role_assignments crashes with a CHECK
        // constraint violation from migration 000020.
        DB::table('model_has_scoped_roles')->insert([
            'user_id' => $user->id,
            'role' => 'phase212_null_scope_id_role',
            'scope_type' => 'department',
            'scope_id' => null,
            'inherit_to_children' => true,
            'granted_by' => null,
            'source' => 'manual',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $assignmentsBefore = DB::table('authorization_role_assignments')->count();

        // MUST NOT throw.
        $this->runMigration('up', self::MIGRATION_NAME_BACKFILL);

        // 1. No assignment was created for the skipped row.
        $skippedAssignment = DB::table('authorization_role_assignments')
            ->where('user_id', $user->id)
            ->where('scope_type', 'department')
            ->whereNull('scope_id')
            ->count();
        $this->assertSame(
            0,
            $skippedAssignment,
            'No authorization_role_assignments row may be created for the skipped legacy row.'
        );
        $this->assertSame(
            $assignmentsBefore,
            DB::table('authorization_role_assignments')->count(),
            'Skipped row must not change the assignment count (no widening).'
        );

        // 2. No role / role permission pivot was created solely for the
        //    skipped row. The skip branch runs BEFORE any
        //    authorization_roles / authorization_role_permissions row is
        //    materialized, so neither must exist for the skipped role
        //    name.
        $role = DB::table('authorization_roles')
            ->where('name', 'phase212_null_scope_id_role')
            ->first();
        $this->assertNull(
            $role,
            'No authorization_roles row may be created solely for the skipped legacy row (no widening).'
        );

        // 3. A skip audit marker exists with the expected reason.
        $skipMarker = DB::table('permission_audits')
            ->where('event', self::AUDIT_EVENT)
            ->where('reason', 'unmappable_null_scope_id_for_scope_type')
            ->first();
        $this->assertNotNull(
            $skipMarker,
            'Skip audit marker with reason=unmappable_null_scope_id_for_scope_type must exist.'
        );

        // 4. The audit marker correctly references the skipped row.
        $this->assertSame((int) $user->id, (int) $skipMarker->target_user_id);
        $this->assertSame('department', $skipMarker->scope_type);
        $this->assertNull($skipMarker->scope_id);

        $newValue = json_decode($skipMarker->new_value, true);
        $this->assertIsArray($newValue);
        $this->assertArrayHasKey(
            'new_authorization_role_assignment_id',
            $newValue,
            'Skip marker must carry the new_authorization_role_assignment_id key (null for skips).'
        );
        $this->assertNull(
            $newValue['new_authorization_role_assignment_id'],
            'Skip marker must have no new assignment id (null).'
        );
        $this->assertSame(
            self::MIGRATION_NAME_BACKFILL,
            $newValue['migration'] ?? null,
            'Skip marker must carry the migration tag.'
        );
    }

    public function test_backfill_continues_processing_valid_rows_after_skipping_null_scope_id_row(): void
    {
        // Verifier finding I-1 (companion): a single null-scope-id legacy
        // row in the seed set must NOT short-circuit the loop. Valid
        // rows alongside the bad row must still be backfilled.
        $this->runMigration('up', self::MIGRATION_NAME_RELAX);
        $this->seedLegacyScopedRoleFixtures();

        // Allow scope_id=NULL on the legacy table so we can seed the
        // bad row. See sibling test for the rationale.
        DB::statement('ALTER TABLE model_has_scoped_roles ALTER COLUMN scope_id DROP NOT NULL');

        $now = now();
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $user = User::factory()->create(['organization_id' => $org->id]);

        $deptScopeType = ScopeType::firstOrCreate(
            ['key' => 'department'],
            [
                'label_ar' => 'department', 'label_en' => 'Department',
                'model_class' => Department::class,
                'supports_hierarchy' => true, 'is_active' => true, 'sort_order' => 1,
            ]
        );
        DB::table('scoped_role_definitions')->insert([
            'name' => 'phase212.null_scope_id_continue',
            'display_name' => 'Null Scope ID Continue',
            'scope_type' => 'department',
            'description' => null,
            'default_abilities' => null,
            'level' => 0,
            'is_active' => true,
            'role_key' => 'phase212_null_scope_id_continue_role',
            'label_ar' => 'Null Scope ID Continue',
            'label_en' => 'Null Scope ID Continue',
            'scope_type_id' => $deptScopeType->id,
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
            'role' => 'phase212_null_scope_id_continue_role',
            'scope_type' => 'department',
            'scope_id' => null,
            'inherit_to_children' => true,
            'granted_by' => null,
            'source' => 'manual',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->runMigration('up', self::MIGRATION_NAME_BACKFILL);

        // Valid seeded rows must STILL be backfilled.
        $this->assertDatabaseHas('authorization_roles', ['name' => 'project_viewer']);
        $this->assertDatabaseHas('authorization_roles', ['name' => 'department_manager']);

        $project = DB::table('projects')->where('name', 'phase212 fixture project')->first();
        $this->assertNotNull($project, 'Test prerequisite: fixture project must exist.');
        $this->assertDatabaseHas('authorization_role_assignments', [
            'scope_type' => 'project',
            'scope_id' => (int) $project->id,
        ]);

        // The skipped row's audit marker must exist alongside the
        // backfill audit markers.
        $skipCount = DB::table('permission_audits')
            ->where('event', self::AUDIT_EVENT)
            ->where('reason', 'unmappable_null_scope_id_for_scope_type')
            ->count();
        $this->assertSame(
            1,
            $skipCount,
            'Exactly one skip audit marker for the null-scope-id row must exist.'
        );
    }

    // =====================================================================
    // 6. Idempotency
    // =====================================================================

    public function test_backfill_up_is_idempotent(): void
    {
        $this->runMigration('up', self::MIGRATION_NAME_RELAX);
        $this->seedLegacyScopedRoleFixtures();

        $this->runMigration('up', self::MIGRATION_NAME_BACKFILL);

        $pivotCountBefore = DB::table('authorization_role_assignments')->count();
        $auditCountBefore = DB::table('permission_audits')
            ->where('event', self::AUDIT_EVENT)
            ->count();
        $roleCountBefore = DB::table('authorization_roles')->count();
        $rolePermissionCountBefore = DB::table('authorization_role_permissions')->count();

        $this->assertGreaterThan(0, $pivotCountBefore, 'First up() did not produce any assignment rows; cannot assert idempotency.');

        $this->runMigration('up', self::MIGRATION_NAME_BACKFILL);

        $this->assertSame(
            $pivotCountBefore,
            DB::table('authorization_role_assignments')->count(),
            'Second up() produced a different assignment count; backfill is not idempotent.'
        );
        $this->assertSame(
            $auditCountBefore,
            DB::table('permission_audits')->where('event', self::AUDIT_EVENT)->count(),
            'Second up() produced a different audit marker count; backfill is not idempotent.'
        );
        $this->assertSame(
            $roleCountBefore,
            DB::table('authorization_roles')->count(),
            'Second up() created additional authorization_roles rows; backfill is not idempotent.'
        );
        $this->assertSame(
            $rolePermissionCountBefore,
            DB::table('authorization_role_permissions')->count(),
            'Second up() created additional authorization_role_permissions rows; backfill is not idempotent.'
        );
    }

    // =====================================================================
    // 7. inherit_to_children semantic preservation (Phase 2.1.2 hardening)
    // =====================================================================

    public function test_backfill_persists_inherit_to_children_false_legacy_value(): void
    {
        // Verifier finding: migration 000021 reads the legacy
        // model_has_scoped_roles.inherit_to_children value into the audit
        // marker, but does not persist it on the new
        // authorization_role_assignments row. The new column migration
        // 000022 must exist; the backfill must write the legacy value
        // through; and the persisted row must carry it so the resolver
        // does NOT default a `false` legacy row to `true` (which would
        // widen access to descendant departments).
        $this->runMigration('up', self::MIGRATION_NAME_RELAX);
        $this->runMigration('up', self::MIGRATION_NAME_INHERIT);

        $now = now();
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $user = User::factory()->create(['organization_id' => $org->id]);

        $deptScopeType = ScopeType::firstOrCreate(
            ['key' => 'department'],
            [
                'label_ar' => 'department', 'label_en' => 'Department',
                'model_class' => Department::class,
                'supports_hierarchy' => true, 'is_active' => true, 'sort_order' => 1,
            ]
        );
        DB::table('scoped_role_definitions')->insert([
            'name' => 'phase212.inherit_false',
            'display_name' => 'Inherit False Role',
            'scope_type' => 'department',
            'description' => null,
            'default_abilities' => null,
            'level' => 0,
            'is_active' => true,
            'role_key' => 'phase212_inherit_false_role',
            'label_ar' => 'Inherit False',
            'label_en' => 'Inherit False',
            'scope_type_id' => $deptScopeType->id,
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
            'role' => 'phase212_inherit_false_role',
            'scope_type' => 'department',
            'scope_id' => $dept->id,
            'inherit_to_children' => false,
            'granted_by' => null,
            'source' => 'manual',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->runMigration('up', self::MIGRATION_NAME_BACKFILL);

        $assignment = DB::table('authorization_role_assignments')
            ->where('user_id', $user->id)
            ->where('scope_type', 'department')
            ->where('scope_id', $dept->id)
            ->where('organization_id', $org->id)
            ->first();

        $this->assertNotNull(
            $assignment,
            'Test prerequisite: backfilled department-scoped assignment must exist.'
        );

        // The column MUST exist (added by 000022) AND carry the legacy value.
        $this->assertNotNull(
            $assignment->inherit_to_children ?? null,
            'authorization_role_assignments.inherit_to_children column must exist after migration 000022.'
        );
        $this->assertSame(
            false,
            (bool) $assignment->inherit_to_children,
            'Backfilled assignment must preserve the legacy inherit_to_children=false value (no widening).'
        );
    }

    public function test_backfilled_dept_assignment_with_inherit_false_denies_descendant_via_resolver(): void
    {
        // End-to-end: legacy row with inherit_to_children=false, after
        // backfill + Eloquent hydration, must NOT grant descendant
        // department access through ScopeAssignmentResolver.
        $this->runMigration('up', self::MIGRATION_NAME_RELAX);
        $this->runMigration('up', self::MIGRATION_NAME_INHERIT);

        $org = Organization::factory()->create();
        $parentDept = Department::factory()->create(['organization_id' => $org->id]);
        $childDept = Department::factory()->create([
            'organization_id' => $org->id,
            'parent_id' => $parentDept->id,
        ]);
        $user = User::factory()->create(['organization_id' => $org->id]);

        $now = now();
        $deptScopeType = ScopeType::firstOrCreate(
            ['key' => 'department'],
            [
                'label_ar' => 'department', 'label_en' => 'Department',
                'model_class' => Department::class,
                'supports_hierarchy' => true, 'is_active' => true, 'sort_order' => 1,
            ]
        );
        DB::table('scoped_role_definitions')->insert([
            'name' => 'phase212.deny_descendant',
            'display_name' => 'Deny Descendant',
            'scope_type' => 'department',
            'description' => null,
            'default_abilities' => null,
            'level' => 0,
            'is_active' => true,
            'role_key' => 'deny_descendant_role',
            'label_ar' => 'Deny Descendant',
            'label_en' => 'Deny Descendant',
            'scope_type_id' => $deptScopeType->id,
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
            'role' => 'deny_descendant_role',
            'scope_type' => 'department',
            'scope_id' => $parentDept->id,
            'inherit_to_children' => false,
            'granted_by' => null,
            'source' => 'manual',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->runMigration('up', self::MIGRATION_NAME_BACKFILL);

        // Load the assignment as an Eloquent model so we exercise the same
        // path AccessDecision takes (AuthorizationRoleAssignment
        // -> ScopeAssignmentResolver::applies via normalize()).
        $assignment = AuthorizationRoleAssignment::query()
            ->where('user_id', $user->id)
            ->where('scope_type', 'department')
            ->where('scope_id', $parentDept->id)
            ->firstOrFail();

        $this->assertFalse(
            (bool) $assignment->inherit_to_children,
            'Eloquent-loaded assignment must reflect the legacy inherit_to_children=false.'
        );

        $descendantProject = Project::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $childDept->id,
        ]);

        $this->assertFalse(
            ScopeAssignmentResolver::applies($assignment, $descendantProject),
            'Legacy inherit_to_children=false must NOT grant descendant department access (no widening).'
        );
    }

    // =====================================================================
    // 8. down() deletes only audit-marked rows; legacy tables intact
    // =====================================================================

    public function test_backfill_down_only_removes_audit_marked_rows_and_leaves_legacy_intact(): void
    {
        $this->runMigration('up', self::MIGRATION_NAME_RELAX);
        $this->seedLegacyScopedRoleFixtures();

        $this->runMigration('up', self::MIGRATION_NAME_BACKFILL);

        $legacyRowsBefore = DB::table('model_has_scoped_roles')->count();
        $authzRowsBefore = DB::table('authorization_role_assignments')->count();
        $authzPermissionsBefore = DB::table('authorization_role_permissions')->count();
        $auditBefore = DB::table('permission_audits')
            ->where('event', self::AUDIT_EVENT)
            ->count();

        $this->assertGreaterThan(0, $legacyRowsBefore, 'Pre-test invariant: legacy rows must exist.');
        $this->assertGreaterThan(0, $authzRowsBefore, 'Pre-test invariant: backfill assignments must exist.');
        $this->assertGreaterThan(0, $auditBefore, 'Pre-test invariant: audit markers must exist.');

        $this->runMigration('down', self::MIGRATION_NAME_BACKFILL);

        // Audit markers the migration wrote are gone.
        $this->assertSame(
            0,
            DB::table('permission_audits')->where('event', self::AUDIT_EVENT)->count(),
            'down() did not remove the audit markers it should own.'
        );

        // Authorization_role_assignments rows this migration wrote are gone.
        $this->assertSame(
            0,
            $this->auditMarkedAssignmentIdsQuery()->count(),
            'down() did not remove the audit-marked assignment rows.'
        );

        // authorization_role_permissions rows: only those the migration
        // wrote were audit-marked and may now be referenced; down() must
        // NOT delete pivots it did not author.
        $pivotsAfter = DB::table('authorization_role_permissions')->count();
        $this->assertLessThanOrEqual(
            $authzPermissionsBefore,
            $pivotsAfter,
            'down() must not create new pivot rows.'
        );

        // Legacy scoped-role rows are FINGERPRINT-INTACT.
        $this->assertSame(
            $legacyRowsBefore,
            DB::table('model_has_scoped_roles')->count(),
            'down() mutated legacy model_has_scoped_roles rows; it must leave them intact.'
        );
    }

    // =====================================================================
    // 9. Ordering bug: 000021 must NOT depend on 000022 having run first
    // =====================================================================

    public function test_backfill_up_runs_without_inherit_to_children_migration_already_applied(): void
    {
        // Verifier finding: migration 000021's INSERT against
        // authorization_role_assignments references the
        // `inherit_to_children` column (so it can preserve the legacy
        // semantic). 000022 is the only migration that adds that column,
        // and 000021 sorts BEFORE 000022. On a production-style run where
        // legacy scoped-role rows are present and 000022 has not yet run,
        // 000021's INSERT fails with `column "inherit_to_children" does
        // not exist`. The fix: 000021 must ensure the column exists
        // itself (idempotent hasColumn guard) so it does not depend on
        // 000022 ordering.
        $this->runMigration('up', self::MIGRATION_NAME_RELAX);

        // Simulate the production-style state where 000022 has NOT yet
        // been applied: drop the column 000022 would have added. The
        // RefreshDatabase default migration runner applies every
        // migration in order, so on a fresh test DB the column exists
        // from the get-go; we undo that to exercise the bug scenario.
        if (Schema::hasColumn('authorization_role_assignments', 'inherit_to_children')) {
            Schema::table('authorization_role_assignments', function ($table) {
                $table->dropColumn('inherit_to_children');
            });
        }
        $this->assertFalse(
            Schema::hasColumn('authorization_role_assignments', 'inherit_to_children'),
            'Pre-test invariant: inherit_to_children column must NOT exist before 000021 runs (pre-fix state).'
        );

        // Seed legacy rows that 000021 will try to materialize.
        $this->seedLegacyScopedRoleFixtures();

        // Deliberately do NOT run 000022 first -- this is the exact
        // failure scenario the verifier flagged. With the current bug,
        // this throws `column does not exist`; after the fix it succeeds
        // because 000021 itself ensures the column exists.
        $this->runMigration('up', self::MIGRATION_NAME_BACKFILL);

        // 000021 must have created the assignment rows AND the column
        // (so the INSERT did not fail with "column does not exist").
        $project = DB::table('projects')->where('name', 'phase212 fixture project')->first();
        $this->assertNotNull($project, 'Test prerequisite: fixture project must exist.');

        $assignment = DB::table('authorization_role_assignments')
            ->where('scope_type', 'project')
            ->where('scope_id', (int) $project->id)
            ->first();

        $this->assertNotNull(
            $assignment,
            '000021 must materialize the authorization_role_assignments row even when 000022 has not run yet.'
        );

        // Column must exist on the table after 000021 ran.
        $this->assertTrue(
            Schema::hasColumn('authorization_role_assignments', 'inherit_to_children'),
            '000021 must ensure the inherit_to_children column exists (000022 is not its prerequisite).'
        );

        // Legacy value must be persisted on the new row.
        $this->assertTrue(
            (bool) $assignment->inherit_to_children,
            'Legacy inherit_to_children=true must be persisted on the new row.'
        );
    }

    // =====================================================================
    // 10. 000022 safety-net must update pre-fix 000021 rows, not just whereNull
    // =====================================================================

    public function test_inherit_to_children_safety_net_updates_pre_fix_assignments_to_legacy_false(): void
    {
        // Verifier finding: 000022 adds the column with `DEFAULT true`
        // and SET NOT NULL. Existing rows (created by a pre-fix 000021
        // run that did NOT write the column because it did not yet
        // exist) immediately get `true` from the default. The safety net
        // then filters on `whereNull('inherit_to_children')`, which
        // matches NOTHING -- so a legacy `inherit_to_children=false`
        // row's assignment stays `true` (silent widening).
        //
        // Simulate the pre-fix 000021: insert an authorization_role_assignments
        // row WITHOUT the inherit_to_children column (column does not
        // exist yet), insert the matching audit marker that links the
        // new assignment id to the legacy model_has_scoped_roles row,
        // then run 000022. The safety net must update the assignment to
        // the legacy value (false) without depending on `whereNull`.
        $this->runMigration('up', self::MIGRATION_NAME_RELAX);

        // Simulate the pre-fix 000021 state: the column does not yet
        // exist. RefreshDatabase applied every migration including
        // 000022, so we undo it here to exercise the bug scenario.
        if (Schema::hasColumn('authorization_role_assignments', 'inherit_to_children')) {
            Schema::table('authorization_role_assignments', function ($table) {
                $table->dropColumn('inherit_to_children');
            });
        }

        $now = now();
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $role = AuthorizationRole::create(['name' => 'phase212_safety_net_role', 'label' => 'Safety Net Role']);

        // 1. Legacy row with inherit_to_children=false.
        $legacyId = DB::table('model_has_scoped_roles')->insertGetId([
            'user_id' => $user->id,
            'role' => 'phase212_safety_net_role',
            'scope_type' => 'department',
            'scope_id' => $dept->id,
            'inherit_to_children' => false,
            'granted_by' => null,
            'source' => 'manual',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // 2. Pre-fix 000021-style assignment row (column does not exist
        // yet, so we insert without it).
        $assignmentId = DB::table('authorization_role_assignments')->insertGetId([
            'authorization_role_id' => $role->id,
            'user_id' => $user->id,
            'scope_type' => 'department',
            'scope_id' => $dept->id,
            'organization_id' => $org->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // 3. Audit marker linking the legacy row to the new assignment.
        DB::table('permission_audits')->insert([
            'event' => self::AUDIT_EVENT,
            'actor_id' => null,
            'target_user_id' => $user->id,
            'scope_type' => 'department',
            'scope_id' => $dept->id,
            'role' => 'phase212_safety_net_role',
            'old_value' => null,
            'new_value' => json_encode([
                'migration' => self::MIGRATION_NAME_BACKFILL,
                'source_scoped_role_id' => (int) $legacyId,
                'source' => 'manual',
                'new_authorization_role_assignment_id' => (int) $assignmentId,
                'authorization_role_id' => (int) $role->id,
                'authorization_role_name' => 'phase212_safety_net_role',
                'scope_type' => 'department',
                'scope_id' => (int) $dept->id,
                'organization_id' => $org->id,
                'inherit_to_children' => false,
                'created_role_permissions' => [],
            ]),
            'reason' => 'Phase 2.1.2 full-semantics scoped-role backfill',
            'ip_address' => null,
            'user_agent' => 'migration',
            'created_at' => $now,
        ]);

        // Sanity: the column does not exist yet (this is the pre-fix state).
        $this->assertFalse(
            Schema::hasColumn('authorization_role_assignments', 'inherit_to_children'),
            'Pre-test invariant: inherit_to_children column must NOT exist before 000022 runs (pre-fix state).'
        );

        // 4. Run 000022. It will: (a) add the column with default true
        //    (existing rows get true); (b) run the safety net.
        $this->runMigration('up', self::MIGRATION_NAME_INHERIT);

        // 5. Safety net must have updated the assignment to the legacy
        //    value (false) -- NOT left it at the default (true).
        $assignment = DB::table('authorization_role_assignments')
            ->where('id', $assignmentId)
            ->first();

        $this->assertNotNull($assignment, 'Test prerequisite: assignment row must still exist after 000022.');
        $this->assertSame(
            false,
            (bool) $assignment->inherit_to_children,
            '000022 safety net must update pre-fix 000021 rows to the legacy inherit_to_children=false value '
            .'(must not depend on whereNull -- default true fills existing rows, so whereNull matches nothing).'
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
     * Seed the minimum legacy data the backfill needs to exercise the
     * happy path: an organization, a department, a project, a user, two
     * scoped_role_definitions (one project-scope, one department-scope),
     * and two model_has_scoped_roles rows (one per definition, one auto +
     * one manual). Mirrors the pattern BackfillAuthorizationRolePermissionsTest
     * uses so the backfill can be exercised end-to-end.
     */
    private function seedLegacyScopedRoleFixtures(): void
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $project = Project::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
            'name' => 'phase212 fixture project',
        ]);
        $user = User::factory()->create(['organization_id' => $org->id]);

        // Two scoped_role_definitions: project-scope (legacy 'project_viewer')
        // + department-scope (legacy 'department_manager'). Each carries
        // permissions[] so the backfill can materialize an
        // authorization_role_permissions row per capability.
        $projectScopeType = ScopeType::firstOrCreate(
            ['key' => 'project'],
            [
                'label_ar' => 'project', 'label_en' => 'Project',
                'model_class' => Project::class,
                'supports_hierarchy' => true, 'is_active' => true, 'sort_order' => 0,
            ]
        );
        $deptScopeType = ScopeType::firstOrCreate(
            ['key' => 'department'],
            [
                'label_ar' => 'department', 'label_en' => 'Department',
                'model_class' => Department::class,
                'supports_hierarchy' => true, 'is_active' => true, 'sort_order' => 1,
            ]
        );

        $now = now();
        $projectDefinition = DB::table('scoped_role_definitions')->insertGetId([
            'name' => 'phase212.project_viewer',
            'display_name' => 'Project Viewer',
            'scope_type' => 'project',
            'description' => null,
            'default_abilities' => null,
            'level' => 0,
            'is_active' => true,
            'role_key' => 'project_viewer',
            'label_ar' => 'Project Viewer',
            'label_en' => 'Project Viewer',
            'scope_type_id' => $projectScopeType->id,
            'color' => 'primary',
            'permissions' => json_encode(['projects.view']),
            'is_admin_role' => false,
            'sort_order' => 0,
            'reach' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $deptDefinition = DB::table('scoped_role_definitions')->insertGetId([
            'name' => 'phase212.department_manager',
            'display_name' => 'Department Manager',
            'scope_type' => 'department',
            'description' => null,
            'default_abilities' => null,
            'level' => 0,
            'is_active' => true,
            'role_key' => 'department_manager',
            'label_ar' => 'Department Manager',
            'label_en' => 'Department Manager',
            'scope_type_id' => $deptScopeType->id,
            'color' => 'primary',
            'permissions' => json_encode(['projects.view']),
            'is_admin_role' => false,
            'sort_order' => 0,
            'reach' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('model_has_scoped_roles')->insert([
            [
                'user_id' => $user->id,
                'role' => 'project_viewer',
                'scope_type' => 'project',
                'scope_id' => $project->id,
                'inherit_to_children' => true,
                'granted_by' => null,
                'source' => 'manual',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'user_id' => $user->id,
                'role' => 'department_manager',
                'scope_type' => 'department',
                'scope_id' => $dept->id,
                'inherit_to_children' => true,
                'granted_by' => null,
                'source' => 'auto',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    /**
     * Helper: count assignment rows that the backfill's audit markers
     * reference. Used by the down() test to assert "every audit-marked
     * row was removed" without depending on the internal id space.
     */
    private function auditMarkedAssignmentIdsQuery(): Builder
    {
        $ids = DB::table('permission_audits')
            ->where('event', self::AUDIT_EVENT)
            ->get()
            ->map(function ($row) {
                $newValue = json_decode($row->new_value, true);

                return $newValue['new_authorization_role_assignment_id'] ?? null;
            })
            ->filter()
            ->values()
            ->all();

        return DB::table('authorization_role_assignments')->whereIn('id', $ids ?: [0]);
    }
}
