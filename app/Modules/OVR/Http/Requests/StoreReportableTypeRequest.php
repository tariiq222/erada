<?php

namespace App\Modules\OVR\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use Illuminate\Foundation\Http\FormRequest;

/**
 * StoreReportableTypeRequest - create a reportable sub-type under an incident
 * type (category). Mirrors StoreIncidentTypeRequest's authz: OVR_MANAGE_TYPES
 * routed through the unified engine (not the legacy flat Spatie lookup).
 */
class StoreReportableTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }

        return AccessDecision::can($user, Capability::OVR_MANAGE_TYPES);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'name_ar' => ['required', 'string', 'max:255'],
        ];
    }
}
