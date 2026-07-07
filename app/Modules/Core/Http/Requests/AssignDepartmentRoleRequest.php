<?php

namespace App\Modules\Core\Http\Requests;

use App\Modules\Core\Models\ScopedRole;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use Illuminate\Foundation\Http\FormRequest;

/**
 * AssignDepartmentRoleRequest - validation + authz for assigning a scoped
 * department role to a user.
 *
 * authorize() mirrors the controller's department-access check
 * (super_admin OR canAccessDepartment), since department role management
 * is not yet wired into the unified engine's Capability map. BOLA/IDOR
 * (target user shares the department's org) is a state check in
 * withValidator.
 */
class AssignDepartmentRoleRequest extends FormRequest
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
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'role' => ['required', 'string', 'in:'.implode(',', array_keys(ScopedRole::getDepartmentRoles()))],
            'inherit_to_children' => ['boolean'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }

    /**
     * BOLA/IDOR guard for the target user against the department's org.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $actor = $this->user();
            $department = $this->route('department');

            if ($actor === null || ! $department instanceof Department) {
                return;
            }

            $target = User::find($this->input('user_id'));
            if (! $target) {
                return;
            }

            if (! $actor->isSuperAdmin()
                && $department->organization_id !== null
                && $target->organization_id !== $department->organization_id) {
                $validator->errors()->add(
                    'user_id',
                    'لا يمكن تعيين دور لمستخدم من مؤسسة أخرى'
                );
            }
        });
    }
}
