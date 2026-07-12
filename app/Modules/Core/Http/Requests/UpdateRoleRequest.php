<?php

namespace App\Modules\Core\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Authorization\Models\AuthorizationRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * UpdateRoleRequest - validates updating a role DEFINITION.
 *
 * Phase 4b: bound to a scoped_role_definitions row (route param roleDefinition).
 * Uniqueness on rename is checked against active definitions at the same scope,
 * ignoring the current row. Renaming a system (compat-set) role is blocked in
 * the controller. super_admin-only via route middleware.
 */
class UpdateRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && AccessDecision::can($user, Capability::ROLES_EDIT);
    }

    public function rules(): array
    {
        $role = $this->route('roleDefinition');
        $roleId = $role instanceof AuthorizationRole ? $role->id : $role;

        return [
            'name' => [
                'sometimes', 'string', 'max:50',
                Rule::unique('authorization_roles', 'name')->ignore($roleId),
            ],
            'label' => ['nullable', 'string', 'max:100'],
            'scope_type' => ['sometimes', 'string', Rule::in(['all', 'organization', 'department', 'project', 'program', 'portfolio', 'kpi', 'meeting', 'survey'])],
            'label_ar' => ['nullable', 'string', 'max:100'],
            'label_en' => ['nullable', 'string', 'max:100'],
            'capabilities' => ['nullable', 'array'],
            'capabilities.*' => ['string', Rule::in(Capability::all())],
            'is_active' => ['sometimes', 'boolean'],
            'reassign_to_role_id' => ['nullable', 'integer', 'exists:authorization_roles,id'],
            // Per-module reach cap: { module: own|department|all } (Phase 6).
            'reach' => ['nullable', 'array'],
            'reach.*' => ['string', 'in:own,department,all'],
        ];
    }
}
