<?php

namespace App\Modules\Projects\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation/authorization for PUT /api/projects/governing-departments.
 *
 * Authorizes against SETTINGS_MANAGE via the engine (AccessDecision::can) —
 * raw capability, no target model. The controller still owns the org-isolation
 * cross-field check (department must belong to caller's organization) — that
 * needs the loaded user + a per-loop dept lookup and is not pure input shape.
 */
class UpdateGoverningDepartmentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && AccessDecision::can($user, Capability::SETTINGS_MANAGE);
    }

    public function rules(): array
    {
        return [
            'mapping' => ['required', 'array'],
            'mapping.*' => ['nullable', 'integer', 'exists:departments,id'],
        ];
    }
}
