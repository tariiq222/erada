<?php

namespace App\Modules\Strategy\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Strategy\Models\Blocker;
use Illuminate\Foundation\Http\FormRequest;

/**
 * EscalateBlockerRequest - validation + engine-only authz for escalating a Blocker.
 *
 * Mirrors ResolveBlockerRequest: the previous controller called
 * authorizeStrategy('update') plus assertSameOrganization() with no payload
 * (escalate only changes status). authorize() now resolves strategy.edit
 * through AccessDecision::can() against the bound Blocker; the organization
 * guard stays in the controller.
 */
class EscalateBlockerRequest extends FormRequest
{
    protected ?Blocker $blocker = null;

    /**
     * Engine-only authorization for blocker escalation.
     */
    public function authorize(): bool
    {
        $blocker = $this->route('blocker');

        if (! $blocker instanceof Blocker) {
            return false;
        }

        $this->blocker = $blocker;

        $user = $this->user();

        return $user !== null
            && AccessDecision::can($user, Capability::STRATEGY_EDIT, $blocker);
    }

    /**
     * No payload - escalate is a pure state transition.
     */
    public function rules(): array
    {
        return [];
    }

    public function getBlocker(): ?Blocker
    {
        return $this->blocker;
    }
}
