<?php

namespace App\Modules\Core\Http\Requests;

use App\Modules\Core\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * UpdateOrganizationRequest - التحقق من بيانات تحديث مؤسسة
 *
 * صلاحية التحديث محصورة بـ super_admin عبر middleware على المسار.
 */
class UpdateOrganizationRequest extends FormRequest
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
        $organization = $this->route('organization');
        $organizationId = $organization instanceof Organization ? $organization->id : $organization;

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => ['sometimes', 'required', 'string', 'max:50', Rule::unique('organizations', 'code')->ignore($organizationId)],
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
