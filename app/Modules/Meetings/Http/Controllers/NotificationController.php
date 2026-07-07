<?php

namespace App\Modules\Meetings\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class NotificationController extends Controller
{
    /** Notification class basenames that the frontend may filter by. */
    private const ALLOWED_TYPES = [
        'MeetingScheduledNotification',
        'MeetingReminderNotification',
        'DecisionApprovedNotification',
        'RecommendationAssignedNotification',
    ];

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'type' => ['sometimes', 'string', Rule::in(self::ALLOWED_TYPES)],
        ]);

        $query = $request->user()->notifications();
        if ($request->boolean('unread_only')) {
            $query->whereNull('read_at');
        }
        if ($type = $request->string('type')->toString()) {
            $query->where('type', 'like', '%'.$type.'%');
        }
        $perPage = min((int) $request->get('per_page', 15), 100);

        return response()->json($query->paginate($perPage));
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'unread' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    public function markRead(Request $request, string $id): JsonResponse
    {
        $notification = $request->user()
            ->notifications()
            ->where('id', $id)
            ->firstOrFail();
        $notification->markAsRead();

        return response()->json(['message' => 'تم وضع علامة مقروء']);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return response()->json(['message' => 'تم وضع علامة مقروء على الكل']);
    }
}
