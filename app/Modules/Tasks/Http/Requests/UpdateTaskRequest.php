<?php

namespace App\Modules\Tasks\Http\Requests;

use App\Modules\Core\Http\Requests\Concerns\ScopesUsersToOrganization;
use App\Modules\Tasks\Enums\TaskPriority;
use App\Modules\Tasks\Enums\TaskStatus;
use App\Modules\Tasks\Enums\TaskType;
use App\Modules\Tasks\Models\Task;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * UpdateTaskRequest - التحقق من تحديث مهمة
 *
 * الصلاحية عبر TaskPolicy::update (engine-only) — المهام الشخصية تُحكَم
 * بـ owner_id داخل Policy، باقي المهام تمر عبر AccessDecision::can().
 */
class UpdateTaskRequest extends FormRequest
{
    use ScopesUsersToOrganization;

    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        // المسار يستخدم {task} route model binding
        $task = $this->route('task');

        if (! $task instanceof Task) {
            $task = Task::find($task);
        }

        if (! $task) {
            return false;
        }

        return $user->can('update', $task);
    }

    public function rules(): array
    {
        return [
            'type' => ['sometimes', Rule::in(TaskType::values())],
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['sometimes', Rule::in(TaskStatus::values())],
            'priority' => ['sometimes', Rule::in(TaskPriority::values())],
            'progress' => ['sometimes', 'integer', 'min:0', 'max:100'],

            'start_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:start_date'],

            'estimated_hours' => ['nullable', 'integer', 'min:0'],
            'actual_hours' => ['nullable', 'integer', 'min:0'],

            'project_id' => ['sometimes', 'nullable', 'exists:projects,id'],
            'department_id' => ['sometimes', 'nullable', 'exists:departments,id'],
            'milestone_id' => ['sometimes', 'nullable', 'exists:milestones,id'],
            'parent_id' => ['sometimes', 'nullable', 'exists:tasks,id'],
            'assigned_to' => ['sometimes', 'nullable', $this->orgScopedUserRule()],
            'owner_id' => ['sometimes', 'nullable', $this->orgScopedUserRule()],

            'is_private' => ['sometimes', 'boolean'],
            'recurrence_rule' => ['nullable', 'string', 'max:100'],
            'order' => ['sometimes', 'integer', 'min:0'],

            // حقول الإكمال
            'challenges' => ['nullable', 'string', 'max:5000'],
            'lessons_learned' => ['nullable', 'string', 'max:5000'],
            'status_comment' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'title.max' => 'عنوان المهمة يجب ألا يتجاوز 255 حرف',
            'project_id.exists' => 'المشروع المحدد غير موجود',
            'assigned_to.exists' => 'المستخدم المحدد غير موجود',
            'progress.min' => 'نسبة الإنجاز يجب أن تكون 0 على الأقل',
            'progress.max' => 'نسبة الإنجاز يجب ألا تتجاوز 100',
        ];
    }
}
