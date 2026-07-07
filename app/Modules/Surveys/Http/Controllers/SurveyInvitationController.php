<?php

namespace App\Modules\Surveys\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Surveys\Enums\InvitationStatus;
use App\Modules\Surveys\Http\Controllers\Concerns\AuthorizesSurveyAccess;
use App\Modules\Surveys\Http\Requests\BulkCreateSurveyInvitationsRequest;
use App\Modules\Surveys\Http\Requests\DestroySurveyInvitationRequest;
use App\Modules\Surveys\Http\Requests\ListSurveyInvitationsRequest;
use App\Modules\Surveys\Http\Requests\ResendSurveyInvitationRequest;
use App\Modules\Surveys\Http\Requests\RevokeSurveyInvitationRequest;
use App\Modules\Surveys\Http\Requests\StoreSurveyInvitationRequest;
use App\Modules\Surveys\Models\Survey;
use App\Modules\Surveys\Models\SurveyInvitation;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class SurveyInvitationController extends Controller
{
    use AuthorizesSurveyAccess;

    public function index(ListSurveyInvitationsRequest $request, Survey $survey): JsonResponse
    {
        // Authz (SURVEYS_VIEW on survey) owned by ListSurveyInvitationsRequest.
        $query = $survey->invitations()
            ->with(['user', 'response'])
            ->latest();

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $invitations = $query->paginate(min((int) $request->input('per_page', 15), 100));

        return response()->json($invitations);
    }

    public function store(StoreSurveyInvitationRequest $request, Survey $survey): JsonResponse
    {
        $validated = $request->validated();

        $invitation = $survey->invitations()->create([
            ...$validated,
            'token' => Str::uuid()->toString(),
            'status' => InvitationStatus::Active,
            'created_by' => auth()->id(),
        ]);

        return response()->json([
            'data' => $invitation,
            'message' => 'تم إنشاء الدعوة بنجاح',
            'url' => config('app.url').'/surveys/invitation/'.$invitation->token,
        ], 201);
    }

    public function bulkCreate(BulkCreateSurveyInvitationsRequest $request, Survey $survey): JsonResponse
    {
        // Authz + payload validation owned by BulkCreateSurveyInvitationsRequest.
        $validated = $request->validated();

        $created = [];

        foreach ($validated['invitations'] as $invitationData) {
            $invitation = $survey->invitations()->create([
                ...$invitationData,
                'token' => Str::uuid()->toString(),
                'status' => InvitationStatus::Active,
                'expires_at' => $validated['expires_at'] ?? null,
                'max_uses' => $validated['max_uses'] ?? null,
                'created_by' => auth()->id(),
            ]);

            $created[] = [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'token' => $invitation->token,
                'url' => config('app.url').'/surveys/invitation/'.$invitation->token,
            ];
        }

        return response()->json([
            'data' => $created,
            'message' => 'تم إنشاء '.count($created).' دعوة بنجاح',
        ], 201);
    }

    public function resend(ResendSurveyInvitationRequest $request, Survey $survey, SurveyInvitation $invitation): JsonResponse
    {
        // Authz + cross-row checks (invitation-belongs-to-survey,
        // invitation-canUse) owned by ResendSurveyInvitationRequest — they
        // surface as 404 / 403 directly from authorize().

        $invitation->update([
            'reminded_at' => now(),
            'reminder_count' => $invitation->reminder_count + 1,
        ]);

        // TODO: إرسال البريد الإلكتروني

        return response()->json([
            'message' => 'تم إعادة إرسال الدعوة بنجاح',
        ]);
    }

    public function destroy(DestroySurveyInvitationRequest $request, Survey $survey, SurveyInvitation $invitation): JsonResponse
    {
        // Authz + scope + safety checks enforced inside DestroySurveyInvitationRequest.
        $invitation->delete();

        return response()->json([
            'message' => 'تم حذف الدعوة بنجاح',
        ]);
    }

    public function revoke(RevokeSurveyInvitationRequest $request, Survey $survey, SurveyInvitation $invitation): JsonResponse
    {
        // Authz + invitation-belongs-to-survey check owned by
        // RevokeSurveyInvitationRequest.

        $invitation->revoke();

        return response()->json([
            'message' => 'تم إلغاء الدعوة بنجاح',
        ]);
    }
}
