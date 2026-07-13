<?php

namespace App\Modules\HR\Services;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Contracts\AuthorizationAssignmentActorGuard;
use App\Modules\Core\Authorization\Data\AssignmentScope;
use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Authorization\Models\AuthorizationRoleAssignment;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\DepartmentCapacityRole;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Assigns scoped department roles automatically by capacity:
 * - members (users with department_id = dept) receive the dept's 'member' role_keys
 * - the manager (departments.manager_id) receives the dept's 'manager' role_keys
 * All grants carry source='auto'; manual delegations are never touched.
 */
class ScopedDepartmentRoleSyncService
{
    public function __construct(
        private readonly AuthorizationAssignmentActorGuard $assignmentActorGuard,
    ) {}

    /**
     * Whether the department's capacity policy still expects this role as an
     * auto grant for this user — member capacity if the user is a member of the
     * department, manager capacity if the user manages it. Drives the
     * downgrade-instead-of-delete decision on manual role removal.
     */
    public function isExpectedAutoRole(User $user, int $departmentId, string $role): bool
    {
        // member capacity applies if the user's department is this department
        if ((int) $user->department_id === $departmentId) {
            $member = DepartmentCapacityRole::where('department_id', $departmentId)
                ->where('capacity', DepartmentCapacityRole::CAPACITY_MEMBER)
                ->where('role_key', $role)->exists();
            if ($member) {
                return true;
            }
        }

        // manager capacity applies if the user manages this department
        $managesDept = Department::where('id', $departmentId)
            ->where('manager_id', $user->id)->exists();
        if ($managesDept) {
            return DepartmentCapacityRole::where('department_id', $departmentId)
                ->where('capacity', DepartmentCapacityRole::CAPACITY_MANAGER)
                ->where('role_key', $role)->exists();
        }

        return false;
    }

    /**
     * @param  User|null  $actor  When set, every materialized auto-grant is
     *                            validated against this actor's authority
     *                            (CSD-CA23078-HR-002). Falls back to
     *                            `auth()->user()` so observer-driven syncs
     *                            inherit the surrounding HTTP context; if no
     *                            actor resolves (CLI, queue worker, no-auth
     *                            observer), the grant is skipped with a
     *                            warning log — fail closed, no DB writes.
     */
    public function syncUser(User $user, ?User $actor = null): void
    {
        $actor ??= auth()->user();

        DB::transaction(function () use (&$user, $actor): void {
            // CSD-CA23078-HR-001 — Lock + refresh invariant.
            //
            // The caller may hand us a stale User snapshot (e.g. a chunk
            // iterator loaded N rows ago before another writer moved the
            // user's department, or a captured $user from before a
            // department transfer). The row lock prevents concurrent
            // mutation, and re-binding $user to the freshly-locked model
            // guarantees every downstream scope computation in this
            // transaction — membership, leadership, stale-assignment
            // cleanup — runs against current DB state, not the captured
            // snapshot. Without the re-bind, a user already moved out of
            // deptA keeps the deptA auto-grant materialized because the
            // cleanup `whereNotIn('scope_id', $expectedByScope)` is
            // computed from the stale $user->department_id and excludes
            // deptA from the revocation set. Must be the FIRST statement
            // inside the transaction so the lock is acquired before any
            // other reader sees a half-applied state.
            $user = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            // Scopes this user currently SHOULD hold an auto role on.
            $expectedByScope = [];

            // Membership
            if ($user->department_id !== null) {
                $expectedByScope[(int) $user->department_id] = $this->capacityRoleKeys(
                    (int) $user->department_id,
                    DepartmentCapacityRole::CAPACITY_MEMBER,
                );
            }

            // Leadership (one or more departments where this user is the manager)
            $managedDeptIds = Department::where('manager_id', $user->id)->pluck('id');
            foreach ($managedDeptIds as $deptId) {
                $scopeId = (int) $deptId;
                $expectedByScope[$scopeId] = array_values(array_unique(array_merge(
                    $expectedByScope[$scopeId] ?? [],
                    $this->capacityRoleKeys($scopeId, DepartmentCapacityRole::CAPACITY_MANAGER),
                )));
            }

            foreach ($expectedByScope as $scopeId => $roleKeys) {
                $this->syncAutoAssignmentsForScope($user, $scopeId, $roleKeys, $actor);
            }

            // Cleanup only automatic department assignments on scopes no longer expected.
            $staleAssignments = AuthorizationRoleAssignment::query()
                ->with('role')
                ->where('user_id', $user->id)
                ->where('source', 'auto')
                ->where('scope_type', AuthorizationRoleAssignment::SCOPE_DEPARTMENT)
                ->when($expectedByScope !== [], fn ($query) => $query->whereNotIn('scope_id', array_keys($expectedByScope)))
                ->lockForUpdate()
                ->get();
            foreach ($staleAssignments as $assignment) {
                $old = $assignment->toArray();
                $assignment->delete();
                $this->auditMutation($user, $assignment, $assignment->role?->name, $old, null, 'revoked');
            }

            DB::afterCommit(static fn () => AccessDecision::flushUserCache((int) $user->id));
        });
    }

    /**
     * @param  User|null  $actor  See {@see syncUser()}.
     */
    public function syncDepartment(Department $department, ?User $actor = null): void
    {
        $actor ??= auth()->user();

        $department->users()->chunkById(200, function (Collection $users) use ($actor) {
            foreach ($users as $user) {
                $this->syncUser($user, $actor);
            }
        });

        if ($department->manager_id !== null) {
            $manager = User::find($department->manager_id);
            if ($manager !== null) {
                $this->syncUser($manager, $actor);
            }
        }

        // Users holding an auto role on this department but no longer member/manager.
        $holderIds = AuthorizationRoleAssignment::query()
            ->where('source', 'auto')
            ->where('scope_type', AuthorizationRoleAssignment::SCOPE_DEPARTMENT)
            ->where('scope_id', $department->id)
            ->pluck('user_id')
            ->unique();

        User::whereIn('id', $holderIds)->chunkById(200, function (Collection $users) use ($actor) {
            foreach ($users as $user) {
                $this->syncUser($user, $actor);
            }
        });
    }

    /** @return list<string> */
    private function capacityRoleKeys(int $departmentId, string $capacity): array
    {
        return DepartmentCapacityRole::query()
            ->where('department_id', $departmentId)
            ->where('capacity', $capacity)
            ->pluck('role_key')
            ->all();
    }

    /**
     * @param  list<string>  $roleKeys
     * @param  User|null  $actor  See {@see syncUser()}.
     */
    private function syncAutoAssignmentsForScope(User $user, int $departmentId, array $roleKeys, ?User $actor = null): void
    {
        $department = Department::query()->findOrFail($departmentId);
        if ($user->organization_id === null || (int) $user->organization_id !== (int) $department->organization_id) {
            // Fail closed without turning an observer side-effect into a failed
            // user save. Any previously materialized automatic grants on the
            // now-invalid scope are revoked and audited; no new grant is made.
            $invalidAssignments = AuthorizationRoleAssignment::query()
                ->with('role')
                ->where('user_id', $user->id)
                ->where('source', 'auto')
                ->where('scope_type', AuthorizationRoleAssignment::SCOPE_DEPARTMENT)
                ->where('scope_id', $departmentId)
                ->lockForUpdate()
                ->get();
            foreach ($invalidAssignments as $assignment) {
                $old = $assignment->toArray();
                $assignment->delete();
                $this->auditMutation($user, $assignment, $assignment->role?->name, $old, null, 'revoked');
            }

            return;
        }

        $roles = AuthorizationRole::query()
            ->whereIn('name', $roleKeys ?: ['__none__'])
            ->where('scope_type', AuthorizationRoleAssignment::SCOPE_DEPARTMENT)
            ->where('is_active', true)
            ->lockForUpdate()
            ->get()
            ->keyBy('name');

        $missing = array_values(array_diff($roleKeys, $roles->keys()->all()));
        if ($missing !== []) {
            throw new RuntimeException('Missing active canonical capacity roles: '.implode(', ', $missing));
        }

        $roleIds = $roles->pluck('id')->map(fn ($id) => (int) $id)->all();
        $staleAssignments = AuthorizationRoleAssignment::query()
            ->with('role')
            ->where('user_id', $user->id)
            ->where('source', 'auto')
            ->where('scope_type', AuthorizationRoleAssignment::SCOPE_DEPARTMENT)
            ->where('scope_id', $departmentId)
            ->whereNotIn('authorization_role_id', $roleIds ?: [0])
            ->lockForUpdate()
            ->get();
        foreach ($staleAssignments as $assignment) {
            $old = $assignment->toArray();
            $assignment->delete();
            $this->auditMutation($user, $assignment, $assignment->role?->name, $old, null, 'revoked');
        }

        foreach ($roles as $role) {
            $identity = [
                'authorization_role_id' => $role->id,
                'user_id' => $user->id,
                'scope_type' => AuthorizationRoleAssignment::SCOPE_DEPARTMENT,
                'scope_id' => $departmentId,
            ];

            // A manual/migration row with the same semantic identity takes precedence.
            if (AuthorizationRoleAssignment::query()->where($identity)->exists()) {
                continue;
            }

            // CSD-CA23078-HR-002 — actor guard against privilege escalation via
            // auto-grants. Defense in depth: the controller already gates the
            // policy write, but if any path slips a non-super_admin actor
            // through (observer, queue, CLI), the sync must still refuse to
            // materialize a role they could not have assigned themselves.
            if ($actor === null) {
                Log::warning('Skipping capacity auto-grant: no actor in sync context', [
                    'subject_user_id' => $user->id,
                    'department_id' => $departmentId,
                    'role' => $role->name,
                ]);
                $this->auditMutation(
                    $user,
                    null,
                    $role->name,
                    null,
                    null,
                    'skipped_no_actor',
                );

                continue;
            }

            $scope = new AssignmentScope(
                AuthorizationRoleAssignment::SCOPE_DEPARTMENT,
                $departmentId,
            );

            if (! $this->assignmentActorGuard->allows($actor, $user, $role, $scope)) {
                Log::warning('Skipping capacity auto-grant: actor guard denied', [
                    'actor_id' => $actor->id,
                    'subject_user_id' => $user->id,
                    'department_id' => $departmentId,
                    'role' => $role->name,
                ]);
                $this->auditMutation(
                    $user,
                    null,
                    $role->name,
                    null,
                    null,
                    'skipped_actor_denied',
                    actorId: (int) $actor->id,
                );

                continue;
            }

            $assignment = AuthorizationRoleAssignment::query()->create($identity + [
                'organization_id' => $department->organization_id,
                'inherit_to_children' => true,
                'expires_at' => null,
                'source' => 'auto',
                'granted_by' => null,
            ]);
            $this->auditMutation($user, $assignment, $role->name, null, $assignment->fresh()->toArray(), 'assigned');
        }
    }

    private function auditMutation(
        User $subject,
        ?AuthorizationRoleAssignment $assignment,
        ?string $role,
        ?array $old,
        ?array $new,
        string $event,
        ?int $actorId = null,
    ): void {
        DB::table('authorization_assignment_audits')->insert([
            'event' => 'canonical_assignment_'.$event,
            'actor_id' => $actorId,
            'target_user_id' => $subject->id,
            'scope_type' => $assignment?->scope_type,
            'scope_id' => $assignment?->scope_id,
            'role' => $role,
            'old_value' => $old === null ? null : json_encode($old),
            'new_value' => $new === null ? null : json_encode($new),
            'reason' => 'automatic department capacity assignment mutation',
            'ip_address' => null,
            'user_agent' => 'scoped-department-role-sync-service',
            'created_at' => now(),
        ]);
    }
}
