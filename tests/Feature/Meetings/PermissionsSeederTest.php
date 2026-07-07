<?php

namespace Tests\Feature\Meetings;

use Database\Seeders\Meetings\MeetingsPermissionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * PermissionsSeederTest — Phase 9 of master AuthZ unification plan.
 *
 * Pre-Phase 9: asserted that MeetingsPermissionsSeeder created the
 * three legacy kebab permissions (view-meetings, manage-meetings,
 * record-decisions) and granted them to admin.
 *
 * Phase 9 retires the legacy kebab strings. The seeder now seeds
 * the canonical dotted capabilities only:
 *   - meetings.view
 *   - meetings.create
 *   - meetings.edit
 *   - meetings.delete
 *   - meetings.record_decisions
 *
 * A new assertion (test_no_legacy_kebab_permissions_are_seeded)
 * pins the absence of the legacy strings so a regression that
 * re-introduces them fails this test.
 */
class PermissionsSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_canonical_meetings_permissions(): void
    {
        $this->seed(MeetingsPermissionsSeeder::class);

        $this->assertNotNull(Permission::where('name', 'meetings.view')->where('guard_name', 'web')->first());
        $this->assertNotNull(Permission::where('name', 'meetings.create')->where('guard_name', 'web')->first());
        $this->assertNotNull(Permission::where('name', 'meetings.edit')->where('guard_name', 'web')->first());
        $this->assertNotNull(Permission::where('name', 'meetings.delete')->where('guard_name', 'web')->first());
        $this->assertNotNull(Permission::where('name', 'meetings.record_decisions')->where('guard_name', 'web')->first());
    }

    public function test_seeder_grants_canonical_permissions_to_admin(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(MeetingsPermissionsSeeder::class);

        $admin = Role::where('name', 'admin')->where('guard_name', 'web')->firstOrFail();
        $this->assertTrue($admin->hasPermissionTo('meetings.view'));
        $this->assertTrue($admin->hasPermissionTo('meetings.create'));
        $this->assertTrue($admin->hasPermissionTo('meetings.edit'));
        $this->assertTrue($admin->hasPermissionTo('meetings.delete'));
        $this->assertTrue($admin->hasPermissionTo('meetings.record_decisions'));
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(MeetingsPermissionsSeeder::class);
        $this->seed(MeetingsPermissionsSeeder::class);
        $this->seed(MeetingsPermissionsSeeder::class);

        $this->assertEquals(1, Permission::where('name', 'meetings.view')->where('guard_name', 'web')->count());
    }

    public function test_no_legacy_kebab_permissions_are_seeded(): void
    {
        $this->seed(MeetingsPermissionsSeeder::class);

        foreach (['view-meetings', 'manage-meetings', 'record-decisions'] as $legacy) {
            $this->assertNull(
                Permission::where('name', $legacy)->where('guard_name', 'web')->first(),
                "legacy kebab permission '{$legacy}' must not be seeded after Phase 9"
            );
        }
    }

    public function test_admin_does_not_carry_legacy_kebab_permissions(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(MeetingsPermissionsSeeder::class);

        $admin = Role::where('name', 'admin')->where('guard_name', 'web')->firstOrFail();

        // Spatie's Role::hasPermissionTo() throws PermissionDoesNotExist
        // when the permission name isn't registered at all. After Phase 9
        // the legacy kebab strings are absent from the permissions table,
        // so we assert against the role's loaded permission list instead
        // of the throwing helper.
        $granted = $admin->permissions->pluck('name')->all();

        foreach (['view-meetings', 'manage-meetings', 'record-decisions'] as $legacy) {
            $this->assertNotContains(
                $legacy,
                $granted,
                "admin role must not carry legacy kebab permission '{$legacy}' after Phase 9"
            );
        }
    }
}
