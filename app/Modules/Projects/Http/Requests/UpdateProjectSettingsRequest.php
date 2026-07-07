<?php

namespace App\Modules\Projects\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation/authorization for PUT /api/projects/settings.
 *
 * Authorizes against SETTINGS_EDIT via the engine (AccessDecision::can) — the
 * raw capability is the right gate here (no target model). Mirrors the same
 * `default_status` / attachment-whitelist rules the controller previously
 * enforced inline.
 */
class UpdateProjectSettingsRequest extends FormRequest
{
    /**
     * Project status values allowed by the projects module. Single source of
     * truth for both the validation rule and the ProjectController's settings
     * endpoint — if this set changes, update PROJECT_STATUS_VALUES in the
     * controller too.
     */
    private const PROJECT_STATUS_VALUES = 'draft,planning,in_progress,on_hold,completed,cancelled';

    /**
     * Safe attachment file-type whitelist. Union of the comment-attachment
     * whitelist (pdf,jpg,jpeg,png,doc,docx,xls,xlsx,txt — see AGENTS.md and
     * StoreCommentRequest) and the project's existing default list (gif —
     * see ProjectSettingsService::$defaultProjectSettings).
     */
    private const ALLOWED_ATTACHMENT_TYPES = 'pdf,jpg,jpeg,png,doc,docx,xls,xlsx,txt,gif';

    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && AccessDecision::can($user, Capability::SETTINGS_EDIT);
    }

    public function rules(): array
    {
        return [
            'project' => ['sometimes', 'array'],
            'project.default_status' => ['sometimes', 'string', 'in:'.self::PROJECT_STATUS_VALUES],

            'attachments' => ['sometimes', 'array'],
            'attachments.max_size_mb' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'attachments.allowed_types' => ['sometimes', 'array'],
            'attachments.allowed_types.*' => ['string', 'in:'.self::ALLOWED_ATTACHMENT_TYPES],
        ];
    }
}
