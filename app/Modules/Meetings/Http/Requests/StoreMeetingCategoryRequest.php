<?php

namespace App\Modules\Meetings\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use Illuminate\Foundation\Http\FormRequest;

/**
 * StoreMeetingCategoryRequest - التحقق من بيانات إنشاء تصنيف اجتماع.
 *
 * الصلاحية تمرّ عبر محرّك AuthZ الموحّد (Capability::MEETINGS_CREATE).
 */
class StoreMeetingCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user ? AccessDecision::can($user, Capability::MEETINGS_CREATE) : false;
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
