<?php

namespace App\Modules\Strategy\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Strategy\Models\Blocker;
use Illuminate\Foundation\Http\FormRequest;

/**
 * ViewBlockerRequest - engine-only authz for reading a single blocker.
 * Surfaces STRATEGY_VIEW against the resolved blocker; engine handles
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

        return AccessDecision::can($user, Capability::STRATEGY_VIEW, $blocker);
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
