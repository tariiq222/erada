<?php

namespace App\Modules\OVR\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\HR\Models\Department;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * UpdateGoverningDepartmentRequest - update (or clear) the governing department
 * for the OVR module. Admin-gated: engine-first (SETTINGS_MANAGE), and the
 * chosen department MUST belong to the editor's organization (super_admin
 * bypasses both).
 */
class UpdateGoverningDepartmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }

        return AccessDecision::can($user, Capability::SETTINGS_MANAGE);
    }

    public function rules(): array
    {
        return [
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->has('department_id')) {
                return;
            }

            $user = $this->user();
            $departmentId = $this->input('department_id');

            if ($departmentId === null || $departmentId === '') {
                return;
            }

            if ($user->isSuperAdmin()) {
                return;
            }

            $belongs = Department::query()
                ->forOrganization($user->organization_id)
                ->whereKey($departmentId)
                ->exists();

            if (! $belongs) {
                $validator->errors()->add(
                    'department_id',
                    'القسم المحدد لا ينتمي لمؤسستك'
                );
            }
        });
    }
}
