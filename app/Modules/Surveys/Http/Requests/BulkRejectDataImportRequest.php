<?php

namespace App\Modules\Surveys\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use Illuminate\Foundation\Http\FormRequest;

/**
 * BulkRejectDataImportRequest - reject multiple survey data-import requests.
 *
 * Authorization routes through the unified engine (SURVEYS_REVIEW_RESPONSES)
 * at the capability level (no single model target). Per-record org isolation
 * is enforced in the controller via `whereHas('response.survey', org-scope)`.
 */
class BulkRejectDataImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }

        return AccessDecision::can($user, Capability::SURVEYS_REVIEW_RESPONSES);
    }

    public function rules(): array
    {
        return [
            'ids' => ['required', 'array'],
            'ids.*' => ['integer', 'exists:data_import_requests,id'],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
