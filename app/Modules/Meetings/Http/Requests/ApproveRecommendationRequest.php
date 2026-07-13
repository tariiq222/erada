<?php

namespace App\Modules\Meetings\Http\Requests;

use App\Modules\Meetings\Models\Recommendation;
use Illuminate\Foundation\Http\FormRequest;

/**
 * ApproveRecommendationRequest
 *
 * Target-aware authorization is ruling-only. Action items move through the
 * dedicated accept endpoint; RecommendationPolicy::approve() also enforces
 * the self-approval guard.
 */
class ApproveRecommendationRequest extends FormRequest
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

        return $user->can('approve', $recommendation);
    }

    public function rules(): array
    {
        return [
            'rationale' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
