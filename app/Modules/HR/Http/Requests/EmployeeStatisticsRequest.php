<?php

namespace App\Modules\HR\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use Illuminate\Foundation\Http\FormRequest;

/**
 * EmployeeStatisticsRequest - engine-only authz for the HR statistics
 * aggregate. Same gate as ListEmployeesRequest (HR_VIEW); kept as a
 * distinct class so future divergence (per-stats permissions, payload
 * validation) has a place to land without churning ListEmployeesRequest.
 */
class EmployeeStatisticsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        if (! AccessDecision::can($user, Capability::HR_VIEW)) {
            return false;
        }

        if (! $user->isSuperAdmin() && $user->organization_id === null) {
            return false;
        }

        return true;
    }

    public function rules(): array
    {
        return [];
    }
}
