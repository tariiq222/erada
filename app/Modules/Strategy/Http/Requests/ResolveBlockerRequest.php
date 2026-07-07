<?php

namespace App\Modules\Strategy\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Strategy\Models\Blocker;
use Illuminate\Foundation\Http\FormRequest;

/**
 * ResolveBlockerRequest - validation + engine-only authz for resolving a Blocker.
 *
 * The previous controller called authorizeStrategy('update') plus
 * assertSameOrganization() and validated the resolution payload inline.
 * authorize() now resolves strategy.edit through AccessDecision::can() against
 * the bound Blocker; the organization guard stays in the controller (it
 * depends on the bound model and the existing trait flow).
 */
class ResolveBlockerRequest extends FormRequest
{
    protected ?Blocker $blocker = null;

    /**
     * Engine-only authorization for blocker resolution.
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
     * Validation rules for blocker resolution.
     */
    public function rules(): array
    {
        return [
            'resolution' => ['required', 'string'],
        ];
    }

    public function attributes(): array
    {
        return [
            'resolution' => 'سبب/وصف الحل',
        ];
    }

    public function getBlocker(): ?Blocker
    {
        return $this->blocker;
    }
}
