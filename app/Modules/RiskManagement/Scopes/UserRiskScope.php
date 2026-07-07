<?php

namespace App\Modules\RiskManagement\Scopes;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\User;
use App\Modules\RiskManagement\Services\RiskAuthorizationService;
use Illuminate\Database\Eloquent\Builder;

/**
 * UserRiskScope — applies the caller's risk visibility to a risks query.
 *
 * Additive (OR) model, mirroring UserProjectScope. After organization isolation,
 * a non-super-admin sees a risk when any of these holds:
 *   - direct relation: creator, owner, or listed stakeholder;
 *   - an active scoped role granting risks.view on the risk's department (or an
 *     ancestor, expanded to the subtree);
 *   - an org-level functional role granting risks.view (whole organization);
 *   - membership of the risks governing department (whole organization).
 *
 * Before this scope the risk list applied only an organization filter, so any
 * user with risks.view over-fetched every risk in the org. This narrows it.
 */
class UserRiskScope
{
    public function apply(Builder $query, User $user): Builder
    {
        if ($user->isSuperAdmin()) {
            return $query;
        }

        // Organization isolation first (prevents cross-org leakage).
        if ($user->organization_id) {
            $query->where('organization_id', $user->organization_id);
        }

        $svc = app(RiskAuthorizationService::class);

        // Whole-organization access: an org-level functional grant (admin) or
        // the risks governing department (overseer). Org isolation above still
        // holds. The legacy flat view_risks Spatie fallback has been removed
        // — the engine path is the only authz source.
        if (AccessDecision::grantsAtOrganization($user, Capability::RISKS_VIEW)
            || $svc->governs($user)) {
            return $query;
        }

        // Otherwise: direct relation OR department-subtree grants.
        $scopes = AccessDecision::grantingScopes($user, Capability::RISKS_VIEW);
        $deptIds = AccessDecision::subtreeDepartmentIds($scopes['department'] ?? []);

        return $query->where(function (Builder $q) use ($user, $deptIds) {
            $q->where('created_by', $user->id)
                ->orWhere('owner_id', $user->id)
                ->orWhereJsonContains('stakeholder_ids', $user->id);

            if ($deptIds !== []) {
                $q->orWhereIn('department_id', $deptIds);
            }
        });
    }
}
