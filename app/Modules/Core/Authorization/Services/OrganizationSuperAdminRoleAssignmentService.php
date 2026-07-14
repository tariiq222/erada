<?php

namespace App\Modules\Core\Authorization\Services;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Data\AssignmentScope;
use App\Modules\Core\Authorization\Data\AssignmentWrite;
use App\Modules\Core\Authorization\Data\RoleAssignmentWrite;
use App\Modules\Core\Authorization\Exceptions\AuthorizationAssignmentDenied;
use App\Modules\Core\Authorization\Models\AuthorizationRoleAssignment;
use App\Modules\Core\Models\User;
use App\Modules\Shared\Models\ActivityLog;
use Illuminate\Support\Facades\DB;

/**
 * CSD-CA23078-CORE-009 (OrgSuper rewrite).
 *
 * Composes the canonical AuthorizationAssignmentService but replaces the actor
 * guard with the OrgSuper-specific guard. Server-derives scope from
 * actor.organization_id regardless of any client-supplied
 * scope_type / scope_id / inherit_to_children values. Writes through the same
 * authorization_role_assignments table inside a DB transaction. Audit row is
 * tagged provenance=organization_super_admin.
 */
final readonly class OrganizationSuperAdminRoleAssignmentService
{
    public function __construct(
        private OrganizationSuperAdminRoleAssignmentActorGuard $actorGuard,
        private AssignmentScopeResolver $scopeResolver,
    ) {}

    /**
     * @param  list<RoleAssignmentWrite>  $writes
     * @param  array{ip_address?: ?string, user_agent?: ?string, request_id?: ?string}  $auditContext
     * @return list<AuthorizationRoleAssignment>
     */
    public function syncManual(User $actor, User $subject, array $writes, array $auditContext = []): array
    {
        $serverScope = $this->serverDerivedScope($actor);
        $serverWrites = [];

        foreach ($writes as $item) {
            if (! $item instanceof RoleAssignmentWrite) {
                throw new AuthorizationAssignmentDenied('OrgSuper sync accepts RoleAssignmentWrite values only.');
            }
            if ($item->assignment->source !== 'manual') {
                throw new AuthorizationAssignmentDenied('OrgSuper sync accepts manual source only.');
            }

            if (! $this->actorGuard->allows($actor, $subject, $item->role, $serverScope)) {
                throw new AuthorizationAssignmentDenied(
                    "OrgSuper [{$actor->id}] cannot assign role [{$item->role->name}] to subject [{$subject->id}] in scope [{$serverScope->type}:{$serverScope->id}]."
                );
            }

            $overriddenWrite = new AssignmentWrite($serverScope, null, 'manual');
            $serverWrites[] = new RoleAssignmentWrite($item->role, $overriddenWrite);
        }

        return DB::transaction(function () use ($actor, $subject, $serverWrites, $auditContext): array {
            $roleIds = collect($serverWrites)->pluck('role.id')->all();
            $existing = AuthorizationRoleAssignment::query()
                ->where('user_id', $subject->id)
                ->where('source', 'manual')
                ->whereHas('role', fn ($roleQuery) => $roleQuery->whereIn('id', $roleIds))
                ->lockForUpdate()
                ->get();

            foreach ($existing as $assignment) {
                $assignment->delete();
            }

            $created = [];
            foreach ($serverWrites as $item) {
                $scope = $item->assignment->scope;
                $organizationId = $this->scopeResolver->organizationId($scope, $subject);
                $identity = [
                    'user_id' => $subject->id,
                    'authorization_role_id' => $item->role->id,
                    'scope_type' => $scope->type,
                    'scope_id' => $scope->id,
                    'source' => 'manual',
                ];
                $row = AuthorizationRoleAssignment::query()->updateOrCreate($identity, [
                    'organization_id' => $organizationId,
                    'inherit_to_children' => false,
                    'expires_at' => null,
                    'source' => 'manual',
                    'granted_by' => $actor->id,
                    'updated_at' => now(),
                ]);
                $created[] = $row;
            }

            DB::afterCommit(static fn () => AccessDecision::flushCache());

            ActivityLog::create([
                'user_id' => $actor->id,
                'action' => ActivityLog::ACTION_SYSTEM_ROLE_ASSIGNED,
                'description' => "Organization Super Admin role assignment: {$subject->name}",
                'loggable_type' => User::class,
                'loggable_id' => $subject->id,
                'metadata' => array_merge([
                    'provenance' => 'organization_super_admin',
                    'request_id' => $auditContext['request_id'] ?? null,
                    'role_ids' => $roleIds,
                ]),
                'ip_address' => $auditContext['ip_address'] ?? null,
                'user_agent' => $auditContext['user_agent'] ?? null,
            ]);

            return $created;
        });
    }

    private function serverDerivedScope(User $actor): AssignmentScope
    {
        if ($actor->organization_id === null) {
            throw new AuthorizationAssignmentDenied('OrgSuper actor has no organization context.');
        }

        return new AssignmentScope(
            AssignmentScope::ORGANIZATION,
            (int) $actor->organization_id,
            false,
        );
    }
}
