<?php

namespace App\Modules\Strategy\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use Illuminate\Foundation\Http\FormRequest;

/**
 * ListReviewsRequest - engine-only authz for the reviews index.
 * Surfaces STRATEGY_VIEW.
 */
class ListReviewsRequest extends FormRequest
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
