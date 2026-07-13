<?php

namespace Tests\Feature\Core\Authorization;

use App\Modules\Core\Authorization\Models\AuthorizationRole;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * CSD-CA23078-SEEDER-001 — role catalog sync safety net.
 *
 * The canonical role catalog lives in `RolesAndPermissionsSeeder::roleCatalog()`
 * and is the single source of truth for which (role, resource, action) pivots
 * should exist. Without a sweep, pivots from a previous catalog version
 * linger and silently grant obsolete capabilities — the bug this ticket
 * fixes.
 *
 * Contract under test:
 *   - Only seeded SYSTEM roles are swept (super_admin, admin, viewer,
 *     dept_manager, member — the role names registered by
 *     RolesAndPermissionsSeeder::roleCatalog()).
 *   - Custom roles (any role whose name is NOT in the catalog) are NEVER
 *     touched.
 *   - super_admin is excluded from the sweep (preserved semantics — its
 *     pivots are admin-managed and a safety-net overwrite would clobber
 *     operator overrides).
 *   - For every deleted pivot, exactly one audit row is written to
 *     authorization_assignment_audits with event
 *     'role_catalog_sync_obsolete_pivot_removed'.
 *   - Re-running the seeder is a no-op for the audit log count.
 */
class RoleCatalogSyncTest extends TestCase
{
    use RefreshDatabase;

    private const AUDIT_EVENT = 'role_catalog_sync_obsolete_pivot_removed';

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Role catalog sync test is PostgreSQL-only.');
        }
    }

    public function test_seeder_removes_obsolete_pivot_for_viewer_role(): void
    {
        // Establish the canonical catalog so a Comment resource exists.
        $this->seed(RolesAndPermissionsSeeder::class);

        $viewerRole = AuthorizationRole::query()->where('name', 'viewer')->firstOrFail();
        $commentResourceId = DB::table('authorization_resources')
            ->where('key', 'App\\Modules\\Shared\\Models\\Comment')
            ->value('id');

        $this->assertNotNull($commentResourceId, 'Comment authorization resource missing after seed.');

        // Inject a phantom pivot: viewer does not have `comments.create` in
        // its catalog (only `comments.view` is granted), so this row is
        // obsolete the moment the seeder runs.
        DB::table('authorization_role_permissions')->insert([
            'authorization_role_id' => $viewerRole->id,
            'authorization_resource_id' => $commentResourceId,
            'action' => 'create',
            'reach' => null,
        ]);

        $phantomKey = [
            'authorization_role_id' => $viewerRole->id,
            'authorization_resource_id' => $commentResourceId,
            'action' => 'create',
        ];

        $this->assertDatabaseHas('authorization_role_permissions', $phantomKey);

        $auditsBefore = $this->countAudits();

        // Re-run the seeder — the sweep must drop the phantom pivot.
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertDatabaseMissing('authorization_role_permissions', $phantomKey);

        // Exactly one new audit row was written for this pivot.
        $auditsAfter = $this->countAudits();
        $this->assertSame($auditsBefore + 1, $auditsAfter, 'Expected exactly one audit row for the removed phantom pivot.');

        $this->assertDatabaseHas('authorization_assignment_audits', [
            'event' => self::AUDIT_EVENT,
            'role' => 'viewer',
        ]);
    }

    public function test_seeder_preserves_super_admin_pivots(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $superAdminRole = AuthorizationRole::query()->where('name', 'super_admin')->firstOrFail();
        $commentResourceId = DB::table('authorization_resources')
            ->where('key', 'App\\Modules\\Shared\\Models\\Comment')
            ->value('id');

        $this->assertNotNull($commentResourceId, 'Comment authorization resource missing after seed.');

        // The catalog never produces a (super_admin, Comment, legacy_action)
        // triple — `legacy_action` is not the suffix of any Capability::
        // constant, so it cannot appear in the mapped set for super_admin
        // (which equals Capability::all()). This makes the row a phantom
        // pivot that the sweep MUST NOT touch on the super_admin role.
        $preservedKey = [
            'authorization_role_id' => $superAdminRole->id,
            'authorization_resource_id' => $commentResourceId,
            'action' => 'legacy_action',
        ];

        DB::table('authorization_role_permissions')->insert($preservedKey + ['reach' => null]);
        $this->assertDatabaseHas('authorization_role_permissions', $preservedKey);

        $auditsBefore = $this->countAudits();

        $this->seed(RolesAndPermissionsSeeder::class);

        // The phantom must still be there — super_admin is excluded.
        $this->assertDatabaseHas('authorization_role_permissions', $preservedKey);

        // No new audits were written for the super_admin pivot.
        $this->assertSame(
            $auditsBefore,
            $this->countAudits(),
            'super_admin pivots must not produce any audit rows; the sweep is excluded for this role.',
        );
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $viewerRole = AuthorizationRole::query()->where('name', 'viewer')->firstOrFail();
        $commentResourceId = DB::table('authorization_resources')
            ->where('key', 'App\\Modules\\Shared\\Models\\Comment')
            ->value('id');

        DB::table('authorization_role_permissions')->insert([
            'authorization_role_id' => $viewerRole->id,
            'authorization_resource_id' => $commentResourceId,
            'action' => 'create',
            'reach' => null,
        ]);

        $this->seed(RolesAndPermissionsSeeder::class);

        $auditsAfterFirstRun = $this->countAudits();
        $this->assertGreaterThan(0, $auditsAfterFirstRun, 'First run with a phantom pivot should produce at least one audit.');

        // Second run on a now-clean catalog: no new audits.
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->assertSame(
            $auditsAfterFirstRun,
            $this->countAudits(),
            'Idempotent re-run must not increase the audit log count for role_catalog_sync_obsolete_pivot_removed.',
        );
    }

    private function countAudits(): int
    {
        return (int) DB::table('authorization_assignment_audits')
            ->where('event', self::AUDIT_EVENT)
            ->count();
    }
}
