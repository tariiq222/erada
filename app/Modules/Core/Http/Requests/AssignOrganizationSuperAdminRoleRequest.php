<?php

namespace App\Modules\Core\Http\Requests;

use App\Modules\Core\Authorization\Data\AssignmentScope;
use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Authorization\Models\AuthorizationRoleAssignment;
use App\Modules\Core\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * CSD-CA23078-CORE-009 (OrgSuper rewrite).
 *
 * Auditable seam for POST /api/org-super/role-assignments. The public gate
 * is `engine_capability:roles.assign` on the route — this FormRequest is
 * the defense-in-depth layer that catches client-side payload manipulation
 * BEFORE the actor guard runs.
 */
final class AssignOrganizationSuperAdminRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        // engine_capability:roles.assign on the route is the public gate.
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'replace_all' => ['required', 'accepted'],
            'assignments' => ['present', 'array'],
            'assignments.*.role_id' => ['required', 'integer', 'exists:authorization_roles,id'],
            'assignments.*.scope_type' => ['required', 'string', Rule::in([AssignmentScope::ORGANIZATION])],
            'assignments.*.scope_id' => ['required', 'integer', 'min:1'],
            'assignments.*.inherit_to_children' => ['required', 'declined'], // false only — rejects `true`
            'assignments.*.expires_at' => ['prohibited'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $actor = $this->user();
                if ($actor === null || ! $actor->isOrganizationSuperAdmin() || $actor->isSuperAdmin()) {
                    return;
                }

                $actorOrgId = $actor->organization_id !== null ? (int) $actor->organization_id : null;
                $forbiddenNames = ['super_admin', 'organization_super_admin', 'admin'];

                foreach ($this->input('assignments', []) as $index => $assignment) {
                    if (! is_array($assignment)) {
                        continue;
                    }
                    $roleId = $assignment['role_id'] ?? null;
                    if (! is_numeric($roleId)) {
                        continue;
                    }
                    $role = AuthorizationRole::query()->find((int) $roleId);
                    if ($role === null) {
                        continue;
                    }

                    if (! (bool) $role->is_active) {
                        $validator->errors()->add(
                            "assignments.{$index}.role_id",
                            "الدور [{$role->name}] غير نشط."
                        );

                        continue;
                    }
                    if ((bool) $role->is_admin_role || (bool) $role->is_system) {
                        $validator->errors()->add(
                            "assignments.{$index}.role_id",
                            "الدور [{$role->name}] محصور بإدارة النظام ولا يمكن إسناده من قِبل Organization Super Admin."
                        );

                        continue;
                    }
                    if (in_array($role->name, $forbiddenNames, true)) {
                        $validator->errors()->add(
                            "assignments.{$index}.role_id",
                            "الدور [{$role->name}] محصور بالمسؤول العام للنظام فقط ولا يمكن إسناده من قِبل Organization Super Admin."
                        );

                        continue;
                    }

                    $scopeType = $assignment['scope_type'] ?? null;
                    $scopeId = $assignment['scope_id'] ?? null;
                    if ($scopeType !== AssignmentScope::ORGANIZATION || $scopeId === null || (int) $scopeId !== $actorOrgId) {
                        $validator->errors()->add(
                            "assignments.{$index}.scope_id",
                            'يجب أن يكون النطاق organization ومعرّفه مساوياً لمؤسسة الفاعل.'
                        );

                        continue;
                    }

                    $subjectId = $this->input('user_id');
                    if (is_numeric($subjectId)) {
                        $subject = User::query()->find((int) $subjectId);
                        if ($subject !== null) {
                            if ((int) $subject->organization_id !== $actorOrgId) {
                                $validator->errors()->add('user_id', 'الموضوع خارج مؤسسة الفاعل.');
                            }
                            $isProtected = AuthorizationRoleAssignment::query()
                                ->where('user_id', $subject->id)
                                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                                ->whereHas('role', fn ($roleQuery) => $roleQuery
                                    ->whereIn('name', ['super_admin', 'organization_super_admin'])
                                    ->where('is_active', true))
                                ->exists();
                            if ($isProtected) {
                                $validator->errors()->add('user_id', 'لا يمكن تعديل مستخدم يحمل دور super_admin أو organization_super_admin.');
                            }
                        }
                    }
                }
            },
        ];
    }
}
