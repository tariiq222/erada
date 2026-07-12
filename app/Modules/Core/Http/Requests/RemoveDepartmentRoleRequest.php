<?php

namespace App\Modules\Core\Http\Requests;

use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use Illuminate\Foundation\Http\FormRequest;

/**
 * RemoveDepartmentRoleRequest - validation + authz for revoking a
 * department-scoped role from a user.
 *
 * authorize() mirrors the controller's department-access check (matches
 * AssignDepartmentRoleRequest). The "row exists" check is a state error
 * (404) and lives in withValidator so it surfaces as a proper 404.
 */
class RemoveDepartmentRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        $department = $this->route('department');

        if (! $department instanceof Department) {
            return false;
        }

        return $user->canAccessDepartment($department);
    }

    public function rules(): array
    {
        return [
            'role_id' => ['required', 'integer', 'exists:authorization_roles,id'],
        ];
    }
}
