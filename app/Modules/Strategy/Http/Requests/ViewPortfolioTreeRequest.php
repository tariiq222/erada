<?php

namespace App\Modules\Strategy\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Strategy\Models\Portfolio;
use Illuminate\Foundation\Http\FormRequest;

/**
 * ViewPortfolioTreeRequest - engine-only authz for the portfolio tree endpoint.
 *
 * Mirrors ViewPortfolioRequest but exposes tree-specific query params:
 *   - depth=programs|full    (default: full)
 *   - include_status=active|all  (default: active)
 *   - hide_empty_programs=1
 *
 * The FormRequest enforces STRATEGY_VIEW against the resolved Portfolio;
 * the controller still applies org isolation via the engine.
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

        return AccessDecision::can($user, Capability::STRATEGY_VIEW, $portfolio);
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
