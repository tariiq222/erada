<?php

namespace App\Modules\RiskManagement\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\HR\Models\Department;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Form request for updating the governing department of risks. Engine-only
 * authz via AccessDecision::can + Capability::SETTINGS_MANAGE.
 *
 * Re-validates the chosen department belongs to the user's organization
 * (super_admin bypassed) so the org-isolation rule from the controller is
 * preserved in the FormRequest.
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

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
        ];
    }

    /**
     * Fail-closed org-isolation check. The controller previously enforced this;
     * we move it into withValidator() so the rejection flows through the
     * standard 422 validation path.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $user = $this->user();
            if ($user === null || $user->isSuperAdmin()) {
                return;
            }

            $departmentId = $this->input('department_id');
            if ($departmentId === null || $departmentId === '') {
                return;
            }

            $belongs = Department::query()
                ->forOrganization($user->organization_id)
                ->whereKey((int) $departmentId)
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
