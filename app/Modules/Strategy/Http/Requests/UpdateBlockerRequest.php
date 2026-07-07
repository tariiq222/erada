<?php

namespace App\Modules\Strategy\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Strategy\Models\Blocker;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * UpdateBlockerRequest - validation + engine-only authz for editing a Blocker.
 *
 * The previous controller called authorizeStrategy('update') plus
 * assertSameOrganization() and validated inline. authorize() now resolves
 * strategy.edit through AccessDecision::can() against the bound Blocker; the
 * organization guard stays in the controller (it depends on the bound model
 * and the existing trait flow).
 */
class UpdateBlockerRequest extends FormRequest
{
    protected ?Blocker $blocker = null;

    /**
     * Engine-only authorization for blocker update.
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
     * Validation rules for blocker update. "sometimes|required" mirrors the
     * pipe-style behavior from the original controller while staying in Pint
     * array form.
     */
    public function rules(): array
    {
        $user = $this->user();
        $userRule = Rule::exists('users', 'id');

        if ($user?->isSuperAdmin() !== true) {
            if ($user?->organization_id === null) {
                $userRule = Rule::exists('users', 'id')->where('organization_id', -1);
            } else {
                $userRule = $userRule->where('organization_id', $user->organization_id);
            }
        }

        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'severity' => ['nullable', Rule::in(['low', 'medium', 'high', 'critical'])],
            'status' => ['nullable', Rule::in(['open', 'in_progress', 'escalated', 'resolved'])],
            'expected_resolution_date' => ['nullable', 'date'],
            'assigned_to' => ['nullable', $userRule],
        ];
    }

    public function getBlocker(): ?Blocker
    {
        return $this->blocker;
    }
}
