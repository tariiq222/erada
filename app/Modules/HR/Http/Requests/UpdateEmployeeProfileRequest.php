<?php

namespace App\Modules\HR\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\EmployeeProfile;
use App\Modules\HR\Support\EmployeeOrgGuard;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * UpdateEmployeeProfileRequest - Phase 2 hardening: HR_MANAGE + same-org + null-org gate.
 *
 * قبل Phase 2 كان authorize() يعتمد فقط على AccessDecision::can(HR_MANAGE)
 * ويُعتمد على الكنترولر لـ assertSameOrganization. هذا الـ FormRequest الآن
 * يفرض العزل record-level في authorize() — لو تجاوز أي مسار الـ controller
 * helper ستبقى الحماية قائمة هنا.
 *
 * - super_admin ⇒ مسموح (AccessDecision short-circuit).
 * - actor بلا organization_id ⇒ مرفوض.
 * - target user من منظمة أخرى ⇒ مرفوض.
 * - target غير موجود ⇒ يُترك لـ route model binding 404 (نفس نمط
 *   ViewEmployeeRequest / DeleteEmployeeRequest).
 */
class UpdateEmployeeProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        // Returns false (not throw) so Laravel emits a 403, matching the
        // FormRequest convention used by Store/Delete variants.
        if ($user === null
            || ! AccessDecision::can($user, Capability::HR_MANAGE)
            || (! $user->isSuperAdmin() && $user->organization_id === null)
        ) {
            return false;
        }

        $employee = $this->route('employee');
        if (! $employee instanceof User) {
            $employee = User::find($employee);
        }

        // ponytail: null → let route model binding produce the 404.
        if ($employee === null) {
            return true;
        }

        return app(EmployeeOrgGuard::class)->sameOrganizationForUser($user, $employee);
    }

    public function rules(): array
    {
        return [
            'employee_no' => ['nullable', 'string', 'max:50'],
            'hire_date' => ['nullable', 'date'],
            'employment_type' => ['required', Rule::in(EmployeeProfile::TYPES)],
            'employment_status' => ['required', Rule::in(EmployeeProfile::STATUSES)],
            'dept_id' => [
                'nullable',
                'integer',
                Rule::exists('departments', 'id')->where(function ($q) {
                    $q->where('organization_id', $this->user()->organization_id);
                }),
            ],
            'notes' => ['nullable', 'string', 'max:2000'],
            'personal_info' => ['nullable', 'array'],
            'personal_info.full_name_english' => ['nullable', 'string', 'max:255'],
        ];
    }
}
