<?php

namespace App\Modules\Strategy\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use Illuminate\Foundation\Http\FormRequest;

/**
 * ListPortfoliosRequest - engine-only authz for the portfolios index.
 * Surfaces STRATEGY_VIEW through the engine.
 */
class ListPortfoliosRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && AccessDecision::can($user, Capability::STRATEGY_VIEW);
    }

    public function rules(): array
    {
        return [];
    }
}
