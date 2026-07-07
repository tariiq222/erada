<?php

namespace App\Modules\OVR\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use Illuminate\Foundation\Http\FormRequest;

/**
 * UpdateIncidentTypeRequest - update an existing OVR incident type (category).
 *
 * Authorization flows through the unified engine: OVR_MANAGE_TYPES. No model
 * binding (the {type} route param is used by the controller for the target
 * row); the FormRequest contract-level gate is global.
 */
class UpdateIncidentTypeRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'max:255'],
            'name_ar' => ['sometimes', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
