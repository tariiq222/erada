<?php

namespace App\Modules\Core\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * DeleteRoleRequest - canonical authorization for deleting a role.
 *
 * authorize() keeps the access decision at the FormRequest seam. Per-role
 * business rules (isSystemRole, users-exist
 * check) stay in the controller — they are state checks, not AuthZ.
 */
class DeleteRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && AccessDecision::can($user, Capability::ROLES_DELETE);
    }

    public function rules(): array
    {
        $roleId = $this->route('roleDefinition')?->getKey();

        return [
            'reassign_to_role_id' => [
                'nullable',
                'integer',
                Rule::exists('authorization_roles', 'id')->where('is_active', true),
                Rule::notIn(array_filter([$roleId])),
            ],
        ];
    }
}
