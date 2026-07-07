<?php

namespace App\Modules\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * StoreScopeTypeRequest - التحقق من بيانات إنشاء نوع نطاق
 *
 * صلاحية الإنشاء محصورة بـ super_admin عبر middleware على المسار (route group).
 * لا توجد سياسة Policy لـ ScopeType (نموذج إدارة نظامي خالص).
 */
class StoreScopeTypeRequest extends FormRequest
{
    /**
     * صلاحية الإنشاء محصورة بـ super_admin على مستوى المسار.
     */
    public function authorize(): bool
    {
        return $this->user()?->isSuperAdmin() ?? false;
    }

    /**
     * قواعد التحقق
     */
    public function rules(): array
    {
        return [
            'key' => ['required', 'string', 'max:100', 'unique:scope_types,key'],
            'label_ar' => ['required', 'string', 'max:255'],
            'label_en' => ['required', 'string', 'max:255'],
            'model_class' => ['required', 'string', 'max:255'],
            'icon' => ['nullable', 'string', 'max:100'],
            'color' => ['nullable', 'string', 'max:20'],
            'description_ar' => ['nullable', 'string'],
            'description_en' => ['nullable', 'string'],
            'sort_order' => ['integer', 'min:0'],
            'is_active' => ['boolean'],
        ];
    }
}
