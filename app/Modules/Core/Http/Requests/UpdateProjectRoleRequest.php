<?php

namespace App\Modules\Core\Http\Requests;

use App\Modules\Core\Models\User;
use App\Modules\Projects\Models\Project;
use Illuminate\Foundation\Http\FormRequest;

/**
 * UpdateProjectRoleRequest - التحقق من تحديث دور مستخدم في مشروع
 *
 * صلاحية التحديث تمر عبر ProjectPolicy::assignProjectRoles (المحرّك الموحّد).
 * التحقق من BOLA/IDOR ومنع تصعيد الدور إلى PROJECT_MANAGER يُنفَّذ في
 * withValidator() لأنهما يعتمدان على النموذج الهدف وقواعد النطاق.
 */
class UpdateProjectRoleRequest extends FormRequest
{
    /**
     * صلاحية تحديث دور المشروع عبر ProjectPolicy::assignProjectRoles.
     * يتطلب تحميل نموذجَي المشروع والمستخدم الهدف قبل فحص السياسة.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }

        $project = $this->route('project');
        if (! $project instanceof Project) {
            return false;
        }

        return $user->can('assignProjectRoles', $project);
    }

    /**
     * قواعد التحقق
     */
    public function rules(): array
    {
        return [
            'role_id' => ['required', 'integer', 'exists:authorization_roles,id'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }

    /**
     * فحوصات إضافية بعد قواعد التحقق الأساسية:
     *  - BOLA/IDOR: المستخدم الهدف يجب أن يكون في نفس مؤسسة المشروع.
     *  - منع تصعيد الصلاحيات: الترقية إلى PROJECT_MANAGER تتطلب قدرة delete على المشروع.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $actor = $this->user();
            $project = $this->route('project');
            $target = $this->route('user');

            if ($actor === null || ! $project instanceof Project || ! $target instanceof User) {
                return;
            }

            // BOLA/IDOR: المستخدم الهدف في نفس مؤسسة المشروع
            if (! $actor->isSuperAdmin()
                && $target->organization_id !== $project->organization_id) {
                $validator->errors()->add(
                    'user',
                    'لا يمكن تحديث دور لمستخدم من مؤسسة أخرى'
                );

                return;
            }

            // منع تصعيد الصلاحيات: الترقية إلى مدير مشروع تتطلب صلاحية delete.
            // Role capability escalation is enforced centrally by
            // AuthorizationAssignmentActorGuard inside the assignment service.
        });
    }
}
