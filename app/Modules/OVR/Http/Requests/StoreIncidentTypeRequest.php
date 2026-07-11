<?php

namespace App\Modules\OVR\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use Illuminate\Foundation\Http\FormRequest;

/**
 * StoreIncidentTypeRequest - create a new OVR incident type (category).
 *
 * Authorization flows through the unified engine: OVR_MANAGE_TYPES. The route
 * gates on the same capability via `engine_capability:ovr.manage_types`
 * middleware; the FormRequest repeats the engine check at the contract
 * boundary so the route is self-documenting even if middleware is reordered.
 */
class StoreIncidentTypeRequest extends FormRequest
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
            'is_active' => ['sometimes', 'boolean'],
            'requires_reportable_type' => ['sometimes', 'boolean'],
        ];
    }
}
