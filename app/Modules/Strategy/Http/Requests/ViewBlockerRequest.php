<?php

namespace App\Modules\Strategy\Http\Requests;

use App\Modules\Strategy\Models\Blocker;
use App\Modules\Strategy\Policies\BlockerPolicy;
use Illuminate\Foundation\Http\FormRequest;

/**
 * ViewBlockerRequest - engine-only authz for reading a single blocker.
 *
 * Phase 9-D-D1b — delegates to BlockerPolicy::view() which contains the
 * cluster_tree read widening (STRATEGY_VIEW + CLUSTER_TREE_VIEW on actor
 * ⇒ cross-org read for descendant organizations). The engine handles
 * super_admin bypass + organization isolation (Blocker is ScopeAware).
 */
class ViewBlockerRequest extends FormRequest
{
    protected ?Blocker $blocker = null;

    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        $blocker = $this->route('blocker');

        if (! $blocker instanceof Blocker) {
            $blocker = Blocker::find($blocker);
        }

        if (! $blocker) {
            return true;
        }

        $this->blocker = $blocker;

        return app(BlockerPolicy::class)->view($user, $blocker);
    }

    public function rules(): array
    {
        return [];
    }

    public function getBlocker(): ?Blocker
    {
        return $this->blocker;
    }
}
