<?php

namespace App\Modules\Strategy\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Strategy\Models\Portfolio;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * UpdatePortfolioStrategicStatusRequest - validation + engine-only authz for
 * "PUT /portfolios/{portfolio}/strategic-status".
 *
 * The previous controller called authorizeStrategy('update') plus
 * assertSameOrganization() and validated inline. authorize() now resolves
 * strategy.edit through AccessDecision::can() against the bound Portfolio —
 * the same gate the generic update path uses — so that non-admin editors who
 * can edit strategy can still hit the controller's close guard (which returns
 * 422 when active programs exist and the user lacks force-close privilege).
 * The canBeClosedStrategically() / canForceClosePortfolio() check stays in
 * the controller because it interacts with PortfolioDecisionService to log
 * the force-close.
 */
class UpdatePortfolioStrategicStatusRequest extends FormRequest
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
            && AccessDecision::can($user, Capability::STRATEGY_EDIT, $portfolio);
    }

    public function rules(): array
    {
        return [
            'portfolio_status' => ['required', Rule::in(['active', 'rebalancing', 'frozen', 'closed_strategically'])],
            'decision_note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function getPortfolio(): ?Portfolio
    {
        return $this->portfolio;
    }
}
