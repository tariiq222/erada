<?php

namespace App\Modules\Meetings\Http\Requests;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Meetings\Models\Recommendation;
use Illuminate\Foundation\Http\FormRequest;

/**
 * ApproveRecommendationRequest
 *
 * Target-aware authorize: routes through Capability::RECOMMENDATIONS_APPROVE
 * for rulings, Capability::RECOMMENDATIONS_ACCEPT for action_item kind.
 * The RecommendationPolicy::approve() handles the kind branch + self-approval
 * guard so this FormRequest stays focused on input rules.
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
