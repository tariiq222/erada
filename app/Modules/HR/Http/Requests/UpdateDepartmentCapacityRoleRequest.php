<?php

namespace App\Modules\HR\Http\Requests;

use App\Modules\HR\Models\Department;
use Illuminate\Foundation\Http\FormRequest;

class UpdateDepartmentCapacityRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }

        $department = $this->route('department');
        if (! $department instanceof Department) {
            $department = Department::find($department);
        }
        if (! $department) {
            return false;
        }

        // Capacity-role policy is governed by the same edit capability as the
        // department itself (the manager writes the policy, the sync service
        // reads it). Engine-only via the policy.
        return $this->user()->can('update', $department);
    }

    public function rules(): array
    {
        return [
            'member_role_keys' => ['array'],
            'member_role_keys.*' => ['string', 'exists:scoped_role_definitions,role_key'],
            'manager_role_keys' => ['array'],
            'manager_role_keys.*' => ['string', 'exists:scoped_role_definitions,role_key'],
        ];
    }
}
