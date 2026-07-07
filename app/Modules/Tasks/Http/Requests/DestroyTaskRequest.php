<?php

namespace App\Modules\Tasks\Http\Requests;

use App\Modules\Tasks\Models\Task;
use Illuminate\Foundation\Http\FormRequest;

/**
 * DestroyTaskRequest - delete a single Task.
 *
 * Authorization delegates to TaskPolicy::delete via the Gate so personal-task
 * owner logic (owner_id floor) and the engine path are handled uniformly.
 * The controller also calls authorizeTask($task, 'delete') as a second gate.
 */
class DestroyTaskRequest extends FormRequest
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

        return $user->can('delete', $task);
    }

    public function rules(): array
    {
        return [];
    }
}
