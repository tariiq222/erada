<?php

namespace App\Modules\Surveys\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Surveys\Enums\ResponseStatus;
use App\Modules\Surveys\Http\Controllers\Concerns\AuthorizesSurveyAccess;
use App\Modules\Surveys\Http\Resources\SurveyResponseResource;
use App\Modules\Surveys\Models\Survey;
use App\Modules\Surveys\Models\SurveyResponse;
use App\Modules\Surveys\Policies\SurveyResponsePolicy;
use App\Modules\Surveys\Scopes\UserSurveyScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;

class SurveyResponseController extends Controller
{
    use AuthorizesSurveyAccess;

    public function index(Request $request, Survey $survey): AnonymousResourceCollection
    {
        if (! AccessDecision::can($request->user(), Capability::SURVEYS_REVIEW_RESPONSES)) {
            abort(403, 'لا تملك صلاحية مراجعة ردود الاستبيانات');
        }
        $this->authorizeSurvey($request, $survey);
        $query = $survey->responses()
            ->with(['answers.field'])
            ->latest('submitted_at');

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('from')) {
            $query->whereDate('submitted_at', '>=', $request->from);
        }

        if ($request->has('to')) {
            $query->whereDate('submitted_at', '<=', $request->to);
        }

        $responses = $query->paginate(min((int) $request->input('per_page', 15), 100));

        return SurveyResponseResource::collection($responses);
    }

    public function show(Request $request, Survey $survey, SurveyResponse $response): SurveyResponseResource
    {
        if (! AccessDecision::can($request->user(), Capability::SURVEYS_REVIEW_RESPONSES)) {
            abort(403, 'لا تملك صلاحية مراجعة ردود الاستبيانات');
        }
        $this->authorizeSurvey($request, $survey);
        if ($response->survey_id !== $survey->id) {
            abort(404, 'الإجابة غير موجودة في هذا الاستبيان');
        }

        $response->load(['answers.field', 'answers.files', 'invitation', 'reviewer']);

        return new SurveyResponseResource($response);
    }

    public function flag(Request $request, Survey $survey, SurveyResponse $response): JsonResponse
    {
        $this->authorizeSurvey($request, $survey);
        $this->authorize('review', $response);
        if ($response->survey_id !== $survey->id) {
            return response()->json([
                'message' => 'الإجابة غير موجودة في هذا الاستبيان',
            ], 404);
        }

        $validated = $request->validate([
            'notes' => 'required|string|max:1000',
        ]);

        $response->update([
            'status' => ResponseStatus::Flagged,
            'reviewed_at' => now(),
            'reviewed_by' => auth()->id(),
            'reviewer_notes' => $validated['notes'],
        ]);

        return response()->json([
            'message' => 'تم تنبيه الإجابة بنجاح',
            'data' => new SurveyResponseResource($response),
        ]);
    }

    public function review(Request $request, Survey $survey, SurveyResponse $response): JsonResponse
    {
        $this->authorizeSurvey($request, $survey);
        $this->authorize('review', $response);
        if ($response->survey_id !== $survey->id) {
            return response()->json([
                'message' => 'الإجابة غير موجودة في هذا الاستبيان',
            ], 404);
        }

        $validated = $request->validate([
            'status' => 'required|in:submitted,invalid,flagged',
            'notes' => 'nullable|string|max:1000',
        ]);

        $response->update([
            'status' => ResponseStatus::from($validated['status']),
            'reviewed_at' => now(),
            'reviewed_by' => auth()->id(),
            'reviewer_notes' => $validated['notes'] ?? $response->reviewer_notes,
        ]);

        return response()->json([
            'message' => 'تم مراجعة الإجابة بنجاح',
            'data' => new SurveyResponseResource($response),
        ]);
    }

    /**
     * Phase CFA-10 — Cluster aggregate stats (NEVER raw responses).
     *
     * Returns per-descendant-organization aggregate counts for one survey:
     *   - response_count: count of survey_responses belonging to surveys in
     *     that organization (NOT individual response rows — only counts).
     *   - completion_rate: ratio of submitted responses to all responses
     *     (draft + submitted + flagged). Computed as a percentage in 0..100.
     *   - aggregate_score: mean completion_time in seconds (the only numeric
     *     metric available across all field types — kept stable to avoid
     *     inventing a new column).
     *
     * The aggregate row per organization does NOT include any of:
     *   - individual survey_responses (no raw PII)
     *   - respondent_email / respondent_phone (NEVER exposed)
     *   - survey_invitations.email (NEVER exposed)
     *   - public tracking token URLs (NEVER touched by this endpoint)
     *
     * Authz (two-path rescue via SurveyResponsePolicy::viewStats):
     *   - Same-org SURVEYS_VIEW, OR
     *   - Cross-org SURVEYS_VIEW + CLUSTER_TREE_VIEW (cluster ancestor walk).
     *   - super_admin bypasses everything.
     *   - null-org actor ⇒ 403 (fail-closed).
     *
     * The widening is read-only — never enable raw response reads, per-
     * response review, flag/review mutations, or write paths.
     */
    public function clusterStats(Request $request, Survey $survey): JsonResponse
    {
        $user = $request->user();

        // Defensive null-floor before the policy call. Mirrors the
        // null-org fail-closed floor on SurveyPolicy::view.
        abort_if($user === null, 401);

        // viewStats lives on SurveyResponsePolicy (per CFA-10 spec). The
        // input model is a Survey, but the policy must NOT be moved to
        // SurveyPolicy (the spec keeps it on the response-side policy so
        // the cluster widening for stats stays decoupled from raw
        // survey metadata view()). Invoke the policy class explicitly
        // because Gate resolves the policy by the model class — and
        // Survey maps to SurveyPolicy by default, not SurveyResponsePolicy.
        abort_unless(
            (new SurveyResponsePolicy)->viewStats($user, $survey),
            403,
            'لا تملك صلاحية عرض إحصائيات الاستبيان'
        );

        $scope = app(UserSurveyScope::class);
        $visibleOrgIds = $scope->clusterVisibleOrgIds($user);

        // Build one aggregate row per visible organization. Single
        // grouped query, no raw row materialization.
        $rows = $this->aggregateRowsForSurvey($survey, $visibleOrgIds);

        return response()->json([
            'survey_id' => $survey->id,
            'survey_code' => $survey->code,
            'survey_title' => $survey->title,
            'cluster_root_org_id' => $user->organization_id,
            'aggregates' => $rows,
        ]);
    }

    /**
     * Phase CFA-10 — Cluster aggregate-only export (NEVER raw responses).
     *
     * Returns the same aggregate shape as clusterStats(), but serialized
     * as CSV / JSON file. Gated by Capability::SURVEYS_EXPORT +
     * Capability::CLUSTER_TREE_EXPORT on actor.org for cross-org widening
     * (paired grant, like KPIS_EXPORT + CLUSTER_TREE_EXPORT). Same-org
     * SURVEYS_EXPORT (without cluster_tree) returns the same-org aggregate
     * row only.
     *
     * Strict aggregate-only contract (NO raw widening, NO PII leaks):
     *   - Only response_count / completion_rate / aggregate_score per org.
     *   - NO respondent_email, NO respondent_phone, NO survey_invitations.email.
     *   - NO public tracking token URLs.
     *
     * The raw response export endpoint (`GET /api/surveys/{survey}/export`)
     * stays unchanged — it remains strict same-org + SURVEYS_REVIEW_RESPONSES
     * and is never cluster-widened.
     */
    public function clusterExport(Request $request, Survey $survey): JsonResponse
    {
        $user = $request->user();

        abort_if($user === null, 401);

        abort_unless(
            (new SurveyResponsePolicy)->exportStats($user, $survey),
            403,
            'لا تملك صلاحية عرض إحصائيات الاستبيان'
        );

        $format = strtolower((string) $request->query('format', 'csv'));
        if (! in_array($format, ['csv', 'json'], true)) {
            abort(422, 'صيغة التصدير يجب أن تكون csv أو json');
        }

        $scope = app(UserSurveyScope::class);
        $visibleOrgIds = $scope->clusterExportVisibleOrgIds($user);

        $rows = $this->aggregateRowsForSurvey($survey, $visibleOrgIds);

        $payload = [
            'survey_id' => $survey->id,
            'survey_code' => $survey->code,
            'survey_title' => $survey->title,
            'cluster_root_org_id' => $user->organization_id,
            'exported_at' => now()->toISOString(),
            'aggregates' => $rows,
        ];

        $filename = sprintf(
            'survey-%s-cluster-aggregate-%s.%s',
            $survey->code,
            now()->format('Y-m-d-His'),
            $format
        );

        $path = 'exports/'.$filename;

        $body = $format === 'json'
            ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            : $this->renderAggregatesCsv($rows);

        Storage::disk('local')->put($path, $body);

        return response()->json([
            'message' => 'تم تصدير الإحصائيات بنجاح',
            'filename' => basename($path),
            'format' => $format,
            'aggregate_rows' => count($rows),
        ]);
    }

    /**
     * Compute per-org aggregate rows for the survey. Each row contains:
     *   - organization_id
     *   - organization_name
     *   - response_count (count of survey_responses belonging to surveys in this org)
     *   - completion_rate (percentage of submitted responses; 0..100)
     *   - aggregate_score (mean completion_time in seconds for submitted responses)
     *
     * The query is grouped by organization and joined through the survey
     * parent (survey_responses does not carry organization_id directly).
     *
     * @param  list<int>  $visibleOrgIds  the descendant org ids the actor can see
     * @return list<array<string, mixed>>
     */
    protected function aggregateRowsForSurvey(Survey $survey, array $visibleOrgIds): array
    {
        // Super admins pass an empty list to signal an unrestricted aggregate.
        $orgs = $visibleOrgIds === []
            ? Organization::query()->orderBy('id')->get()
            : Organization::query()->whereIn('id', $visibleOrgIds)->orderBy('id')->get();

        $rows = [];
        foreach ($orgs as $org) {
            // User respondents are attributed to their current organization.
            // Anonymous or deleted respondents fall back to the survey owner.
            $responsesQuery = SurveyResponse::query()
                ->leftJoin('users', 'users.id', '=', 'survey_responses.respondent_id')
                ->where('survey_responses.survey_id', $survey->id)
                ->whereRaw('COALESCE(users.organization_id, ?) = ?', [
                    (int) $survey->organization_id,
                    (int) $org->id,
                ]);

            $total = (clone $responsesQuery)->count();

            $submitted = (clone $responsesQuery)
                ->where('survey_responses.status', ResponseStatus::Submitted->value)
                ->count();

            $meanCompletion = (float) (clone $responsesQuery)
                ->where('survey_responses.status', ResponseStatus::Submitted->value)
                ->whereNotNull('survey_responses.completion_time')
                ->avg('survey_responses.completion_time');

            $completionRate = $total > 0
                ? round(($submitted / $total) * 100, 2)
                : 0.0;

            $rows[] = [
                'organization_id' => (int) $org->id,
                'organization_name' => $org->name,
                'response_count' => $total,
                'submitted_count' => $submitted,
                'completion_rate' => $completionRate,
                'aggregate_score' => round($meanCompletion, 2),
            ];
        }

        return $rows;
    }

    /**
     * Render the aggregate rows as CSV. Aggregate-only: response_count,
     * completion_rate, aggregate_score — NO raw responses, NO PII.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    protected function renderAggregatesCsv(array $rows): string
    {
        $output = fopen('php://temp', 'r+');
        // UTF-8 BOM so Excel renders Arabic organization names correctly.
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

        fputcsv($output, [
            'organization_id',
            'organization_name',
            'response_count',
            'submitted_count',
            'completion_rate',
            'aggregate_score',
        ]);

        foreach ($rows as $row) {
            fputcsv($output, [
                $row['organization_id'],
                $row['organization_name'],
                $row['response_count'],
                $row['submitted_count'],
                $row['completion_rate'],
                $row['aggregate_score'],
            ]);
        }

        rewind($output);
        $content = stream_get_contents($output);
        fclose($output);

        return (string) $content;
    }
}
