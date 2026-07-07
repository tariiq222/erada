<?php

namespace App\Modules\Performance\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Performance\Models\Kpi;
use App\Modules\Performance\Support\KpiOrgGuard;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * UpdateKpiRequest - validation + engine-based authz for KPI updates.
 *
 * The {kpi} route-model binding is resolved here for the engine capability
 * check and the deny-not-bypass cross-organization gate. Controller-side
 * organization assertions become redundant.
 */
class UpdateKpiRequest extends FormRequest
{
    protected ?Kpi $kpi = null;

    public function authorize(): bool
    {
        $user = $this->user();
        $kpi = $this->resolveKpi();

        if (! $user instanceof User || ! $kpi instanceof Kpi) {
            return false;
        }

        $this->kpi = $kpi;

        if (! AccessDecision::can($user, Capability::KPIS_EDIT)) {
            return false;
        }

        // Deny-not-bypass: caller must share the KPI's organization (super_admin handled by engine).
        // Phase 4: delegate to KpiOrgGuard (single source of truth for same-org gate).
        return app(KpiOrgGuard::class)->sameOrganizationForKpi($user, $kpi);
    }

    public function rules(): array
    {
        $kpi = $this->kpi;

        $codeRule = Rule::unique('kpis', 'code');
        if ($kpi !== null) {
            $codeRule->ignore($kpi->id);
        }

        return [
            'code' => ['nullable', 'string', 'max:40', $codeRule],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'measurement_method' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:100'],
            'baseline' => ['nullable', 'numeric', 'min:-1000000000000', 'max:1000000000000'],
            'target' => ['nullable', 'numeric', 'min:-1000000000000', 'max:1000000000000'],
            // M-10: current_value is measurement-derived, never client-settable.
            'unit' => ['nullable', 'string', 'max:50'],
            'frequency' => ['nullable', Rule::in(array_keys(Kpi::FREQUENCY_LABELS))],
            'direction' => ['nullable', Rule::in(array_keys(Kpi::DIRECTION_LABELS))],
            'status' => ['nullable', 'in:active,inactive,archived'],
            'owner_id' => ['nullable', $this->orgScopedUserRule()],
            'order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'department_ids' => ['sometimes', 'array'],
            'department_ids.*' => ['integer', 'distinct', 'min:1'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (! $this->kpi || ! $this->filled('department_ids')) {
                return;
            }

            if ($this->kpi->organization_id === null) {
                $validator->errors()->add(
                    'department_ids',
                    'يجب أن يكون مؤشر الأداء مرتبطاً بمؤسسة قبل ربط الإدارات'
                );

                return;
            }

            $ids = array_values(array_unique(array_map('intval', (array) $this->input('department_ids'))));

            if ($ids === []) {
                return;
            }

            $validCount = Department::query()
                ->where('organization_id', (int) $this->kpi->organization_id)
                ->whereIn('id', $ids)
                ->count();

            if ($validCount !== count($ids)) {
                $validator->errors()->add(
                    'department_ids',
                    'يجب اختيار إدارات من نفس مؤسسة مؤشر الأداء'
                );
            }
        });
    }

    public function getKpi(): ?Kpi
    {
        return $this->kpi;
    }

    private function resolveKpi(): ?Kpi
    {
        $routeKpi = $this->route('kpi');

        if ($routeKpi instanceof Kpi) {
            return $routeKpi;
        }

        if ($routeKpi !== null) {
            return Kpi::find($routeKpi);
        }

        return null;
    }

    private function orgScopedUserRule(): Exists
    {
        $user = $this->user();
        $rule = Rule::exists('users', 'id');

        if ($user instanceof User && ! $user->isSuperAdmin()) {
            if ($user->organization_id === null) {
                abort(403, 'ليس لديك صلاحية الوصول لهذا العنصر');
            }

            $rule->where('organization_id', $user->organization_id);
        }

        return $rule;
    }
}
