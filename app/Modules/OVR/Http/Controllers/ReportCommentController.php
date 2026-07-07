<?php

namespace App\Modules\OVR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\User;
use App\Modules\OVR\Http\Requests\DestroyReportCommentRequest;
use App\Modules\OVR\Http\Requests\StoreCommentRequest;
use App\Modules\OVR\Http\Resources\CommentResource;
use App\Modules\OVR\Models\IncidentReport;
use App\Modules\OVR\Models\ReportComment;
use App\Modules\OVR\Notifications\CommentAddedNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportCommentController extends Controller
{
    public function index(Request $request, IncidentReport $report): JsonResponse
    {
        $this->authorize('view', $report);

        $query = $report->comments()->with('user');

        if (! $request->user()->can('viewInternalComments', $report)) {
            $query->where('is_internal', false);
        }

        $comments = $query->orderBy('created_at')->get();

        return response()->json([
            'data' => CommentResource::collection($comments),
        ]);
    }

    public function store(StoreCommentRequest $request, IncidentReport $report): JsonResponse
    {
        $this->authorize('comment', $report);

        $user = $request->user();

        $comment = $report->comments()->create([
            'user_id' => $user->id,
            'author_name' => $user->name,
            'text' => $request->input('text'),
            'is_internal' => $request->boolean('is_internal', false),
        ]);

        // Notify participants (reporter + assignee), excluding the comment author.
        // Internal comments only notify the assigned handler, not the reporter.
        $recipientIds = array_filter([
            $comment->is_internal ? null : $report->reporter_id,
            $report->assigned_to,
        ], fn ($id) => $id && $id !== $user->id);

        if ($recipientIds) {
            $recipients = User::whereIn('id', array_unique($recipientIds))->get();
            foreach ($recipients as $recipient) {
                $recipient->notify(new CommentAddedNotification($report, $comment));
            }
        }

        return response()->json([
            'message' => __('ovr.api.comment_added'),
            'data' => new CommentResource($comment->load('user')),
        ], 201);
    }

    public function destroy(DestroyReportCommentRequest $request, IncidentReport $report, ReportComment $comment): JsonResponse
    {
        // Authorization is handled by DestroyReportCommentRequest::authorize()
        // (comment author OR engine OVR_DELETE_ALL on the parent report).

        $comment->delete();

        return response()->json([
            'message' => __('ovr.api.comment_deleted'),
        ]);
    }
}
