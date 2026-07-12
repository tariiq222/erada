<?php

namespace App\Modules\Core\Http\Requests;

use App\Modules\Core\Authorization\Data\AssignmentScope;
use App\Modules\Core\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * UpdateUserRequest - التحقق من بيانات تحديث مستخدم
 *
 * صلاحية التحديث تمر عبر UserPolicy::update (المحرّك الموحّد).
 */
class UpdateUserRequest extends FormRequest
{
    /**
     * صلاحية التحديث عبر UserPolicy::update ⇒ AccessDecision::can(USERS_EDIT).
     * يتطلب تحميل النموذج الهدف قبل فحص السياسة.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }

        $targetId = $this->route('user');
        $target = $targetId instanceof User
            ? $targetId
            : User::find($targetId);

        if ($target === null) {
            return false;
        }

        return $user->can('update', $target);
    }

    /**
     * قواعد التحقق
     */
    public function rules(): array
    {
        $targetId = $this->route('user');
        $id = $targetId instanceof User ? $targetId->id : $targetId;

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'unique:users,email,'.$id],
            'password' => ['nullable', Password::defaults()],
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

    /**
     * فحص تصعيد الأدوار بعد قواعد التحقق الأساسية.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
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
        });
    }
}
