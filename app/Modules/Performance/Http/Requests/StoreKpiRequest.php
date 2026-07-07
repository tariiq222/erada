<?php

namespace App\Modules\Performance\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Performance\Models\Kpi;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\ValidationException;

/**
 * StoreKpiRequest - validation + engine-based authz for KPI creation.
 *
 * Authorization is decided by the unified AuthZ engine (AccessDecision::can)
 * via the Capability constant. Cross-organization isolation is enforced here
 * (deny-not-bypass) instead of in the controller, so the FormRequest is the
 * single gate.
 */
class StoreKpiRequest extends FormRequest
{
    /**
     * Engine-only authz: caller must hold the KPI create capability.
     * super_admin is handled by the engine itself (before()).
     */
    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user instanceof User) {
            return false;
        }

        if (! AccessDecision::can($user, Capability::KPIS_MANAGE)) {
            return false;
        }

        // A super_admin without an organization MUST send organization_id;
        // everyone else is scoped to their own org implicitly.
        $requiresOrganization = $user->isSuperAdmin() && $user->organization_id === null;

        if ($requiresOrganization && ! $this->filled('organization_id')) {
            throw ValidationException::withMessages([
                'organization_id' => 'يجب تحديد المنظمة لمؤشر الأداء',
            ]);
        }

        if ($this->filled('organization_id') && ! $user->isSuperAdmin()) {
            if ($user->organization_id === null) {
                return false;
            }
            // Non-super-admins MAY send organization_id; the controller scopes
            // the write to $user->organization_id regardless of the body value.
            // A mismatched id is silently coerced by the controller — not a 403.
        }

        return true;
    }

    public function rules(): array
    {
        $user = $this->user();
        $requiresOrganization = $user instanceof User
            && $user->isSuperAdmin()
            && $user->organization_id === null;

        return [
            'organization_id' => [$requiresOrganization ? 'required' : 'nullable', 'integer', Rule::exists('organizations', 'id')],
            'code' => ['nullable', 'string', 'max:40', Rule::unique('kpis', 'code')],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'measurement_method' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:100'],
            'baseline' => ['nullable', 'numeric', 'min:-1000000000000', 'max:1000000000000'],
            'target' => ['nullable', 'numeric', 'min:-1000000000000', 'max:1000000000000'],
            // M-10: current_value is measurement-derived, never client-settable.
            // Seeded from baseline on create; changed only via KpiMeasurement.
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

    /**
     * Re-validate department_ids against the resolved organization id.
     * Done in after() because organization_id may come from the user (not body).
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (! $this->filled('department_ids')) {
                return;
            }

            $organizationId = $this->resolveOrganizationIdForDepartments();

            if ($organizationId === null) {
                $validator->errors()->add(
                    'department_ids',
                    'يجب تحديد مؤسسة قبل ربط الإدارات'
                );

                return;
            }

            $ids = array_values(array_unique(array_map('intval', (array) $this->input('department_ids'))));

            if ($ids === []) {
                return;
            }

            $validCount = Department::query()
                ->where('organization_id', $organizationId)
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

    private function resolveOrganizationIdForDepartments(): ?int
    {
        $user = $this->user();

        if ($user instanceof User && $user->isSuperAdmin()) {
            $requested = $this->input('organization_id');

            return $requested !== null ? (int) $requested : ($user->organization_id !== null ? (int) $user->organization_id : null);
        }

        return $user?->organization_id !== null ? (int) $user->organization_id : null;
    }
}
