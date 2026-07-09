<?php

namespace App\Modules\Strategy\Http\Requests;

use App\Modules\Strategy\Models\Review;
use App\Modules\Strategy\Policies\ReviewPolicy;
use Illuminate\Foundation\Http\FormRequest;

/**
 * ViewReviewRequest - engine-only authz for reading a single review.
 *
 * Phase 9-D-D1b — delegates to ReviewPolicy::view() which contains the
 * cluster_tree read widening (STRATEGY_VIEW + CLUSTER_TREE_VIEW on actor
 * ⇒ cross-org read for descendant organizations). The engine handles
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

        return app(ReviewPolicy::class)->view($user, $review);
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
