<?php

namespace App\Modules\Surveys\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Surveys\Models\DataImportRequest;
use Illuminate\Foundation\Http\FormRequest;

/**
 * RejectDataImportRequest - reject a single survey data-import request.
 *
 * Authorization routes through the unified engine (SURVEYS_REVIEW_RESPONSES)
 * on the route-bound DataImportRequest so per-import org isolation is
 * enforced, not the legacy controller-only org-floor super_admin bypass.
 */
class RejectDataImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }

        $request = $this->route('request');
        if (! $request instanceof DataImportRequest) {
            return false;
        }

        return AccessDecision::can($user, Capability::SURVEYS_REVIEW_RESPONSES, $request);
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
