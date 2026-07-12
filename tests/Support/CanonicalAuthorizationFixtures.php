<?php

namespace Tests\Support;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Data\AssignmentScope;
use App\Modules\Core\Authorization\Models\AuthorizationResource;
use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Authorization\Models\AuthorizationRoleAssignment;
use App\Modules\Core\Authorization\Models\AuthorizationRolePermission;
use App\Modules\Core\Authorization\Support\CapabilityToAuthorizationRolePermission;
use App\Modules\Core\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use InvalidArgumentException;

trait CanonicalAuthorizationFixtures
{
    /** @param list<string>|null $capabilities @param array<string, string>|null $reach */
    protected function assignCanonicalRole(User $user, string $roleName, string $scopeType = AuthorizationRoleAssignment::SCOPE_ORGANIZATION, ?int $scopeId = null, ?array $capabilities = null, ?array $reach = null): AuthorizationRoleAssignment
    {
        if (! isset(RolesAndPermissionsSeeder::roleCatalog()[$roleName])) {
            throw new InvalidArgumentException("Unknown canonical role [{$roleName}].");
        }
        if ($scopeType === AuthorizationRoleAssignment::SCOPE_ORGANIZATION && $scopeId === null) {
            if ($user->organization_id === null) {
                $scopeType = AuthorizationRoleAssignment::SCOPE_OWN;
            } else {
                $scopeId = (int) $user->organization_id;
            }
        }
        $scope = new AssignmentScope($scopeType, $scopeId);
        $role = AuthorizationRole::query()->where('name', $roleName)->first();
        if ($role === null) {
            $this->seed(RolesAndPermissionsSeeder::class);
            $role = AuthorizationRole::query()->where('name', $roleName)->firstOrFail();
        }
        foreach ($capabilities ?? [] as $capability) {
            $mapping = CapabilityToAuthorizationRolePermission::map($capability);
            if ($mapping === null) {
                throw new InvalidArgumentException("Capability [{$capability}] has no canonical resource mapping.");
            }
            $resource = AuthorizationResource::query()->firstOrCreate(['key' => $mapping['resource']], ['label' => class_basename($mapping['resource'])]);
            AuthorizationRolePermission::query()->updateOrCreate(['authorization_role_id' => $role->id, 'authorization_resource_id' => $resource->id, 'action' => $mapping['action']], ['reach' => $reach]);
        }
        $assignment = AuthorizationRoleAssignment::query()->updateOrCreate(
            ['authorization_role_id' => $role->id, 'user_id' => $user->id, 'scope_type' => $scope->type, 'scope_id' => $scope->id],
            ['organization_id' => $scope->type === AuthorizationRoleAssignment::SCOPE_ORGANIZATION ? $scope->id : ($scope->type === AuthorizationRoleAssignment::SCOPE_ALL ? null : $user->organization_id), 'inherit_to_children' => false, 'expires_at' => null, 'source' => 'manual', 'granted_by' => null],
        );
        AccessDecision::flushCache();

        return $assignment->load('role');
    }

    protected function grantCanonicalSuperAdmin(User $user): AuthorizationRoleAssignment
    {
        return $this->assignCanonicalRole($user, 'super_admin', AuthorizationRoleAssignment::SCOPE_ALL);
    }

    protected function grantCanonicalAdmin(User $user, string $scopeType = AuthorizationRoleAssignment::SCOPE_ORGANIZATION, ?int $scopeId = null): AuthorizationRoleAssignment
    {
        return $this->assignCanonicalRole($user, 'admin', $scopeType, $scopeId);
    }

    protected function grantCanonicalViewer(User $user, string $scopeType = AuthorizationRoleAssignment::SCOPE_ORGANIZATION, ?int $scopeId = null): AuthorizationRoleAssignment
    {
        return $this->assignCanonicalRole($user, 'viewer', $scopeType, $scopeId);
    }
}
