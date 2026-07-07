<?php

namespace App\Modules\Core\Http\Requests;

use App\Modules\Core\Models\ScopedRole;
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
            'role' => ['required', 'string', 'in:'.implode(',', array_keys(ScopedRole::getProjectRoles()))],
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
            if ($this->input('role') === ScopedRole::PROJECT_MANAGER
                && ! $actor->can('delete', $project)) {
                $validator->errors()->add(
                    'role',
                    'لا يمكن ترقية مستخدم إلى مدير مشروع بدون صلاحية الحذف على المشروع.'
                );
            }
        });
    }
}
