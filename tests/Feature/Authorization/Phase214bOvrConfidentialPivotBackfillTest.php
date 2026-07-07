<?php

namespace Tests\Feature\Authorization;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\AuthorizationRuntimeMode;
use App\Modules\Core\Authorization\AuthzShadowMismatchException;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Phase214bOvrConfidentialPivotBackfillTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_NAME = '2026_07_05_000027_backfill_authorization_role_permissions_ovr_confidential';

    private const AUDIT_EVENT = 'legacy_ovr_confidential_permission_backfill_000027';

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Phase 2.1.4b OVR confidential backfill test is PostgreSQL-only.');
        }
    }

    protected function tearDown(): void
    {
        AuthorizationRuntimeMode::reset();
        AccessDecision::flushCache();

        parent::tearDown();
    }

    public function test_migration_creates_canonical_confidential_pivot_for_legacy_view_confidential_role(): void
    {
        $fixture = $this->seedRoleFixture([Capability::OVR_VIEW_CONFIDENTIAL]);

        $this->runMigration('up');

        $this->assertSame(1, $this->canonicalPivotCount($fixture['role']->id));
        $this->assertSame(1, DB::table('permission_audits')->where('event', self::AUDIT_EVENT)->where('reason', 'ovr_confidential_permission_backfilled')->count());
    }

    public function test_shadow_parity_grants_confidential_only_after_backfilled_pivot_exists(): void
    {
        $fixture = $this->seedRoleFixture([Capability::OVR_VIEW_CONFIDENTIAL]);
        AuthorizationRuntimeMode::enableShadow();

        $mismatchedBeforePivot = false;
        try {
            AccessDecision::can($fixture['user'], Capability::OVR_CONFIDENTIAL, $fixture['report']);
        } catch (AuthzShadowMismatchException) {
            $mismatchedBeforePivot = true;
        } finally {
            AuthorizationRuntimeMode::reset();
        }
        $this->assertTrue($mismatchedBeforePivot, 'Legacy allows but the new path must deny before the canonical pivot exists.');

        $this->runMigration('up');
        AuthorizationRuntimeMode::enableShadow();

        $this->assertTrue(AccessDecision::can($fixture['user'], Capability::OVR_CONFIDENTIAL, $fixture['report']));
    }

    public function test_migration_does_not_grant_roles_without_legacy_confidential_capability(): void
    {
        $fixture = $this->seedRoleFixture([Capability::OVR_VIEW]);

        $this->runMigration('up');

        AuthorizationRuntimeMode::enableShadow();

        $this->assertFalse(AccessDecision::can($fixture['user'], Capability::OVR_CONFIDENTIAL, $fixture['report']));
        $this->assertSame(0, $this->canonicalPivotCount($fixture['role']->id));
    }

    public function test_admin_role_flag_without_legacy_confidential_capability_does_not_widen_access(): void
    {
        $fixture = $this->seedRoleFixture([Capability::OVR_VIEW], authorizationRoleIsAdmin: true);

        $this->runMigration('up');

        AuthorizationRuntimeMode::enableShadow();

        $this->assertFalse(AccessDecision::can($fixture['user'], Capability::OVR_CONFIDENTIAL, $fixture['report']));
        $this->assertSame(0, $this->canonicalPivotCount($fixture['role']->id));
        $this->assertSame(0, DB::table('permission_audits')->where('event', self::AUDIT_EVENT)->count());
    }

    public function test_canonical_legacy_confidential_permission_grants_real_sensitive_access_after_backfill(): void
    {
        $fixture = $this->seedRoleFixture([Capability::OVR_CONFIDENTIAL]);

        $this->runMigration('up');

        AuthorizationRuntimeMode::enableShadow();

        $this->assertSame(1, $this->canonicalPivotCount($fixture['role']->id));
        $this->assertTrue(AccessDecision::can($fixture['user'], Capability::OVR_CONFIDENTIAL, $fixture['report']));
    }

    public function test_migration_is_idempotent_and_supports_canonical_legacy_string(): void
    {
        $fixture = $this->seedRoleFixture([Capability::OVR_CONFIDENTIAL]);

        $this->runMigration('up');
        $pivotCount = $this->canonicalPivotCount($fixture['role']->id);
        $auditCount = DB::table('permission_audits')->where('event', self::AUDIT_EVENT)->count();

        $this->runMigration('up');

        $this->assertSame(1, $pivotCount);
        $this->assertSame(1, $this->canonicalPivotCount($fixture['role']->id));
        $this->assertSame($auditCount, DB::table('permission_audits')->where('event', self::AUDIT_EVENT)->count());
    }

    public function test_unmapped_legacy_confidential_role_is_skipped_and_audit_is_idempotent(): void
    {
        $roleKey = 'phase214b_unmapped_'.bin2hex(random_bytes(4));
        $this->seedScopedRoleDefinition($roleKey, [Capability::OVR_VIEW_CONFIDENTIAL]);

        $this->runMigration('up');
        $this->runMigration('up');

        $this->assertFalse(AuthorizationRole::where('name', $roleKey)->exists());
        $this->assertSame(1, DB::table('permission_audits')->where('event', self::AUDIT_EVENT)->where('reason', 'unmappable_ovr_confidential_permission')->count());
    }

    public function test_down_removes_only_this_migrations_pivots_and_audits(): void
    {
        $created = $this->seedRoleFixture([Capability::OVR_VIEW_CONFIDENTIAL]);
        $preexisting = $this->seedRoleFixture([Capability::OVR_VIEW_CONFIDENTIAL]);
        $this->insertCanonicalPivot($preexisting['role']->id);

        $this->runMigration('up');
        $this->runMigration('down');

        $this->assertSame(0, $this->canonicalPivotCount($created['role']->id));
        $this->assertSame(1, $this->canonicalPivotCount($preexisting['role']->id));
        $this->assertSame(0, DB::table('permission_audits')->where('event', self::AUDIT_EVENT)->count());
    }

    private function runMigration(string $direction): void
    {
        $migration = require database_path('migrations/'.self::MIGRATION_NAME.'.php');
        $migration->{$direction}();
    }

    /**
     * @param  list<string>  $permissions
     * @return array{user: User, role: AuthorizationRole, report: IncidentReport}
     */
    private function seedRoleFixture(array $permissions, bool $authorizationRoleIsAdmin = false): array
    {
        $suffix = bin2hex(random_bytes(4));
        $roleKey = 'phase214b_'.$suffix;
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $user = User::factory()->create(['organization_id' => $org->id, 'department_id' => $dept->id]);
        $reporter = User::factory()->create(['organization_id' => $org->id, 'department_id' => $dept->id]);

        $role = AuthorizationRole::create(['name' => $roleKey, 'label' => $roleKey, 'is_admin_role' => $authorizationRoleIsAdmin]);
        $this->seedScopedRoleDefinition($roleKey, $permissions);
        DB::table('model_has_scoped_roles')->insert([
            'user_id' => $user->id,
            'role' => $roleKey,
            'scope_type' => ScopedRole::SCOPE_ORGANIZATION,
            'scope_id' => $org->id,
            'inherit_to_children' => true,
            'source' => 'manual',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('authorization_role_assignments')->insert([
            'authorization_role_id' => $role->id,
            'user_id' => $user->id,
            'scope_type' => ScopedRole::SCOPE_ORGANIZATION,
            'scope_id' => $org->id,
            'organization_id' => $org->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $incidentType = IncidentType::create(['name' => 'phase214b_'.$suffix, 'name_ar' => 'phase214b', 'is_active' => true]);
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
            'incident_description' => 'phase 214b confidential report',
            'immediate_action_required' => false,
            'severity_level' => SeverityLevel::High,
            'status' => ReportStatus::New,
            'is_confidential' => true,
        ]);

        AccessDecision::flushCache();

        return ['user' => $user, 'role' => $role, 'report' => $report];
    }

    /** @param list<string> $permissions */
    private function seedScopedRoleDefinition(string $roleKey, array $permissions): void
    {
        $scopeType = ScopeType::firstOrCreate(
            ['key' => ScopedRole::SCOPE_ORGANIZATION],
            ['label_ar' => 'organization', 'label_en' => 'Organization', 'model_class' => Organization::class, 'supports_hierarchy' => true, 'is_active' => true, 'sort_order' => 0]
        );

        DB::table('scoped_role_definitions')->insert([
            'name' => 'organization.'.$roleKey,
            'display_name' => $roleKey,
            'scope_type' => ScopedRole::SCOPE_ORGANIZATION,
            'level' => 0,
            'is_active' => true,
            'role_key' => $roleKey,
            'label_ar' => $roleKey,
            'label_en' => $roleKey,
            'scope_type_id' => $scopeType->id,
            'color' => 'primary',
            'permissions' => json_encode($permissions),
            'is_admin_role' => false,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function canonicalPivotCount(int $roleId): int
    {
        $resource = AuthorizationResource::where('key', IncidentReport::class)->first();
        if ($resource === null) {
            return 0;
        }

        return DB::table('authorization_role_permissions')
            ->where('authorization_role_id', $roleId)
            ->where('authorization_resource_id', $resource->id)
            ->where('action', 'confidential')
            ->count();
    }

    private function insertCanonicalPivot(int $roleId): void
    {
        $resource = AuthorizationResource::firstOrCreate(['key' => IncidentReport::class], ['label' => 'IncidentReport']);
        DB::table('authorization_role_permissions')->insert([
            'authorization_role_id' => $roleId,
            'authorization_resource_id' => $resource->id,
            'action' => 'confidential',
        ]);
    }
}
