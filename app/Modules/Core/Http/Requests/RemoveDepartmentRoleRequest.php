<?php

namespace App\Modules\Core\Http\Requests;

use App\Modules\Core\Models\ScopedRole;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use Illuminate\Foundation\Http\FormRequest;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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
            'role' => ['required', 'string'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $user = $this->route('user');
            $department = $this->route('department');
            $roleName = $this->input('role');

            if (! $user instanceof User || ! $department instanceof Department || ! is_string($roleName)) {
                return;
            }

            $exists = $user->scopedRoles()
                ->where('scope_type', ScopedRole::SCOPE_DEPARTMENT)
                ->where('scope_id', $department->id)
                ->where('role', $roleName)
                ->exists();

            if (! $exists) {
                throw new NotFoundHttpException('المستخدم ليس لديه هذا الدور في هذا القسم');
            }
        });
    }
}
