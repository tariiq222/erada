<?php

namespace App\Modules\Meetings\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Meetings\Models\MeetingCategory;
use Illuminate\Foundation\Http\FormRequest;

/**
 * UpdateMeetingCategoryRequest - التحقق من بيانات تحديث تصنيف اجتماع.
 *
 * الصلاحية تمرّ عبر محرّك AuthZ الموحّد (Capability::MEETINGS_EDIT).
 * ملاحظة: كان controller يستدعي 'create' بدل 'update' (P1 contract violation) — تم التصحيح هنا.
 */
class UpdateMeetingCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user) {
            return false;
        }

        $category = $this->route('meetingCategory');

        if (! $category instanceof MeetingCategory) {
            $category = MeetingCategory::find($category);
        }

        if (! $category) {
            return false;
        }

        return AccessDecision::can($user, Capability::MEETINGS_EDIT, $category);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ];
    }
}
