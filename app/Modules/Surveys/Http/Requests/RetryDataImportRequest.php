<?php

namespace App\Modules\Surveys\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Surveys\Models\DataImportRequest;
use Illuminate\Foundation\Http\FormRequest;

/**
 * RetryDataImportRequest - retry a previously failed survey data-import.
 *
 * Authorization routes through the unified engine (SURVEYS_REVIEW_RESPONSES)
 * on the route-bound DataImportRequest. Body-less state transition; the
 * controller handles retry via lockForUpdate + status->canRetry() guard.
 */
class RetryDataImportRequest extends FormRequest
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
        return [];
    }
}
