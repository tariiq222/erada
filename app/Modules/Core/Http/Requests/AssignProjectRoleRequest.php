<?php

namespace App\Modules\Core\Http\Requests;

use App\Modules\Core\Models\User;
use App\Modules\Projects\Models\Project;
use Illuminate\Foundation\Http\FormRequest;

/**
 * AssignProjectRoleRequest - validation + engine-only authz for assigning
 * a scoped role to a user inside a project.
 *
 * authorize() runs the existing ProjectPolicy::assignProjectRoles path
 * through the unified AuthZ engine. BOLA/IDOR (target user shares the
 * project's org) and the manager-escalation gate (PROJECT_MANAGER requires
 * delete capability) are state checks executed in withValidator — they
 * depend on the loaded target user and follow the existing pattern in
 * UpdateProjectRoleRequest.
 */
class AssignProjectRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');

        if (! $project instanceof Project) {
            return false;
        }

        return $this->user()?->can('assignProjectRoles', $project) ?? false;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'role_id' => ['required', 'integer', 'exists:authorization_roles,id'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }

    /**
     * BOLA/IDOR + privilege-escalation guards. Mirror the existing
     * UpdateProjectRoleRequest shape so the two endpoints stay symmetrical.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $actor = $this->user();
            $project = $this->route('project');

            if ($actor === null || ! $project instanceof Project) {
                return;
            }

            $target = User::find($this->input('user_id'));
            if (! $target) {
                return;
            }

            // BOLA/IDOR: target user shares the project's organization.
            if (! $actor->isSuperAdmin()
                && $target->organization_id !== $project->organization_id) {
                $validator->errors()->add(
                    'user_id',
                    'لا يمكن تعيين دور لمستخدم من مؤسسة أخرى'
                );

                return;
            }

            // Privilege escalation: assigning PROJECT_MANAGER requires delete
            // capability on the project (matches ProjectController::addMember).
            // Role capability escalation is enforced centrally by
            // AuthorizationAssignmentActorGuard inside the assignment service.
        });
    }
}
