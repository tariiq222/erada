<?php

namespace App\Modules\Performance\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * ImportKpiRequest - validation + engine-only authz for synchronous KPI
 * import (CSV/XLSX).
 *
 * authorize() mirrors the controller's two gate calls (KPIS_MANAGE,
 * resolved through the engine). The "super_admin without organization
 * must send organization_id" rule lives in rules() so it surfaces as a
 * validation error (422) rather than a 403 — the controller coerces
 * non-super_admin organization_id to their own org regardless.
 */
class ImportKpiRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        return AccessDecision::can($user, Capability::KPIS_MANAGE);
    }

    public function rules(): array
    {
        $user = $this->user();
        $requiresOrganization = $user !== null
            && $user->isSuperAdmin()
            && $user->organization_id === null;

        return [
            'organization_id' => [$requiresOrganization ? 'required' : 'nullable', 'integer', Rule::exists('organizations', 'id')],
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx', 'max:10240'],
        ];
    }
}
