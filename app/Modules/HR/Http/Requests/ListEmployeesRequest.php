<?php

namespace App\Modules\HR\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use Illuminate\Foundation\Http\FormRequest;

/**
 * ListEmployeesRequest - engine-only authz for paginated employees list.
 *
 * authorize() mirrors the controller's authorizeHr(Capability::HR_VIEW)
 * gate: engine-only, deny-not-bypass on org membership for non-super_admin.
 * No payload rules — list endpoints take only filter query params that the
 * controller interprets defensively.
 */
class ListEmployeesRequest extends FormRequest
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
