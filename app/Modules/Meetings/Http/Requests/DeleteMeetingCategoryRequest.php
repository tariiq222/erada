<?php

namespace App\Modules\Meetings\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Meetings\Models\MeetingCategory;
use Illuminate\Foundation\Http\FormRequest;

/**
 * DeleteMeetingCategoryRequest - engine-only authz for deleting a meeting
 * category.
 *
 * Audit fix: the previous controller called $this->authorize('delete',
 * Meeting::class) which (a) routes against the wrong model and (b) gates
 * on the bare Spatie delete-meetings permission rather than the engine's
 * MEETINGS_DELETE capability on the category itself. authorize() now runs
 * MEETINGS_DELETE through AccessDecision::can against the resolved
 * category; the engine handles super_admin bypass + organization
 * isolation (MeetingCategory is ScopeAware).
 */
class DeleteMeetingCategoryRequest extends FormRequest
{
    protected ?MeetingCategory $category = null;

    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        $category = $this->route('meetingCategory');

        if (! $category instanceof MeetingCategory) {
            $category = MeetingCategory::find($category);
        }

        // ponytail: null → let route model binding produce the 404.
        if (! $category) {
            return true;
        }

        $this->category = $category;

        return AccessDecision::can($user, Capability::MEETINGS_DELETE, $category);
    }

    public function rules(): array
    {
        return [];
    }

    public function getCategory(): ?MeetingCategory
    {
        return $this->category;
    }
}
