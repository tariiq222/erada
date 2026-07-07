<?php

namespace App\Modules\RiskManagement\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\RiskManagement\Models\Risk;
use Illuminate\Foundation\Http\FormRequest;

/**
 * DestroyRiskRequest - delete a single Risk.
 *
 * Authorization routes through the unified engine (RISKS_DELETE) on the
 * route-bound Risk. Defense-in-depth: the controller's assertSameOrganization
 * still runs after this returns true.
 */
class DestroyRiskRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }

        $risk = $this->route('risk');
        if (! $risk instanceof Risk) {
            return false;
        }

        return AccessDecision::can($user, Capability::RISKS_DELETE, $risk);
    }

    public function rules(): array
    {
        return [];
    }
}
