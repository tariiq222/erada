<?php

namespace App\Modules\OVR\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\OVR\Models\IncidentReport;
use Illuminate\Foundation\Http\FormRequest;

/**
 * StoreCommentRequest - post a comment on an OVR incident report.
 *
 * Authorization routes through the unified engine (Capability::OVR_COMMENT) on
 * the route-bound IncidentReport so per-report confidentiality / org isolation
 * is enforced, not the legacy flat Spatie permission check.
 */
class StoreCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }

        $report = $this->route('report');
        if (! $report instanceof IncidentReport) {
            return false;
        }

        return AccessDecision::can($user, Capability::OVR_COMMENT, $report);
    }

    public function rules(): array
    {
        return [
            'text' => ['required', 'string'],
            'is_internal' => ['sometimes', 'boolean'],
        ];
    }
}
