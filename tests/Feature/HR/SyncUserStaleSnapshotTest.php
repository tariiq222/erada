<?php

namespace Tests\Feature\HR;

use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Authorization\Models\AuthorizationRoleAssignment;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\DepartmentCapacityRole;
use App\Modules\HR\Services\ScopedDepartmentRoleSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * CSD-CA23078-HR-001 — Lock + refresh invariant for
 * {@see ScopedDepartmentRoleSyncService::syncUser()}.
 *
 * syncUser() is reached from a chunk iterator over `$department->users()`
 * (see syncDepartment()), from observer hooks on User create/update, and
 * from direct controller calls. Any of those call paths can hand syncUser()
 * a User model that was loaded before a concurrent writer moved the user to
 * a different department.
 *
 * Pre-fix behaviour: the lock was acquired but its result was discarded, so
 * the captured `$user` (with its stale `department_id`) drove every scope
 * computation. Cleanup of auto-grants on scopes no longer expected built
 * `$expectedByScope` from the stale membership and then ran
 * `whereNotIn('scope_id', $expectedByScope)`. That excluded the dept the
 * user had just left, so the auto-grant on the old dept was never revoked
 * — a privilege-leak window until the next unrelated sync picked it up.
 *
 * Post-fix behaviour: the locked row is re-read into `$user` as the FIRST
 * statement of the transaction, so every downstream computation runs
 * against current DB state. The deptA auto-grant is revoked in the same
 * transaction that materializes the deptB auto-grant.
 *
 * This test asserts the post-fix invariant directly. The captured snapshot
 * is constructed by holding onto a User model loaded BEFORE the
 * department transfer; the transfer itself is performed via the query
 * builder (so the UserObserver's `updated` hook does not fire and re-sync
 * the user out from under the test).
 */
class SyncUserStaleSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_user_reloads_user_after_concurrent_department_transfer(): void
    {
        $organization = Organization::factory()->create();
        $deptA = Department::factory()->create(['organization_id' => $organization->id]);
        $deptB = Department::factory()->create(['organization_id' => $organization->id]);
        $memberRole = $this->role('dept_member');

        DepartmentCapacityRole::create([
            'department_id' => $deptA->id,
            'capacity' => DepartmentCapacityRole::CAPACITY_MEMBER,
            'role_key' => $memberRole->name,
        ]);
        DepartmentCapacityRole::create([
            'department_id' => $deptB->id,
            'capacity' => DepartmentCapacityRole::CAPACITY_MEMBER,
            'role_key' => $memberRole->name,
        ]);

        // CSD-CA23078-HR-002 — actor guard. Without an actor in the auth
        // context, every auto-grant in this test would be skipped with a
        // `skipped_no_actor` audit row, so we sign in as a canonical
        // super_admin before triggering any sync path. The observer-driven
        // create sync and the explicit sync below both inherit this actor
        // through `auth()->user()`.
        $this->actingAsSuperAdmin();

        $user = User::factory()->create([
            'organization_id' => $organization->id,
            'department_id' => $deptA->id,
        ]);

        // UserObserver::created fired syncUser($user) with the fresh model.
        // Assert the precondition: deptA auto-grant materialized, no deptB
        // grant yet.
        $this->assertDatabaseHas('authorization_role_assignments', [
            'authorization_role_id' => $memberRole->id,
            'user_id' => $user->id,
            'scope_type' => AuthorizationRoleAssignment::SCOPE_DEPARTMENT,
            'scope_id' => $deptA->id,
            'source' => 'auto',
        ]);
        $this->assertDatabaseMissing('authorization_role_assignments', [
            'user_id' => $user->id,
            'scope_id' => $deptB->id,
            'source' => 'auto',
        ]);

        // Simulate a concurrent department transfer: the user is moved to
        // deptB by another writer, between the time the caller captured
        // $user and the time syncUser acquires its row lock. Query-builder
        // update intentionally bypasses Eloquent model events so the
        // UserObserver's `updated` hook does NOT auto-re-sync — otherwise
        // the bug surface we are testing would be masked. The captured
        // $user below still has department_id = deptA in memory while the
        // DB row now has department_id = deptB.
        User::query()->whereKey($user->id)->update([
            'department_id' => $deptB->id,
            'updated_at' => now(),
        ]);

        $this->assertSame($deptA->id, $user->department_id, 'Captured snapshot is stale on purpose.');
        $this->assertSame($deptB->id, User::query()->whereKey($user->id)->value('department_id'), 'DB row moved by the simulated transfer.');

        // Trigger sync with the captured (stale) User model. With the fix,
        // syncUser's FIRST statement inside the transaction re-reads the
        // row and re-binds $user, so the scope computation sees deptB.
        app(ScopedDepartmentRoleSyncService::class)->syncUser($user);

        // Post-fix invariant: deptA auto-grant MUST be revoked and the
        // deptB auto-grant MUST be materialized. Pre-fix, deptA persisted
        // because $expectedByScope was built from the stale department_id
        // and excluded deptA from the cleanup `whereNotIn('scope_id', ...)`.
        $this->assertDatabaseMissing('authorization_role_assignments', [
            'authorization_role_id' => $memberRole->id,
            'user_id' => $user->id,
            'scope_type' => AuthorizationRoleAssignment::SCOPE_DEPARTMENT,
            'scope_id' => $deptA->id,
            'source' => 'auto',
        ]);
        $this->assertDatabaseHas('authorization_role_assignments', [
            'authorization_role_id' => $memberRole->id,
            'user_id' => $user->id,
            'scope_type' => AuthorizationRoleAssignment::SCOPE_DEPARTMENT,
            'scope_id' => $deptB->id,
            'organization_id' => $organization->id,
            'source' => 'auto',
        ]);

        // Exactly one row per (user, role, scope) — no duplicates, no
        // orphans left behind by the stale-snapshot cleanup.
        $this->assertSame(1, DB::table('authorization_role_assignments')
            ->where('user_id', $user->id)
            ->where('source', 'auto')
            ->count());

        // The deptA revocation is auditable — if the test ever regresses
        // to the pre-fix behaviour, this audit row would be absent.
        $this->assertDatabaseHas('authorization_assignment_audits', [
            'event' => 'canonical_assignment_revoked',
            'target_user_id' => $user->id,
            'scope_type' => AuthorizationRoleAssignment::SCOPE_DEPARTMENT,
            'scope_id' => $deptA->id,
            'role' => $memberRole->name,
        ]);
        $this->assertDatabaseHas('authorization_assignment_audits', [
            'event' => 'canonical_assignment_assigned',
            'target_user_id' => $user->id,
            'scope_type' => AuthorizationRoleAssignment::SCOPE_DEPARTMENT,
            'scope_id' => $deptB->id,
            'role' => $memberRole->name,
        ]);
    }

    private function role(string $name): AuthorizationRole
    {
        return AuthorizationRole::query()->updateOrCreate(['name' => $name], [
            'label' => $name,
            'scope_type' => 'department',
            'is_admin_role' => false,
            'is_system' => false,
            'is_active' => true,
        ]);
    }

    /**
     * Sign in as a freshly-created canonical super_admin so the
     * `auth()->user()` fallback inside {@see ScopedDepartmentRoleSyncService}
     * is non-null and the CSD-CA23078-HR-002 actor guard admits every
     * auto-grant this test wants to materialize.
     */
    private function actingAsSuperAdmin(): User
    {
        $super = User::factory()->create(['is_active' => true]);
        $this->grantCanonicalSuperAdmin($super);

        $this->actingAs($super, 'sanctum');

        return $super;
    }
}
