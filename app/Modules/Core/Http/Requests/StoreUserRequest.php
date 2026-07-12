<?php

namespace App\Modules\Core\Http\Requests;

use App\Modules\Core\Authorization\Data\AssignmentScope;
use App\Modules\Core\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

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
            'roles' => ['prohibited'],
            'assignments' => ['sometimes', 'array'],
            'assignments.*.role_id' => ['required', 'integer', 'exists:authorization_roles,id'],
            'assignments.*.scope_type' => ['required', 'string', Rule::in(AssignmentScope::TYPES)],
            'assignments.*.scope_id' => ['nullable', 'integer', 'min:1'],
            'assignments.*.inherit_to_children' => ['sometimes', 'boolean'],
            'assignments.*.expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }

    public function after(): array
    {
        return [$this->validateAssignmentIdentities(...)];
    }

    private function validateAssignmentIdentities(Validator $validator): void
    {
        $identities = [];

        foreach ($this->input('assignments', []) as $index => $assignment) {
            if (! is_array($assignment)) {
                continue;
            }

            $scopeType = $assignment['scope_type'] ?? null;
            $scopeId = $assignment['scope_id'] ?? null;
            $allowsNullScope = in_array($scopeType, ['all', 'own'], true);

            if ($allowsNullScope && $scopeId !== null) {
                $validator->errors()->add("assignments.{$index}.scope_id", 'هذا النطاق لا يقبل معرّف نطاق.');
            } elseif (is_string($scopeType) && ! $allowsNullScope && $scopeId === null) {
                $validator->errors()->add("assignments.{$index}.scope_id", 'معرّف النطاق مطلوب لهذا النوع.');
            }

            $roleId = $assignment['role_id'] ?? null;
            if (! is_numeric($roleId) || ! is_string($scopeType)) {
                continue;
            }

            $identity = implode(':', [(int) $roleId, $scopeType, $scopeId ?? 'null']);
            if (isset($identities[$identity])) {
                $validator->errors()->add("assignments.{$index}", 'لا يمكن تكرار الدور والنطاق نفسيهما في الطلب.');
            }
            $identities[$identity] = true;
        }
    }
}
