<?php

namespace App\Modules\Meetings\Http\Requests;

use App\Modules\Meetings\Models\Recommendation;
use Illuminate\Foundation\Http\FormRequest;

/**
 * DeferRecommendationRequest
 *
 * Persists the deferral metadata on the recommendation:
 *   - defer_reason   (free-text)
 *   - deferred_until (date — typically the new target date for the
 *                     action_item's `due_date`, or a re-decision date
 *                     for a ruling)
 *
 * Authorization routes through RecommendationPolicy::defer() which
 * enforces the engine capability and the self-approval block for
 * rulings.
 */
class DeferRecommendationRequest extends FormRequest
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

        return $user->can('defer', $recommendation);
    }

    public function rules(): array
    {
        return [
            'defer_reason' => ['nullable', 'string', 'min:5', 'max:5000'],
            'deferred_until' => ['nullable', 'date', 'after_or_equal:today'],
        ];
    }
}
