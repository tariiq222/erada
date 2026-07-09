<?php

namespace App\Modules\Strategy\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Projects\Models\Project;
use App\Modules\Shared\Traits\HasOrganizationScope;
use App\Modules\Strategy\Http\Requests\DeleteReviewRequest;
use App\Modules\Strategy\Http\Requests\ListReviewsRequest;
use App\Modules\Strategy\Http\Requests\StoreReviewRequest;
use App\Modules\Strategy\Http\Requests\UpdateReviewRequest;
use App\Modules\Strategy\Http\Requests\ViewReviewRequest;
use App\Modules\Strategy\Models\Program;
use App\Modules\Strategy\Models\Review;
use App\Modules\Strategy\Models\StrategicObjective;
use App\Modules\Strategy\Scopes\UserStrategyScope;
use Illuminate\Http\JsonResponse;

class ReviewController extends Controller
{
    use HasOrganizationScope;

    /**
     * التحقق من صلاحية الوصول للاستراتيجية
     */
    protected function authorizeStrategy(string $ability = 'view'): void
    {
        $user = auth()->user();

        if ($user?->isSuperAdmin()) {
            return;
        }

        $capability = match ($ability) {
            'create' => Capability::STRATEGY_CREATE,
            'update' => Capability::STRATEGY_EDIT,
            'delete' => Capability::STRATEGY_DELETE,
            default => Capability::STRATEGY_VIEW,
        };

        if (! $user || ! AccessDecision::can($user, $capability)) {
            abort(403, 'ليس لديك صلاحية الوصول');
        }
    }

    /**
     * Display a listing of reviews.
     */
    public function index(ListReviewsRequest $request): JsonResponse
    {
        // Authz (STRATEGY_VIEW) owned by ListReviewsRequest.
        // Phase 9-D-D1b: cluster_tree read widening via UserStrategyScope.

        $query = Review::query()
            ->with(['conductor:id,name']);

        $user = auth()->user();
        if (! $user) {
            abort(401);
        }

        app(UserStrategyScope::class)->applyToReviews($query, $user);

        // Filter by reviewable type
        if ($request->has('type') && $request->has('id')) {
            $query->where('reviewable_type', $this->getModelClass($request->type))
                ->where('reviewable_id', $request->id);
        }

        // Filter by review type
        if ($request->has('review_type') && $request->review_type) {
            $query->where('type', $request->review_type);
        }

        // Filter by PDCA phase
        if ($request->has('pdca_phase') && $request->pdca_phase) {
            $query->where('pdca_phase', $request->pdca_phase);
        }

        // Filter by overall status
        if ($request->has('overall_status') && $request->overall_status) {
            $query->where('overall_status', $request->overall_status);
        }

        $reviews = $query->orderBy('review_date', 'desc')
            ->paginate(min((int) $request->get('per_page', 15), 100));

        // Add computed fields
        $reviews->getCollection()->transform(function ($review) {
            $review->type_label = $review->type_label;
            $review->pdca_phase_label = $review->pdca_phase_label;
            $review->overall_status_label = $review->overall_status_label;

            return $review;
        });

        return response()->json($reviews);
    }

    /**
     * Store a newly created review.
     */
    public function store(StoreReviewRequest $request): JsonResponse
    {
        $validated = $request->validated();

        // Verify the reviewable exists
        $modelClass = $this->getModelClass($validated['reviewable_type']);
        $reviewable = $modelClass::find($validated['reviewable_id']);

        if (! $reviewable) {
            return response()->json([
                'message' => 'العنصر المرتبط غير موجود',
            ], 422);
        }

        $this->assertSameOrganization($reviewable);

        // Org-isolation floor: a review with a null organization_id becomes
        // invisible to every org-scoped query, so refuse to save it instead
        // of creating an orphan. Validation-style 422.
        if ($reviewable->organization_id === null) {
            return response()->json([
                'message' => 'لا يمكن إنشاء مراجعة مرتبطة بعنصر غير مرتبط بمؤسسة',
                'errors' => ['reviewable_id' => ['العنصر المرتبط يجب أن يكون مرتبطًا بمؤسسة']],
            ], 422);
        }

        $validated['reviewable_type'] = $modelClass;
        $validated['conducted_by'] = auth()->id();
        $validated['overall_status'] = $validated['overall_status'] ?? 'on_track';

        // Capture progress snapshot (مع التحقق من النوع والحدود)
        $progress = 0;
        if (method_exists($reviewable, 'calculateProgress')) {
            $progress = (float) $reviewable->calculateProgress();
        } elseif (isset($reviewable->progress) && is_numeric($reviewable->progress)) {
            $progress = (float) $reviewable->progress;
        }
        $validated['progress_snapshot'] = max(0, min(100, $progress));

        $review = Review::create($validated);
        $review->forceFill(['organization_id' => $reviewable->organization_id])->save();

        return response()->json([
            'message' => 'تم إنشاء المراجعة بنجاح',
            'review' => $review->load('conductor:id,name'),
        ], 201);
    }

    /**
     * Display the specified review.
     */
    public function show(ViewReviewRequest $request, Review $review): JsonResponse
    {
        // Authz (STRATEGY_VIEW on review) + org-isolation floor owned by
        // ViewReviewRequest.

        $review->load(['conductor:id,name', 'reviewable']);

        $review->type_label = $review->type_label;
        $review->pdca_phase_label = $review->pdca_phase_label;
        $review->overall_status_label = $review->overall_status_label;

        return response()->json($review);
    }

    /**
     * Update the specified review.
     */
    public function update(UpdateReviewRequest $request, Review $review): JsonResponse
    {
        $this->assertSameOrganization($review);

        $validated = $request->validated();

        $review->update($validated);

        return response()->json([
            'message' => 'تم تحديث المراجعة بنجاح',
            'review' => $review->fresh()->load('conductor:id,name'),
        ]);
    }

    /**
     * Remove the specified review.
     */
    public function destroy(DeleteReviewRequest $request, Review $review): JsonResponse
    {
        // Authz (STRATEGY_DELETE on review) + org-isolation floor owned by
        // DeleteReviewRequest.

        $review->delete();

        return response()->json([
            'message' => 'تم حذف المراجعة بنجاح',
        ]);
    }

    /**
     * Get the model class for a reviewable type.
     */
    private function getModelClass(string $type): string
    {
        return match ($type) {
            'objective' => StrategicObjective::class,
            'program' => Program::class,
            'project' => Project::class,
            default => throw new \InvalidArgumentException('نوع العنصر غير صالح'),
        };
    }
}
