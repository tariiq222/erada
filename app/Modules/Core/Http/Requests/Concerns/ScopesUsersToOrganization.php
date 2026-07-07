<?php

namespace App\Modules\Core\Http\Requests\Concerns;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Shared `exists:users,id` rule that is scoped to the actor's organization
 * (H-03). Super admins are unscoped; an actor with no organization is denied
 * outright. Deduplicates the rule that was copy-pasted per FormRequest
 * (canonical body in StoreRiskRequest::orgScopedUserRule()).
 */
trait ScopesUsersToOrganization
{
    public function orgScopedUserRule(): Exists
    {
        $rule = Rule::exists('users', 'id');
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
