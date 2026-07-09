<?php

namespace App\Modules\Strategy\Http\Requests;

use App\Modules\Strategy\Models\Portfolio;
use App\Modules\Strategy\Policies\PortfolioPolicy;
use Illuminate\Foundation\Http\FormRequest;

/**
 * ViewPortfolioRequest - engine-only authz for reading a single portfolio.
 *
 * Phase 9-D-D1b — delegates to PortfolioPolicy::view() which contains the
 * cluster_tree read widening (STRATEGY_VIEW + CLUSTER_TREE_VIEW on actor
 * ⇒ cross-org read for descendant organizations). The engine handles
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

        return app(PortfolioPolicy::class)->view($user, $portfolio);
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
