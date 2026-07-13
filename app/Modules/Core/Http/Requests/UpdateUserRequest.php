<?php

namespace App\Modules\Core\Http\Requests;

use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
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
        $target = $targetId instanceof User ? $targetId : User::find($targetId);
        $targetOrganizationId = $target?->organization_id;

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'unique:users,email,'.$id],
            'password' => ['nullable', Password::defaults()],
            'department_id' => [
                'nullable',
                Rule::exists(Department::class, 'id')
                    ->where(fn ($query) => $query->where('organization_id', $targetOrganizationId)),
            ],
            'phone' => ['nullable', 'string', 'max:20'],
            'extension' => ['nullable', 'string', 'max:10'],
            'job_title' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
            'roles' => ['array'],
            'roles.*' => ['string'],
        ];
    }

    /**
     * فحص تصعيد الأدوار بعد قواعد التحقق الأساسية.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $roles = $this->input('roles', null);
            if (! is_array($roles)) {
                return;
            }

            $actor = $this->user();
            if ($actor === null) {
                return;
            }

            $targetId = $this->route('user');
            $target = $targetId instanceof User
                ? $targetId
                : User::find($targetId);

            if ($target === null) {
                return;
            }

            $targetHasSuperAdmin = $target->activeCanonicalRoleAssignments()
                ->whereHas('role', fn ($query) => $query->where('name', 'super_admin'))
                ->exists();

            if (in_array('super_admin', $roles, true) && ! $targetHasSuperAdmin) {
                $validator->errors()->add(
                    'roles',
                    'لا يمكن منح دور المدير العام من شاشة تعديل المستخدم.'
                );

                return;
            }

            if (! $actor->can('canAssignRole', [$target, $roles])) {
                $validator->errors()->add(
                    'roles',
                    'لا تملك صلاحية تعيين أحد الأدوار المطلوبة.'
                );
            }
        });
    }
}
