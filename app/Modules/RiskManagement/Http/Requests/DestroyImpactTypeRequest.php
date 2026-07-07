<?php

namespace App\Modules\RiskManagement\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Form request for deleting a RiskImpactType. Engine-only authz via
 * AccessDecision::can + Capability::RISKS_EDIT.
 */
class DestroyImpactTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        return AccessDecision::can($user, Capability::RISKS_EDIT);
    }

    public function rules(): array
    {
        return [];
    }
}
