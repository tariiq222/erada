<?php

namespace App\Modules\Strategy\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Strategy\Models\Review;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * UpdateReviewRequest - validation + engine-only authz for editing a Review.
 *
 * The previous controller called authorizeStrategy('update') plus
 * assertSameOrganization() and validated inline. authorize() now resolves
 * strategy.edit through AccessDecision::can() against the bound Review;
 * the organization guard stays in the controller.
 */
class UpdateReviewRequest extends FormRequest
{
    protected ?Review $review = null;

    public function authorize(): bool
    {
        $review = $this->route('review');

        if (! $review instanceof Review) {
            return false;
        }

        $this->review = $review;

        $user = $this->user();

        return $user !== null
            && AccessDecision::can($user, Capability::STRATEGY_EDIT, $review);
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'type' => ['sometimes', 'required', Rule::in(['monthly', 'quarterly', 'annual', 'adhoc'])],
            'pdca_phase' => ['sometimes', 'required', Rule::in(['plan', 'do', 'check', 'act'])],
            'review_date' => ['sometimes', 'required', 'date'],
            'period_start' => ['sometimes', 'required', 'date'],
            'period_end' => ['sometimes', 'required', 'date', 'after_or_equal:period_start'],
            'overall_status' => ['nullable', Rule::in(['on_track', 'at_risk', 'off_track', 'completed'])],
            'achievements' => ['nullable', 'string'],
            'challenges' => ['nullable', 'string'],
            'lessons_learned' => ['nullable', 'string'],
            'next_steps' => ['nullable', 'string'],
            'recommendations' => ['nullable', 'string'],
            'attendees' => ['nullable', 'array'],
        ];
    }

    public function getReview(): ?Review
    {
        return $this->review;
    }
}
