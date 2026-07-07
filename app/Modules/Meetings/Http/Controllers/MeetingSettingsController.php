<?php

namespace App\Modules\Meetings\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Meetings\Http\Requests\UpdateMeetingSettingsRequest;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Meetings\Models\MeetingSettings;
use Illuminate\Http\JsonResponse;

class MeetingSettingsController extends Controller
{
    public function show(): JsonResponse
    {
        $this->authorize('viewAny', Meeting::class);

        return response()->json([
            'data' => MeetingSettings::forOrganization(auth()->user()->organization_id),
        ]);
    }

    public function update(UpdateMeetingSettingsRequest $request): JsonResponse
    {
        $this->authorize('create', Meeting::class);

        $settings = MeetingSettings::forOrganization(auth()->user()->organization_id);
        $settings->update($request->validated());

        return response()->json([
            'message' => 'تم حفظ إعدادات الاجتماعات بنجاح',
            'data' => $settings->fresh(),
        ]);
    }
}
