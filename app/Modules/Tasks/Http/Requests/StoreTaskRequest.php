<?php

namespace App\Modules\Tasks\Http\Requests;

use App\Modules\Core\Http\Requests\Concerns\ScopesUsersToOrganization;
use App\Modules\Tasks\Enums\TaskPriority;
use App\Modules\Tasks\Enums\TaskStatus;
use App\Modules\Tasks\Enums\TaskType;
use App\Modules\Tasks\Models\Task;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTaskRequest extends FormRequest
{
    use ScopesUsersToOrganization;

    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        // A personal task belongs to its creator — any authenticated user may
        // create one. Project/department tasks go through the unified engine
        // (TaskPolicy::create → AccessDecision).
        if (($this->input('type') ?? 'project') === 'personal') {
            return true;
        }

        return $user->can('create', Task::class);
    }

    public function rules(): array
    {
        return [
            'type' => ['sometimes', Rule::in(TaskType::values())],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['sometimes', Rule::in(TaskStatus::values())],
            'priority' => ['sometimes', Rule::in(TaskPriority::values())],
            'progress' => ['sometimes', 'integer', 'min:0', 'max:100'],

            'start_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:start_date'],

            'estimated_hours' => ['nullable', 'integer', 'min:0'],

            'project_id' => [
                'nullable',
                'exists:projects,id',
                Rule::requiredIf(fn () => $this->input('type') === 'project'),
            ],
            'department_id' => [
                'nullable',
                'exists:departments,id',
            ],
            'milestone_id' => ['nullable', 'exists:milestones,id'],
            'parent_id' => ['nullable', 'exists:tasks,id'],
            'assigned_to' => ['nullable', $this->orgScopedUserRule()],
            'owner_id' => ['nullable', $this->orgScopedUserRule()],

            'is_private' => ['sometimes', 'boolean'],
            'recurrence_rule' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => 'عنوان المهمة مطلوب',
            'title.max' => 'عنوان المهمة يجب ألا يتجاوز 255 حرف',
            'due_date.after_or_equal' => 'تاريخ الاستحقاق يجب أن يكون بعد أو يساوي تاريخ البداية',
            'project_id.required' => 'المشروع مطلوب لمهام المشاريع',
            'project_id.exists' => 'المشروع المحدد غير موجود',
            'assigned_to.exists' => 'المستخدم المحدد غير موجود',
            'progress.min' => 'نسبة الإنجاز يجب أن تكون 0 على الأقل',
            'progress.max' => 'نسبة الإنجاز يجب ألا تتجاوز 100',
        ];
    }

    protected function prepareForValidation(): void
    {
        // تعيين نوع افتراضي
        if (! $this->has('type')) {
            if ($this->has('project_id')) {
                $this->merge(['type' => 'project']);
            } else {
                $this->merge(['type' => 'personal']);
            }
        }
    }
}
