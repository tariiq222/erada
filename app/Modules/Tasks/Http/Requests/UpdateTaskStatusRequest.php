<?php

namespace App\Modules\Tasks\Http\Requests;

use App\Modules\Tasks\Enums\TaskStatus;
use App\Modules\Tasks\Models\Task;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * UpdateTaskStatusRequest - التحقق من تحديث حالة المهمة فقط
 *
 * يستخرج قواعد التحقق وصلاحية الوصول من TaskController::updateStatus
 * ويُوحِّد منطق الصلاحيات في FormRequest بدلاً من controller.
 *
 * الصلاحية عبر TaskPolicy — مسار engine-only:
 *  - completeTask → Capability::TASKS_COMPLETE (قيادية)
 *  - changeStatus → Capability::TASKS_EDIT
 * المهام الشخصية (type=personal) محكومة بطبقة owner_id داخل Policy.
 */
class UpdateTaskStatusRequest extends FormRequest
{
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

        $newStatus = $this->input('status');

        // الحالات الخاصة: completed تتطلب صلاحية completeTask (قيادية)؛
        // الباقي يستخدم changeStatus (editor). المهام الشخصية لا تملك
        // مفهوم الإكمال القيادي — TaskPolicy::completeTask يرفضها صراحة.
        $ability = $newStatus === TaskStatus::COMPLETED->value
            ? 'completeTask'
            : 'changeStatus';

        return $user->can($ability, $task);
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(TaskStatus::values())],
            'status_comment' => ['nullable', 'string', 'max:2000'],
            'lessons_learned' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return [
            'status.required' => 'حالة المهمة مطلوبة',
            'status.in' => 'حالة المهمة غير صالحة',
            'status_comment.max' => 'تعليق الحالة يجب ألا يتجاوز 2000 حرف',
            'lessons_learned.max' => 'الدروس المستفادة يجب ألا تتجاوز 5000 حرف',
        ];
    }
}
