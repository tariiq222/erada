<?php

namespace App\Modules\Core\Rules;

use App\Modules\HR\Models\Department;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Ensures the submitted `department_id` belongs to the `organization_id`
 * carried in the same request.
 *
 * Without this rule, a registrant could pick `organization_id=1` but submit
 * a `department_id` whose row belongs to `organization_id=2`, breaking the
 * tenancy invariant "user.organization_id == user.department.organization_id".
 *
 * Bypassed (returns true) when either side is absent — the caller's own
 * `required` rules decide whether presence is mandatory.
 */
class DepartmentBelongsToOrganization implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $orgId = request()->input('organization_id');

        if (! is_numeric($value) || ! is_numeric($orgId)) {
            return;
        }

        $matches = Department::query()
            ->whereKey((int) $value)
            ->where('organization_id', (int) $orgId)
            ->exists();

        if (! $matches) {
            $fail('القسم المحدد لا يتبع للمؤسسة المختارة.');
        }
    }
}
