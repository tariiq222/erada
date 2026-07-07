<?php

namespace App\Modules\Tasks\Http\Requests;

use App\Modules\Tasks\Models\Task;
use Illuminate\Foundation\Http\FormRequest;

/**
 * AssignTaskRequest - assign a Task to a user.
 *
 * Authorization delegates to TaskPolicy::assign (Capability::TASKS_ASSIGN) via
 * the Gate so personal-task owner logic and the engine path are handled
 * uniformly. Phase 2 of master AuthZ unification plan: previously this gate
 * used Capability::TASKS_EDIT, which made the assign-only user silently fail.
 * Defense-in-depth: the D-04 IDOR floor on assigned_to's organization_id
 * still runs in the controller after this returns true.
 */
class AssignTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }

        $task = $this->route('task');
        if (! $task instanceof Task) {
            return false;
        }

        return $user->can('assign', $task);
    }

    public function rules(): array
    {
        return [
            'assigned_to' => ['required', 'integer', 'exists:users,id'],
        ];
    }
}
