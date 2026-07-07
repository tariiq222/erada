<?php

namespace App\Modules\Performance\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Performance\Models\KpiLink;
use Illuminate\Foundation\Http\FormRequest;

/**
 * DestroyKpiLinkRequest - unlink a KPI from a target.
 *
 * Authorization routes through the unified engine (KPIS_DELETE) on the
 * route-bound KpiLink. Defense-in-depth: the controller's
 * assertSameOrganization still runs after this returns true.
 */
class DestroyKpiLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }

        $link = $this->route('link');
        if (! $link instanceof KpiLink) {
            return false;
        }

        return AccessDecision::can($user, Capability::KPIS_DELETE, $link);
    }

    public function rules(): array
    {
        return [];
    }
}
