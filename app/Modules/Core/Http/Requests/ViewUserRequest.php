<?php

namespace App\Modules\Core\Http\Requests;

use App\Modules\Core\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * ViewUserRequest - engine-only authz for reading a single user.
 *
 * authorize() runs the existing UserPolicy::view path through the unified
 * AuthZ engine (self floor + capability gate + org-isolation floor). No
 * payload rules — read endpoints take no user input.
 *
 * Phase CFA-07 (HIGH PII): this seam ALSO admits the cluster limited-directory
 * widening via UserPolicy::viewDirectory(). When the existing `view()` denies
 * (cross-org same-cluster target), `viewDirectory()` may rescue the request so
 * the controller can return the sanitized directory shape. Same-org reads
 * keep using `view()` and return the full UserResource; cross-org widening
 * does NOT alter the existing `view()` path — it ONLY opens the narrow
 * viewDirectory() rescue so the controller can route to UserDirectoryResource
 * (which is the PII firewall).
 */
class ViewUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->route('user');

        if (! $user instanceof User) {
            $user = User::find($user);
        }

        // ponytail: return true on null so route model binding's natural 404
        // runs (e.g. /api/users/999999). Returning false here would yield a
        // misleading 403 instead of the 404 the HTTP semantics demand.
        if (! $user) {
            return true;
        }

        $actor = $this->user();

        if ($actor === null) {
            return false;
        }

        // (1) Same-org read — the existing strict path. Owner floor + engine + org-isolation.
        if ($actor->can('view', $user)) {
            return true;
        }

        // (2) Cluster widening — CFA-07: cross-org target via the two-path
        // directory grant (USERS_VIEW + CLUSTER_TREE_VIEW). The controller will
        // detect that this branch admitted the request and emit the sanitized
        // UserDirectoryResource instead of the full UserResource.
        return $actor->can('viewDirectory', $user);
    }

    public function rules(): array
    {
        return [];
    }
}
