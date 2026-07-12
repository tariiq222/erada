<?php

namespace App\Modules\Core\Authorization\Services;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Contracts\AuthorizationAssignmentActorGuard;
use App\Modules\Core\Authorization\Data\AssignmentScope;
use App\Modules\Core\Authorization\Data\AssignmentWrite;
use App\Modules\Core\Authorization\Data\RoleAssignmentWrite;
use App\Modules\Core\Authorization\Exceptions\AuthorizationAssignmentDenied;
use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Authorization\Models\AuthorizationRoleAssignment;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class AuthorizationAssignmentService
{
    public function __construct(
        private AuthorizationAssignmentActorGuard $actorGuard,
        private AssignmentScopeResolver $scopeResolver,
    ) {}

    public function assign(
        User $actor,
        User $subject,
        AuthorizationRole $role,
        AssignmentWrite $write,
    ): AuthorizationRoleAssignment {
        $id = DB::transaction(function () use ($actor, $subject, $role, $write): int {
            $this->lockMutationParents($subject, [$role]);
            $this->validateWrite($actor, $subject, $role, $write);
            $organizationId = $this->scopeResolver->organizationId($write->scope, $subject);
            $identity = $this->identity($subject, $role, $write->scope);
            $values = [
                'organization_id' => $organizationId,
                'inherit_to_children' => $write->scope->inheritToChildren,
                'expires_at' => $write->expiresAt,
                'source' => $write->source,
                'granted_by' => $actor->id,
                'updated_at' => now(),
            ];

            $existing = AuthorizationRoleAssignment::query()->where($identity)->first();
            if ($existing !== null && $existing->source !== $write->source) {
                throw new AuthorizationAssignmentDenied('Assignment provenance cannot be overwritten.');
            }
            $old = $existing?->toArray();
            $assignment = AuthorizationRoleAssignment::query()->updateOrCreate($identity, $values);
            $this->auditMutation($actor, $subject, $role, $write->scope, $old, $assignment->fresh()->toArray(), 'assigned');
            $id = (int) $assignment->id;

            DB::afterCommit(static fn () => AccessDecision::flushCache());

            return $id;
        });

        return AuthorizationRoleAssignment::query()->findOrFail($id);
    }

    public function revoke(
        User $actor,
        User $subject,
        AuthorizationRole $role,
        AssignmentScope $scope,
    ): bool {
        return DB::transaction(function () use ($actor, $subject, $role, $scope): bool {
            $this->lockMutationParents($subject, [$role]);
            $this->assertRoleScopeCompatibility($role, $scope);
            $this->authorizeActor($actor, $subject, $role, $scope);
            $this->scopeResolver->organizationId($scope, $subject);

            $assignment = AuthorizationRoleAssignment::query()
                ->where($this->identity($subject, $role, $scope))
                ->first();
            $deleted = $assignment !== null;
            if ($assignment !== null) {
                $old = $assignment->toArray();
                $assignment->delete();
                $this->auditMutation($actor, $subject, $role, $scope, $old, null, 'revoked');
            }

            if ($deleted) {
                DB::afterCommit(static fn () => AccessDecision::flushCache());
            }

            return $deleted;
        });
    }

    /**
     * Replace one subject's scopes for one role as a single transaction.
     *
     * @param  list<AssignmentWrite>  $writes
     * @return list<AuthorizationRoleAssignment>
     */
    public function syncForRole(User $actor, User $subject, AuthorizationRole $role, array $writes): array
    {
        $ids = DB::transaction(function () use ($actor, $subject, $role, $writes): array {
            $this->lockMutationParents($subject, [$role]);
            $desired = [];

            foreach ($writes as $write) {
                if (! $write instanceof AssignmentWrite || $write->source !== 'manual') {
                    throw new AuthorizationAssignmentDenied('Role sync accepts manual AssignmentWrite values only.');
                }

                $this->validateWrite($actor, $subject, $role, $write);
                $key = $write->scope->semanticKey();
                if (isset($desired[$key])) {
                    throw new AuthorizationAssignmentDenied("Duplicate assignment scope [{$key}].");
                }
                $desired[$key] = $write;
            }

            $existing = AuthorizationRoleAssignment::query()
                ->where('user_id', $subject->id)
                ->where('authorization_role_id', $role->id)
                ->where('source', 'manual')
                ->lockForUpdate()
                ->get();

            foreach ($existing as $assignment) {
                $scope = new AssignmentScope(
                    (string) $assignment->scope_type,
                    $assignment->scope_id === null ? null : (int) $assignment->scope_id,
                    (bool) $assignment->inherit_to_children,
                );

                if (! isset($desired[$scope->semanticKey()])) {
                    $this->authorizeActor($actor, $subject, $role, $scope);
                    $old = $assignment->toArray();
                    $assignment->newQuery()->whereKey($assignment->id)->delete();
                    $this->auditMutation($actor, $subject, $role, $scope, $old, null, 'revoked');
                }
            }

            $ids = [];
            foreach ($desired as $write) {
                $organizationId = $this->scopeResolver->organizationId($write->scope, $subject);
                $identity = $this->identity($subject, $role, $write->scope);
                $existing = AuthorizationRoleAssignment::query()->where($identity)->first();
                if ($existing !== null && $existing->source !== 'manual') {
                    $ids[] = (int) $existing->id;

                    continue;
                }
                $old = $existing?->toArray();
                $assignment = AuthorizationRoleAssignment::query()->updateOrCreate($identity, [
                    'organization_id' => $organizationId,
                    'inherit_to_children' => $write->scope->inheritToChildren,
                    'expires_at' => $write->expiresAt,
                    'source' => $write->source,
                    'granted_by' => $actor->id,
                ]);
                $this->auditMutation($actor, $subject, $role, $write->scope, $old, $assignment->fresh()->toArray(), 'synced');
                $ids[] = (int) $assignment->id;
            }

            DB::afterCommit(static fn () => AccessDecision::flushCache());

            return $ids;
        });

        return AuthorizationRoleAssignment::query()->whereKey($ids)->get()->all();
    }

    /**
     * Atomically replace the user's manually managed canonical assignments.
     * Auto and migration provenance are deliberately preserved.
     *
     * @param  list<RoleAssignmentWrite>  $writes
     * @return list<AuthorizationRoleAssignment>
     */
    public function syncManual(User $actor, User $subject, array $writes): array
    {
        $ids = DB::transaction(function () use ($actor, $subject, $writes): array {
            $roles = array_map(fn (RoleAssignmentWrite $item) => $item->role, $writes);
            $this->lockMutationParents($subject, $roles);
            $desired = [];

            foreach ($writes as $item) {
                if (! $item instanceof RoleAssignmentWrite || $item->assignment->source !== 'manual') {
                    throw new AuthorizationAssignmentDenied('Manual sync accepts manual RoleAssignmentWrite values only.');
                }

                $this->validateWrite($actor, $subject, $item->role, $item->assignment);
                $key = $item->role->id.'|'.$item->assignment->scope->semanticKey();
                $desired[$key] = $item;
            }

            $existing = AuthorizationRoleAssignment::query()
                ->where('user_id', $subject->id)
                ->where('source', 'manual')
                ->lockForUpdate()
                ->get();

            foreach ($existing as $assignment) {
                $scope = new AssignmentScope(
                    (string) $assignment->scope_type,
                    $assignment->scope_id === null ? null : (int) $assignment->scope_id,
                    (bool) $assignment->inherit_to_children,
                );
                $key = $assignment->authorization_role_id.'|'.$scope->semanticKey();

                if (! isset($desired[$key])) {
                    $role = $assignment->role;
                    if ($role === null) {
                        throw new AuthorizationAssignmentDenied('Cannot authorize revocation for a missing canonical role.');
                    }
                    $this->authorizeActor($actor, $subject, $role, $scope);
                    $old = $assignment->toArray();
                    $assignment->delete();
                    $this->auditMutation($actor, $subject, $role, $scope, $old, null, 'revoked');
                }
            }

            $ids = [];
            foreach ($desired as $item) {
                $write = $item->assignment;
                $identity = $this->identity($subject, $item->role, $write->scope);
                $existing = AuthorizationRoleAssignment::query()->where($identity)->first();
                if ($existing !== null && $existing->source !== 'manual') {
                    $ids[] = (int) $existing->id;

                    continue;
                }
                $old = $existing?->toArray();
                $assignment = AuthorizationRoleAssignment::query()->updateOrCreate($identity, [
                    'organization_id' => $this->scopeResolver->organizationId($write->scope, $subject),
                    'inherit_to_children' => $write->scope->inheritToChildren,
                    'expires_at' => $write->expiresAt,
                    'source' => 'manual',
                    'granted_by' => $actor->id,
                ]);
                $this->auditMutation($actor, $subject, $item->role, $write->scope, $old, $assignment->fresh()->toArray(), 'synced');
                $ids[] = (int) $assignment->id;
            }

            DB::afterCommit(static fn () => AccessDecision::flushCache());

            return $ids;
        });

        return AuthorizationRoleAssignment::query()->whereKey($ids)->get()->all();
    }

    private function validateWrite(User $actor, User $subject, AuthorizationRole $role, AssignmentWrite $write): void
    {
        $active = AuthorizationRole::query()->lockForUpdate()->whereKey($role->id)->value('is_active');
        if (! $active) {
            throw new AuthorizationAssignmentDenied('Inactive roles cannot be assigned.');
        }

        if ($write->expiresAt !== null && ! $write->expiresAt->isFuture()) {
            throw new AuthorizationAssignmentDenied('Assignment expiry must be in the future.');
        }

        $this->assertRoleScopeCompatibility($role, $write->scope);
        $this->authorizeActor($actor, $subject, $role, $write->scope);
    }

    private function assertRoleScopeCompatibility(AuthorizationRole $role, AssignmentScope $scope): void
    {
        if (! $scope->isCompatibleWithRoleScope($role->scope_type)) {
            throw new AuthorizationAssignmentDenied(
                "Role scope [{$role->scope_type}] is incompatible with assignment scope [{$scope->type}]."
            );
        }
    }

    private function authorizeActor(User $actor, User $subject, AuthorizationRole $role, AssignmentScope $scope): void
    {
        if (! $this->actorGuard->allows($actor, $subject, $role, $scope)) {
            throw new AuthorizationAssignmentDenied('The actor cannot manage this canonical assignment.');
        }
    }

    /** @return array{authorization_role_id: int, user_id: int, scope_type: string, scope_id: int|null} */
    private function identity(User $subject, AuthorizationRole $role, AssignmentScope $scope): array
    {
        return [
            'authorization_role_id' => (int) $role->id,
            'user_id' => (int) $subject->id,
            'scope_type' => $scope->type,
            'scope_id' => $scope->id,
        ];
    }

    /** @param list<AuthorizationRole> $roles */
    private function lockMutationParents(User $subject, array $roles): void
    {
        User::query()->whereKey($subject->id)->lockForUpdate()->firstOrFail();
        $roleIds = collect($roles)->pluck('id')->map(fn ($id) => (int) $id)->sort()->values();
        if ($roleIds->isNotEmpty()) {
            AuthorizationRole::query()->whereKey($roleIds)->orderBy('id')->lockForUpdate()->get();
        }
    }

    private function auditMutation(
        User $actor,
        User $subject,
        AuthorizationRole $role,
        AssignmentScope $scope,
        ?array $old,
        ?array $new,
        string $event,
    ): void {
        DB::table('authorization_assignment_audits')->insert([
            'event' => 'canonical_assignment_'.$event,
            'actor_id' => $actor->id,
            'target_user_id' => $subject->id,
            'scope_type' => $scope->type,
            'scope_id' => $scope->id,
            'role' => $role->name,
            'old_value' => $old === null ? null : json_encode($old),
            'new_value' => $new === null ? null : json_encode($new),
            'reason' => 'canonical authorization assignment mutation',
            'ip_address' => null,
            'user_agent' => 'authorization-assignment-service',
            'created_at' => now(),
        ]);
    }
}
