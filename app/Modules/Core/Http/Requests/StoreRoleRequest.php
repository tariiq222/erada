<?php

namespace App\Modules\Core\Http\Requests;

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
        return $this->user()?->isSuperAdmin() ?? false;
    }

    public function rules(): array
    {
        $scopeKey = $this->input('scope_type', 'organization');

        return [
            'name' => [
                'required', 'string', 'max:50',
                Rule::unique('scoped_role_definitions', 'role_key')
                    ->where(fn ($q) => $q->where('scope_type', $scopeKey)->where('is_active', true)),
            ],
            'scope_type' => ['sometimes', 'string', 'exists:scope_types,key'],
            'permissions' => ['array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
            'label_ar' => ['nullable', 'string', 'max:100'],
            'label_en' => ['nullable', 'string', 'max:100'],
            'permissions_capabilities' => ['nullable', 'array'],
            'permissions_capabilities.*' => ['string', 'max:100'],
            // Per-module reach cap: { module: own|department|all } (Phase 6).
            'reach' => ['nullable', 'array'],
            'reach.*' => ['string', 'in:own,department,all'],
        ];
    }
}
