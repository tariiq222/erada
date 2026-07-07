<?php

namespace App\Modules\Strategy\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Strategy\Models\Portfolio;
use Illuminate\Foundation\Http\FormRequest;

/**
 * ViewPortfolioRequest - engine-only authz for reading a single portfolio.
 * Surfaces STRATEGY_VIEW against the resolved portfolio; engine handles
 * super_admin bypass + organization isolation (Portfolio is ScopeAware).
 */
class ViewPortfolioRequest extends FormRequest
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

        return AccessDecision::can($user, Capability::STRATEGY_VIEW, $portfolio);
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
