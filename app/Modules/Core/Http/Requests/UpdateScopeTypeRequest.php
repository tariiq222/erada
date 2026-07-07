<?php

namespace App\Modules\Core\Http\Requests;

use App\Modules\Core\Models\ScopeType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * UpdateScopeTypeRequest - التحقق من بيانات تحديث نوع نطاق
 *
 * صلاحية التحديث محصورة بـ super_admin عبر middleware على المسار.
 */
class UpdateScopeTypeRequest extends FormRequest
{
    /**
     * صلاحية التحديث محصورة بـ super_admin على مستوى المسار.
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
        $scopeType = $this->route('scopeType');
        $scopeTypeId = $scopeType instanceof ScopeType ? $scopeType->id : $scopeType;

        return [
            'key' => ['sometimes', 'required', 'string', 'max:100', Rule::unique('scope_types', 'key')->ignore($scopeTypeId)],
            'label_ar' => ['sometimes', 'required', 'string', 'max:255'],
            'label_en' => ['sometimes', 'required', 'string', 'max:255'],
            'model_class' => ['sometimes', 'required', 'string', 'max:255'],
            'icon' => ['nullable', 'string', 'max:100'],
            'color' => ['nullable', 'string', 'max:20'],
            'description_ar' => ['nullable', 'string'],
            'description_en' => ['nullable', 'string'],
            'sort_order' => ['integer', 'min:0'],
            'is_active' => ['boolean'],
        ];
    }
}
