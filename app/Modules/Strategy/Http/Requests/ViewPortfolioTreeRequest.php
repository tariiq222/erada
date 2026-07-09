<?php

namespace App\Modules\Strategy\Http\Requests;

use App\Modules\Strategy\Models\Portfolio;
use App\Modules\Strategy\Policies\PortfolioPolicy;
use Illuminate\Foundation\Http\FormRequest;

/**
 * ViewPortfolioTreeRequest - engine-only authz for the portfolio tree endpoint.
 *
 * Mirrors ViewPortfolioRequest but exposes tree-specific query params:
 *   - depth=programs|full    (default: full)
 *   - include_status=active|all  (default: active)
 *   - hide_empty_programs=1
 *
 * Phase 9-D-D1b — delegates to PortfolioPolicy::view() so cluster_tree
 * read widening applies (STRATEGY_VIEW + CLUSTER_TREE_VIEW on actor ⇒
 * read access to descendant organizations' portfolios).
 */
class ViewPortfolioTreeRequest extends FormRequest
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
        return [
            'depth' => 'nullable|in:full,programs',
            'include_status' => 'nullable|in:active,all',
            'hide_empty_programs' => 'nullable|boolean',
        ];
    }

    public function getPortfolio(): ?Portfolio
    {
        return $this->portfolio;
    }
}
