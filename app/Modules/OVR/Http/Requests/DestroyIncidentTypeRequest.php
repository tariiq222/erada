<?php

namespace App\Modules\OVR\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use Illuminate\Foundation\Http\FormRequest;

/**
 * DestroyIncidentTypeRequest - delete an OVR incident type (category).
 *
 * Authorization flows through the unified engine: OVR_MANAGE_TYPES. No
 * validation rules apply on DELETE; the body is ignored by Laravel for
 * non-form payloads.
 */
class DestroyIncidentTypeRequest extends FormRequest
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
        return [];
    }
}
