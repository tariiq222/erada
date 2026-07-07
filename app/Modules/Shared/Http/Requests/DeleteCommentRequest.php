<?php

namespace App\Modules\Shared\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Projects\Models\Project;
use App\Modules\Shared\Models\Comment;
use App\Modules\Tasks\Models\Task;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Form request for deleting a comment.
 *
 * Three gates, identical to UpdateCommentRequest, but using COMMENTS_DELETE:
 *  1. Engine-only parent access — IDOR guard via the commentable.
 *  2. Owner floor.
 *  3. COMMENTS_DELETE capability.
 */
class DeleteCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        $comment = $this->comment();
        if ($comment === null) {
            return false;
        }

        if (! $this->canAccessParent($user, $comment)) {
            return false;
        }

        if ((int) $comment->user_id === (int) $user->id) {
            return true;
        }

        return AccessDecision::can($user, Capability::COMMENTS_DELETE);
    }

    public function rules(): array
    {
        return [];
    }

    /**
     * The route-bound Comment, or null when the binding is missing.
     *
     * Controllers in this module type-hint the param as `string $id` (so the
     * controller can keep its own `findOrFail($id)` 404 path), which means the
     * route only carries the raw id string here. We resolve the Comment
     * ourselves so the engine-driven authz gates below have a hydrated model to
     * inspect — without this fallback the route string would short-circuit the
     * `comment() === null` branch and return 403 before reaching the controller.
     */
    public function comment(): ?Comment
    {
        $routeParam = $this->route('comment');

        if ($routeParam instanceof Comment) {
            return $routeParam;
        }

        if (is_numeric($routeParam)) {
            return Comment::find((int) $routeParam);
        }

        return null;
    }

    private function canAccessParent($user, Comment $comment): bool
    {
        $parent = $comment->commentable;

        if ($parent === null) {
            return false;
        }

        if ($parent instanceof Task) {
            return AccessDecision::can($user, Capability::COMMENTS_VIEW, $parent)
                || AccessDecision::can($user, 'tasks.view', $parent);
        }

        if ($parent instanceof Project) {
            return AccessDecision::can($user, Capability::COMMENTS_VIEW, $parent)
                || AccessDecision::can($user, 'projects.view', $parent);
        }

        return false;
    }
}
