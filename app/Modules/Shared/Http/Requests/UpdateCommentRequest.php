<?php

namespace App\Modules\Shared\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Projects\Models\Project;
use App\Modules\Shared\Models\Comment;
use App\Modules\Tasks\Models\Task;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Form request for updating a comment.
 *
 * Authz has three gates, all evaluated before the controller runs:
 *  1. Engine-only parent access: COMMENTS_VIEW on the resolved commentable
 *     (Task or Project) — enforces organization isolation and scope-chain
 *     grants via the unified engine, replacing the controller's legacy
 *     authorizeCommentableParent() IDOR guard.
 *  2. Owner floor: the user is the comment author.
 *  3. COMMENTS_EDIT capability: anyone granted the capability may edit any
 *     comment (mirrors the legacy comment-or-capability decision).
 */
class UpdateCommentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
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

        // Engine-only parent access — replaces the controller's
        // authorizeCommentableParent() IDOR guard. super_admin bypasses in
        // AccessDecision::whyCan() step 1.
        if (! $this->canAccessParent($user, $comment)) {
            return false;
        }

        // Owner OR explicit COMMENTS_EDIT capability.
        if ((int) $comment->user_id === (int) $user->id) {
            return true;
        }

        return AccessDecision::can($user, Capability::COMMENTS_EDIT);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'content' => ['required', 'string', 'min:1', 'max:5000'],
            'mentioned_users' => ['nullable', 'array'],
            'mentioned_users.*' => ['integer', 'exists:users,id'],
        ];
    }

    public function attributes(): array
    {
        return [
            'content' => __('validation.attributes.content'),
            'mentioned_users' => __('validation.attributes.mentioned_users'),
        ];
    }

    public function messages(): array
    {
        return [
            'content.required' => __('validation.messages.comment_content_required'),
            'content.max' => __('validation.messages.comment_content_max'),
        ];
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

    /**
     * Engine-only check that the user can access the comment's parent
     * commentable (Task or Project). Mirrors the legacy
     * authorizeCommentableParent() fail-closed semantics: an orphan comment
     * with no parent is rejected.
     */
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

        // Unknown commentable type — fail closed.
        return false;
    }
}
