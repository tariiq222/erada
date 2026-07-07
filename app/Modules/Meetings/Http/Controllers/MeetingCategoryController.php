<?php

namespace App\Modules\Meetings\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Meetings\Http\Requests\DeleteMeetingCategoryRequest;
use App\Modules\Meetings\Http\Requests\StoreMeetingCategoryRequest;
use App\Modules\Meetings\Http\Requests\UpdateMeetingCategoryRequest;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Meetings\Models\MeetingCategory;
use App\Modules\Shared\Traits\HasOrganizationScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeetingCategoryController extends Controller
{
    use HasOrganizationScope;

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Meeting::class);

        $query = MeetingCategory::query()->orderBy('sort_order')->orderBy('name');

        if (! auth()->user()->isSuperAdmin()) {
            $query->where('organization_id', auth()->user()->organization_id);
        }

        if ($request->boolean('active_only')) {
            $query->where('is_active', true);
        }

        return response()->json(['data' => $query->get()]);
    }

    public function store(StoreMeetingCategoryRequest $request): JsonResponse
    {
        $validated = $request->validated();

        // Null-org fail-closed floor: never create a tenant-less meeting category.
        abort_if(auth()->user()->organization_id === null, 403, 'المستخدم لا ينتمي لمؤسسة');

        $category = MeetingCategory::create([
            'name' => $validated['name'],
            'is_active' => $validated['is_active'] ?? true,
            'sort_order' => $validated['sort_order'] ?? 0,
            'organization_id' => auth()->user()->organization_id,
        ]);

        return response()->json(['message' => 'تم إنشاء التصنيف بنجاح', 'category' => $category], 201);
    }

    public function update(UpdateMeetingCategoryRequest $request, MeetingCategory $meetingCategory): JsonResponse
    {
        $this->assertSameOrganization($meetingCategory);

        $validated = $request->validated();

        $meetingCategory->update($validated);

        return response()->json(['message' => 'تم تحديث التصنيف بنجاح', 'category' => $meetingCategory]);
    }

    public function destroy(DeleteMeetingCategoryRequest $request, MeetingCategory $meetingCategory): JsonResponse
    {
        // Authz (MEETINGS_DELETE on the category) + org-isolation floor owned by
        // DeleteMeetingCategoryRequest. Audit fix: previously authorized against
        // the wrong model (Meeting::class).
        $meetingCategory->delete();

        return response()->json(['message' => 'تم حذف التصنيف بنجاح']);
    }
}
