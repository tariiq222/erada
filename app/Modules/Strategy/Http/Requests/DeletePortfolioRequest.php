<?php

namespace App\Modules\Strategy\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Strategy\Models\Portfolio;
use Illuminate\Foundation\Http\FormRequest;

/**
 * DeletePortfolioRequest - engine-only authz for deleting a portfolio.
 * Surfaces STRATEGY_DELETE against the resolved portfolio; the "has
 * programs" 422 business rule stays in the controller (it's a state
 * check, not AuthZ).
 */
class DeletePortfolioRequest extends FormRequest
{
    protected ?Portfolio $portfolio = null;

    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        $portfolio = $this->route('portfolio');

        if (! $portfolio instanceof Portfolio) {
            $portfolio = Portfolio::find($portfolio);
        }

        if (! $portfolio) {
            return true;
        }

        $this->portfolio = $portfolio;

        return AccessDecision::can($user, Capability::STRATEGY_DELETE, $portfolio);
    }

    public function rules(): array
    {
        return [];
    }

    public function getPortfolio(): ?Portfolio
    {
        return $this->portfolio;
    }
}
