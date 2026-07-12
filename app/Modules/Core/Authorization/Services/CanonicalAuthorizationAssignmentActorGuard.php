<?php

namespace App\Modules\Core\Authorization\Services;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Authorization\Contracts\AuthorizationAssignmentActorGuard;
use App\Modules\Core\Authorization\Data\AssignmentScope;
use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Authorization\Models\AuthorizationRoleAssignment;
use App\Modules\Core\Authorization\Support\CapabilityToAuthorizationRolePermission;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Model;

final class CanonicalAuthorizationAssignmentActorGuard implements AuthorizationAssignmentActorGuard
{
    public function __construct(private readonly AssignmentScopeResolver $scopeResolver) {}

    public function allows(User $actor, User $subject, AuthorizationRole $role, AssignmentScope $scope): bool
    {
        if (! $scope->isCompatibleWithRoleScope($role->scope_type)) {
            return false;
        }

        $superAdmin = $this->isCanonicalSuperAdmin($actor);
        if ($scope->type === AssignmentScope::ALL) {
            return $superAdmin;
        }

        $target = $this->scopeResolver->target($scope, $subject);
        if ($target === null
            || ! AccessDecision::canonicalTrace($actor, Capability::CORE_ASSIGN_ROLES, $target)['granted']) {
            return false;
        }

        if ($role->is_admin_role) {
            return $superAdmin;
        }

        if ($superAdmin) {
            return true;
        }

        return $this->roleFitsActorAuthority($actor, $role, $target);
    }

    private function roleFitsActorAuthority(User $actor, AuthorizationRole $role, Model $target): bool
    {
        $descriptors = collect(CapabilityToAuthorizationRolePermission::mapAll());

        foreach ($role->permissions()->with('resource')->get() as $permission) {
            $capabilities = $descriptors->filter(fn (array $descriptor) => $descriptor['resource'] === $permission->resource?->key
                && $descriptor['action'] === $permission->action
            )->pluck('capability');

            if ($capabilities->isEmpty()
                || $capabilities->contains(Capability::CORE_ASSIGN_ROLES)
                || $capabilities->contains(fn (string $capability) => ! AccessDecision::canonicalTrace($actor, $capability, $target)['granted']
                )) {
                return false;
            }
        }

        return true;
    }

    private function isCanonicalSuperAdmin(User $actor): bool
    {
        return AuthorizationRoleAssignment::query()
            ->where('user_id', $actor->id)
            ->where('scope_type', AuthorizationRoleAssignment::SCOPE_ALL)
            ->whereNull('scope_id')
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->whereHas('role', fn ($query) => $query
                ->where('name', 'super_admin')
                ->where('scope_type', AuthorizationRoleAssignment::SCOPE_ALL)
                ->where('is_admin_role', true)
                ->where('is_active', true))
            ->exists();
    }
}
