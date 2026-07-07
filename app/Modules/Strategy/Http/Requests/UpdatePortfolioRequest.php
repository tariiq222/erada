<?php

namespace App\Modules\Strategy\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Strategy\Models\Portfolio;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * UpdatePortfolioRequest - validation + engine-only authz for editing a Portfolio.
 *
 * The previous controller called authorizeStrategy('update') plus
 * assertSameOrganization(), validated inline, and then enforced a strategic
 * close guard (portfolio_status = closed_strategically). authorize() now
 * resolves strategy.edit through AccessDecision::can() against the bound
 * Portfolio; the strategic close guard stays in the controller (it depends
 * on Portfolio::canBeClosedStrategically() and the decision service).
 */
class UpdatePortfolioRequest extends FormRequest
{
    protected ?Portfolio $portfolio = null;

    /**
     * Engine-only authorization for portfolio update.
     */
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

    /**
     * Whether the current user may set priority/weight on update.
     */
    public function canManagePriority(): bool
    {
        $user = $this->user();

        return $user !== null
            && AccessDecision::can($user, Capability::STRATEGY_MANAGE_PRIORITY, $this->portfolio);
    }

    /**
     * Validation rules for portfolio update.
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'rationale' => ['nullable', 'string'],
            'strategic_plan_link' => ['nullable', 'string', 'max:500'],
            'directive_source' => ['nullable', Rule::in(['cluster_3', 'moh', 'holding', 'other'])],
            'directive_source_other' => ['nullable', 'string', 'max:255', 'required_if:directive_source,other'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'status' => ['nullable', Rule::in(['draft', 'active', 'completed', 'cancelled'])],
            'portfolio_status' => ['nullable', Rule::in(['active', 'rebalancing', 'frozen', 'closed_strategically'])],
            'order' => ['nullable', 'integer', 'min:0'],
            'priority_rank' => ['nullable', 'integer', 'min:0'],
            'weight' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'decision_note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function getPortfolio(): ?Portfolio
    {
        return $this->portfolio;
    }
}
