<?php

namespace App\Modules\RiskManagement\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\RiskManagement\Models\RiskAction;
use Illuminate\Foundation\Http\FormRequest;

/**
 * DestroyRiskActionRequest - delete a single RiskAction.
 *
 * Authorization routes through the unified engine (RISKS_DELETE) on the
 * route-bound RiskAction. Defense-in-depth: the controller's
 * assertSameOrganization still runs after this returns true.
 */
class DestroyRiskActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }

        $action = $this->route('action');
        if (! $action instanceof RiskAction) {
            return false;
        }

        return AccessDecision::can($user, Capability::RISKS_DELETE, $action);
    }

    public function rules(): array
    {
        return [];
    }
}
