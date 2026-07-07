<?php

namespace App\Modules\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * StoreOrganizationRequest - التحقق من بيانات إنشاء مؤسسة
 *
 * صلاحية الإنشاء محصورة بـ super_admin عبر middleware على المسار (route group).
 * لا توجد سياسة Policy لـ Organization (نموذج إدارة نظامي خالص).
 */
class StoreOrganizationRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', 'unique:organizations,code'],
            'description' => ['nullable', 'string'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:500'],
            'website' => ['nullable', 'url', 'max:255'],
            'settings' => ['nullable', 'array'],
            'is_active' => ['boolean'],
        ];
    }
}
