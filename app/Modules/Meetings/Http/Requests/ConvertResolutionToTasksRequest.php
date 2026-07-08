<?php

namespace App\Modules\Meetings\Http\Requests;

use App\Modules\Core\Http\Requests\Concerns\ScopesUsersToOrganization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Phase 3 / Direction R — Convert a meeting resolution into one or more real
 * tasks on the `tasks` table.
 *
 * Each task inherits:
 *   - source_type = 'MeetingResolution' (short basename, matches
 *     Task::SOURCE_CLASS_MAP so scope walking works)
 *   - source_id = the resolution id
 *   - organization_id = the resolution's organization
 *   - assignee_id = the user passed in this payload
 *
 * Optional:
 *   - project_id — when omitted, falls back to a `linkable_type=project`
 *     resolution_link pivot so the resolution's project-link auto-attaches
 *     the spawned tasks.
 *   - risk_id is **not** accepted: the tasks table has no `risk_id` column.
 *     Risk linking is planned for Phase 4 via a separate task row whose
 *     source_type='Risk'. See Phase 3 report §11 for the known limitation.
 */
class ConvertResolutionToTasksRequest extends FormRequest
{
    use ScopesUsersToOrganization;

    public function authorize(): bool
    {
        return $this->user()?->can('convertToTasks', $this->route('resolution')) ?? false;
    }

    public function rules(): array
    {
        return [
            'tasks' => ['required', 'array', 'min:1', 'max:20'],
            'tasks.*.title' => ['required', 'string', 'max:255'],
            'tasks.*.description' => ['nullable', 'string', 'max:5000'],
            'tasks.*.assignee_id' => ['required', 'integer', $this->orgScopedUserRule()],
            'tasks.*.due_date' => ['nullable', 'date', 'after_or_equal:today'],
            'tasks.*.priority' => ['nullable', Rule::in(['low', 'medium', 'high', 'critical'])],
            'tasks.*.project_id' => [
                'nullable',
                'integer',
                Rule::exists('projects', 'id')->when(
                    ! $this->user() || ! $this->user()->isSuperAdmin(),
                    fn ($rule) => $rule->where('organization_id', $this->user()?->organization_id)
                ),
            ],
            // Risk linking is not yet supported on the tasks table; reject
            // any risk_id explicitly so the SPA stops sending it.
            'tasks.*.risk_id' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'tasks.required' => 'يجب إدخال مهمة واحدة على الأقل.',
            'tasks.*.title.required' => 'عنوان المهمة مطلوب.',
            'tasks.*.assignee_id.required' => 'يجب تحديد مسؤول المهمة.',
            'tasks.*.project_id.exists' => 'المشروع المحدد غير موجود.',
            'tasks.*.risk_id.prohibited' => 'ربط المهام بالخطر غير مدعوم حاليًا.',
        ];
    }
}
