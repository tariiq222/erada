<?php

namespace Tests\Support;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Authorization\Data\AssignmentScope;
use App\Modules\Core\Authorization\Models\AuthorizationResource;
use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Authorization\Models\AuthorizationRoleAssignment;
use App\Modules\Core\Authorization\Models\AuthorizationRolePermission;
use App\Modules\Core\Authorization\Support\CapabilityToAuthorizationRolePermission;
use App\Modules\Core\Models\User;
use InvalidArgumentException;

/**
 * Builds authorization fixtures exclusively through the canonical graph.
 *
 * Every grant creates an active AuthorizationRole, its resource/action pivots,
 * and one live AuthorizationRoleAssignment. Tests using this trait therefore
 * exercise the same data source used by enforce mode and cannot accidentally
 * pass through a retired authorization fallback.
 */
trait GrantsEngineCapability
{
    /**
     * @param  string|array<int, string>  $capability
     * @param  array<string, mixed>  $definitionFlags
     * @param  array<string, string>|null  $reach
     */
    protected function grantEngineCapability(
        User $user,
        string|array $capability,
        string $scopeType = 'organization',
        ?int $scopeId = null,
        ?string $roleKey = null,
        array $definitionFlags = [],
        ?array $reach = null
    ): void {
        $capabilities = is_array($capability) ? array_values(array_unique($capability)) : [$capability];
        $capabilities = array_values(array_unique(array_merge(
            $capabilities,
            $this->expandLegacyFlagsToCapabilities($definitionFlags)
        )));
        $roleKey ??= 'test_role_'.bin2hex(random_bytes(4));

        if (in_array($scopeType, [AssignmentScope::ALL, AssignmentScope::OWN], true)) {
            $scopeId = null;
        } elseif ($scopeId === null && $scopeType === 'organization') {
            if ($user->organization_id === null) {
                $scopeType = AssignmentScope::ALL;
            } else {
                $scopeId = $user->organization_id;
            }
        }

        $scope = new AssignmentScope(
            $scopeType,
            $scopeId,
            (bool) ($definitionFlags['inherit_to_children'] ?? false),
        );

        $role = AuthorizationRole::query()->updateOrCreate(
            ['name' => $roleKey],
            [
                'label' => $roleKey,
                'label_ar' => $roleKey,
                'label_en' => $roleKey,
                'scope_type' => $scope->type,
                'is_admin_role' => (bool) ($definitionFlags['is_admin_role'] ?? false),
                'is_system' => (bool) ($definitionFlags['is_system'] ?? $roleKey === 'super_admin'),
                'is_active' => true,
            ],
        );

        foreach ($capabilities as $capabilityKey) {
            $mapping = CapabilityToAuthorizationRolePermission::map($capabilityKey);
            if ($mapping === null) {
                throw new InvalidArgumentException("Capability [{$capabilityKey}] has no canonical resource mapping.");
            }

            $resource = AuthorizationResource::query()->firstOrCreate(
                ['key' => $mapping['resource']],
                ['label' => $mapping['resource']],
            );

            $permissionIdentity = [
                'authorization_role_id' => $role->id,
                'authorization_resource_id' => $resource->id,
                'action' => $mapping['action'],
            ];

            if (AuthorizationRolePermission::query()->where($permissionIdentity)->exists()) {
                AuthorizationRolePermission::query()->where($permissionIdentity)->update(['reach' => $reach]);
            } else {
                AuthorizationRolePermission::query()->create($permissionIdentity + ['reach' => $reach]);
            }
        }

        AuthorizationRoleAssignment::query()->updateOrCreate(
            [
                'authorization_role_id' => $role->id,
                'user_id' => $user->id,
                'scope_type' => $scope->type,
                'scope_id' => $scope->id,
            ],
            [
                'organization_id' => $this->assignmentOrganizationId($user, $scope),
                'inherit_to_children' => $scope->inheritToChildren,
                'expires_at' => null,
                'source' => 'manual',
                'granted_by' => null,
            ],
        );

        AccessDecision::flushCache();
    }

    private function assignmentOrganizationId(User $user, AssignmentScope $scope): ?int
    {
        return match ($scope->type) {
            AssignmentScope::ALL => null,
            'organization' => $scope->id,
            default => $user->organization_id === null ? null : (int) $user->organization_id,
        };
    }

    /**
     * @param  array<string, mixed>  $definitionFlags
     * @return array<int, string>
     */
    private function expandLegacyFlagsToCapabilities(array $definitionFlags): array
    {
        $byAction = fn (array $actions): array => array_values(array_filter(
            Capability::all(),
            function (string $capability) use ($actions) {
                $action = str_contains($capability, '.')
                    ? substr($capability, strrpos($capability, '.') + 1)
                    : $capability;

                return in_array($action, $actions, true);
            }
        ));

        $out = [];
        if (! empty($definitionFlags['can_edit'])) {
            $out = array_merge($out, $byAction(['edit', 'update']));
        }
        if (! empty($definitionFlags['can_delete'])) {
            $out = array_merge($out, $byAction(['delete', 'remove']));
        }
        if (! empty($definitionFlags['can_view_all'])) {
            $out = array_merge($out, $byAction(['view', 'view_all', 'view_reports']));
        }
        if (! empty($definitionFlags['can_manage_members'])) {
            $out = array_merge($out, $byAction(['manage_members', 'assign_roles']));
        }
        if (! empty($definitionFlags['can_view_confidential'])) {
            $out[] = Capability::OVR_CONFIDENTIAL;
        }

        return $out;
    }
}
