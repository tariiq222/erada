<?php

namespace App\Modules\Core\Http\Requests\Concerns;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Shared `exists:departments,id` rule scoped to the actor's organization
 * (M-07/L-07). Super admins are unscoped; an actor with no organization is
 * denied outright.
 */
trait ScopesDepartmentsToOrganization
{
    public function orgScopedDepartmentRule(): Exists
    {
        $rule = Rule::exists('departments', 'id');
        $user = $this->user();

        if ($user?->isSuperAdmin()) {
            return $rule;
        }

        if ($user?->organization_id === null) {
            abort(403, 'You do not have access to this resource.');
        }

        return $rule->where('organization_id', $user->organization_id);
    }
}
