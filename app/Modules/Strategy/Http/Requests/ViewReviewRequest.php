<?php

namespace App\Modules\Strategy\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Strategy\Models\Review;
use Illuminate\Foundation\Http\FormRequest;

/**
 * ViewReviewRequest - engine-only authz for reading a single review.
 * Surfaces STRATEGY_VIEW against the resolved review; engine handles
 * super_admin bypass + organization isolation (Review is ScopeAware).
 */
class ViewReviewRequest extends FormRequest
{
    protected ?Review $review = null;

    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        $review = $this->route('review');

        if (! $review instanceof Review) {
            $review = Review::find($review);
        }

        if (! $review) {
            return true;
        }

        $this->review = $review;

        return AccessDecision::can($user, Capability::STRATEGY_VIEW, $review);
    }

    public function rules(): array
    {
        return [];
    }

    public function getReview(): ?Review
    {
        return $this->review;
    }
}
