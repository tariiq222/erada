<?php

namespace App\Modules\Meetings\Http\Requests;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Meetings\Models\Recommendation;
use Illuminate\Foundation\Http\FormRequest;

/**
 * RejectRecommendationRequest
 *
 * Authorization is delegated to RecommendationPolicy::reject() so the
 * kind-aware capability routing and self-rejection guard live in one
 * place.
 */
class RejectRecommendationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user) {
            return false;
        }

        $recommendation = $this->route('recommendation');

        if (! $recommendation instanceof Recommendation) {
            $recommendation = Recommendation::find($recommendation);
        }

        if (! $recommendation) {
            return false;
        }

        return $user->can('reject', $recommendation);
    }

    public function rules(): array
    {
        return [
            'rationale' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
