<?php

namespace App\Modules\Strategy\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Strategy\Models\Blocker;
use Illuminate\Foundation\Http\FormRequest;

/**
 * DeleteBlockerRequest - engine-only authz for deleting a blocker.
 * Surfaces STRATEGY_DELETE against the resolved blocker.
 */
class DeleteBlockerRequest extends FormRequest
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

        return AccessDecision::can($user, Capability::STRATEGY_DELETE, $blocker);
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
