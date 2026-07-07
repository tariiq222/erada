<?php

namespace App\Modules\Strategy\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Strategy\Models\Portfolio;
use Illuminate\Foundation\Http\FormRequest;

/**
 * UpdatePortfolioPriorityRequest - validation + engine-only authz for the
 * dedicated "PUT /portfolios/{portfolio}/priority" endpoint.
 *
 * The previous controller called authorizeStrategy('update') plus
 * canManagePortfolioPriority() and validated inline. authorize() now
 * resolves strategy.manage_priority through AccessDecision::can() against
 * the bound Portfolio — the same gate the helper used. The controller
 * drops the redundant privilege check.
 */
class UpdatePortfolioPriorityRequest extends FormRequest
{
    protected ?Portfolio $portfolio = null;

    public function authorize(): bool
    {
        $portfolio = $this->route('portfolio');

        if (! $portfolio instanceof Portfolio) {
            return false;
        }

        $this->portfolio = $portfolio;

        $user = $this->user();

        return $user !== null
            && AccessDecision::can($user, Capability::STRATEGY_MANAGE_PRIORITY, $portfolio);
    }

    public function rules(): array
    {
        return [
            'priority_rank' => ['required', 'integer', 'min:0'],
            'weight' => ['required', 'numeric', 'min:0', 'max:100'],
        ];
    }

    public function getPortfolio(): ?Portfolio
    {
        return $this->portfolio;
    }
}
