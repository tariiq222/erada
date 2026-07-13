<?php

namespace App\Modules\Core\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * StoreRoleRequest - validates creation of a role DEFINITION.
 *
 * Phase 4b: scoped_role_definitions is the single source. Uniqueness is checked
 * against ACTIVE definitions at the target scope (a soft-deleted role_key may be
 * re-created — store() reactivates it). Creation is super_admin-only via route
 * middleware; there is no Role Policy (pure system-admin model).
 */
class StoreRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && AccessDecision::can($user, Capability::ROLES_CREATE);
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:50',
                Rule::unique('authorization_roles', 'name'),
            ],
            'label' => ['nullable', 'string', 'max:100'],
            'scope_type' => ['sometimes', 'string', Rule::in(['all', 'organization', 'department', 'project', 'program', 'portfolio', 'kpi', 'meeting', 'survey'])],
            'label_ar' => ['nullable', 'string', 'max:100'],
            'label_en' => ['nullable', 'string', 'max:100'],
            'capabilities' => ['nullable', 'array'],
            'capabilities.*' => ['string', Rule::in(Capability::all())],
            'is_active' => ['sometimes', 'boolean', 'accepted'],
            // Per-module reach cap: { module: own|department|all } (Phase 6).
            'reach' => ['nullable', 'array'],
            'reach.*' => ['string', 'in:own,department,all'],
        ];
    }
}
