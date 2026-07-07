<?php

namespace App\Modules\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * DestroyOrganizationRequest - صلاحية حذف مؤسسة
 *
 * صلاحية الحذف محصورة بـ super_admin عبر middleware على المسار.
 */
class DestroyOrganizationRequest extends FormRequest
{
    /**
     * صلاحية الحذف محصورة بـ super_admin على مستوى المسار.
     */
    public function authorize(): bool
    {
        return $this->user()?->isSuperAdmin() ?? false;
    }

    /**
     * لا حاجة لقواعد تحقق على الحذف.
     */
    public function rules(): array
    {
        return [];
    }
}
