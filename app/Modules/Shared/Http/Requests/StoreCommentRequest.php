<?php

namespace App\Modules\Shared\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Models\Task;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Form request for creating a comment on a Task or Project.
 *
 * Authz flows through AccessDecision::can on the commentable target so that
 * organization isolation, owner-floor and scope-chain semantics are all
 * enforced by the unified engine — no Spatie, no controller-level fallback.
 */
class StoreCommentRequest extends FormRequest
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

        $type = $this->input('commentable_type');
        $id = $this->input('commentable_id');

        if (! is_string($type) || ! is_numeric($id)) {
            return false;
        }

        $model = match ($type) {
            'task' => Task::find((int) $id),
            'project' => Project::find((int) $id),
            default => null,
        };

        if ($model === null) {
            // The commentable doesn't exist — let the controller's `findOrFail`
            // surface a 404 rather than masking it as 403 here.
            return true;
        }

        return AccessDecision::can($user, Capability::COMMENTS_CREATE, $model);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $user = $this->user();

        $mentionedExists = Rule::exists('users', 'id');
        if ($user !== null && ! $user->isSuperAdmin() && $user->organization_id !== null) {
            $mentionedExists = $mentionedExists->where('organization_id', $user->organization_id);
        }

        return [
            'commentable_type' => [
                'required',
                'string',
                Rule::in(['project', 'task']),
            ],
            'commentable_id' => ['required', 'integer', 'min:1'],
            'content' => ['required', 'string', 'min:1', 'max:5000'],
            'parent_id' => ['nullable', 'exists:comments,id'],
            'mentioned_users' => ['nullable', 'array'],
            'mentioned_users.*' => ['integer', $mentionedExists],
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*' => ['file', 'mimes:pdf,jpg,jpeg,png,doc,docx,xls,xlsx,txt', 'max:10240'], // 10MB max per file
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'commentable_type' => __('validation.attributes.type'),
            'commentable_id' => __('validation.attributes.id'),
            'content' => __('validation.attributes.content'),
            'parent_id' => __('validation.attributes.parent_comment'),
            'mentioned_users' => __('validation.attributes.mentioned_users'),
            'attachments' => __('validation.attributes.attachments'),
        ];
    }

    /**
     * Get custom error messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'content.required' => __('validation.messages.comment_content_required'),
            'content.max' => __('validation.messages.comment_content_max'),
            'attachments.max' => __('validation.messages.max_attachments'),
            'attachments.*.max' => __('validation.messages.attachment_too_large'),
        ];
    }
}
