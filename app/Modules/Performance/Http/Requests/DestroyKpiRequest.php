<?php

namespace App\Modules\Performance\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Performance\Models\Kpi;
use Illuminate\Foundation\Http\FormRequest;

/**
 * DestroyKpiRequest - delete a single KPI.
 *
 * Authorization routes through the unified engine (KPIS_DELETE) on the
 * route-bound Kpi. Defense-in-depth: the controller's assertSameOrganization
 * still runs after this returns true.
 */
class DestroyKpiRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }

        $kpi = $this->route('kpi');
        if (! $kpi instanceof Kpi) {
            return false;
        }

        return AccessDecision::can($user, Capability::KPIS_DELETE, $kpi);
    }

    public function rules(): array
    {
        return [];
    }
}
