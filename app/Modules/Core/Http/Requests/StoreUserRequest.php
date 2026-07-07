<?php

namespace App\Modules\Core\Http\Requests;

use App\Modules\Core\Models\User;
use App\Modules\Core\Rules\AssignableRoleKey;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * StoreUserRequest - التحقق من بيانات إنشاء مستخدم جديد
 *
 * صلاحية الإنشاء تمر عبر UserPolicy::create (المحرّك الموحّد).
 */
class StoreUserRequest extends FormRequest
{
    /**
     * Authorization: creation gate (USERS_CREATE) + role-escalation guard.
     *
     * Role escalation is an authorization concern, not a validation concern:
     * attempting to assign super_admin when you are not one is privilege
     * escalation (403), not a malformed request (422). Both checks run here
     * so that a single false → 403 response covers either failure.
     *
     * RoleHierarchy::canAssignAll handles the full matrix:
     *   - super_admin: may assign any role
     *   - admin: may assign any role EXCEPT super_admin
     *   - everyone else: only roles strictly below their own level
     */
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        if (! $user->can('create', User::class)) {
            return false;
        }

        // Role-escalation guard: checked here (403) rather than withValidator
        // (422) because assigning an unauthorized role is an authz violation,
        // not a malformed payload. Only runs when roles are actually submitted.
        $roles = $this->input('roles', []);
        if (is_array($roles) && $roles !== []) {
            if (! $user->can('canAssignRole', [new User, $roles])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validation rules.
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', Password::defaults()],
            'organization_id' => ['nullable', 'exists:organizations,id'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'phone' => ['nullable', 'string', 'max:20'],
            'extension' => ['nullable', 'string', 'max:10'],
            'job_title' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
            'roles' => ['array'],
            'roles.*' => ['string', new AssignableRoleKey],
        ];
    }
}
