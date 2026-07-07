<?php

namespace App\Modules\Performance\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Performance\Models\Kpi;
use App\Modules\Performance\Support\KpiOrgGuard;
use App\Modules\Projects\Models\Project;
use App\Modules\Strategy\Models\Program;
use App\Modules\Strategy\Models\Review;
use App\Modules\Strategy\Models\StrategicObjective;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * StoreKpiLinkRequest - validation + engine-based authz for KPI link creation.
 *
 * The store path uses the KPI edit capability (KPIS_EDIT) and enforces the
 * deny-not-bypass cross-organization gate on both the KPI and the linkable
 * target. The compatible-organization check (KPI ↔ linkable) is also lifted
 * here so the controller does not have to repeat it.
 */
class StoreKpiLinkRequest extends FormRequest
{
    protected ?Kpi $kpi = null;

    protected ?Model $linkable = null;

    /**
     * @return array<string, class-string<Model>>
     */
    public static function contextTypes(): array
    {
        return [
            'project' => Project::class,
            'program' => Program::class,
            'objective' => StrategicObjective::class,
            'review' => Review::class,
            'department' => Department::class,
        ];
    }

    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user instanceof User) {
            return false;
        }

        $kpi = $this->resolveKpi();

        if (! $kpi instanceof Kpi) {
            return false;
        }

        $this->kpi = $kpi;

        if (! AccessDecision::can($user, Capability::KPIS_EDIT)) {
            return false;
        }

        // Caller must share the KPI's organization (super_admin handled by engine).
        // Phase 4: delegate to KpiOrgGuard (single source of truth for same-org gate).
        if (! app(KpiOrgGuard::class)->sameOrganizationForKpi($user, $kpi)) {
            return false;
        }

        // Resolve + validate linkable target now so engine-relevant context is set.
        $linkableType = $this->input('linkable_type');

        if (! is_string($linkableType) || ! array_key_exists($linkableType, self::contextTypes())) {
            // Let rules() emit the proper validation error for an invalid type.
            return true;
        }

        $linkableClass = self::contextTypes()[$linkableType];
        $linkableId = $this->input('linkable_id');

        if ($linkableId === null) {
            return true;
        }

        $linkable = $linkableClass::find($linkableId);

        if (! $linkable) {
            throw ValidationException::withMessages([
                'linkable_id' => 'العنصر المرتبط غير موجود',
            ]);
        }

        $this->linkable = $linkable;

        // Cross-org: caller must also share the linkable's organization.
        // Phase 4: delegate to KpiOrgGuard (single source of truth for same-org gate).
        if (! app(KpiOrgGuard::class)->sameOrganization($user, $linkable->organization_id !== null ? (int) $linkable->organization_id : null)) {
            return false;
        }

        // Compatible-organization: KPI and linkable must agree (when both set).
        $kpiOrgId = $kpi->organization_id !== null ? (int) $kpi->organization_id : null;
        $linkableOrgId = $linkable->organization_id !== null ? (int) $linkable->organization_id : null;

        if ($kpiOrgId !== null
            && $linkableOrgId !== null
            && $kpiOrgId !== $linkableOrgId) {
            throw ValidationException::withMessages([
                'linkable_id' => 'يجب أن يكون مؤشر الأداء والعنصر المرتبط ضمن نفس المؤسسة',
            ]);
        }

        return true;
    }

    public function rules(): array
    {
        return [
            'linkable_type' => ['required', Rule::in(array_keys(self::contextTypes()))],
            'linkable_id' => ['required', 'integer', 'min:1'],
            'relationship_type' => ['nullable', 'string', 'max:50'],
            'weight' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function getKpi(): ?Kpi
    {
        return $this->kpi;
    }

    public function getLinkable(): ?Model
    {
        return $this->linkable;
    }

    public function getLinkableClass(): string
    {
        $type = $this->input('linkable_type');

        return self::contextTypes()[$type];
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
}
