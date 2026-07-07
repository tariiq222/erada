<?php

namespace App\Modules\OVR\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\OVR\Enums\ReportStatus;
use App\Modules\OVR\Models\IncidentReport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * UpdateStatusRequest - transition an OVR incident report between states.
 *
 * Authorization routes through the unified engine (Capability::OVR_CHANGE_STATUS)
 * on the route-bound IncidentReport so per-report state-guard / org floor /
 * confidential layer all run, replacing the legacy flat Spatie lookup.
 *
 * Side-effect field validation (P1 audit fix):
 *   The controller only consumes `assigned_to` when transitioning to InProgress,
 *   `closure_reason` when transitioning to Closed, and `reopen_reason` is not
 *   wired yet. Sending these fields with an unrelated status was silently
 *   dropped. The conditional rules below reject inconsistent combinations at
 *   the validation layer so the API surface is honest about what is and is
 *   not accepted.
 */
class UpdateStatusRequest extends FormRequest
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

        return AccessDecision::can($user, Capability::OVR_CHANGE_STATUS, $report);
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(ReportStatus::class)],
            'reason' => ['nullable', 'string'],
            // assigned_to is only meaningful when moving to InProgress. Sending it
            // alongside a Closed / Rejected target is rejected so the client gets a
            // clear 422 instead of a silent drop on the floor.
            'assigned_to' => [
                'nullable',
                'integer',
                'exists:users,id',
                Rule::prohibitedIf(
                    fn () => $this->input('status') === ReportStatus::Closed->value
                        || $this->input('status') === ReportStatus::Rejected->value
                ),
            ],
            // closure_reason: required when transitioning to Closed; rejected on any
            // other status so the field cannot be smuggled in alongside a non-closure
            // transition (paired with the audit-required min:5).
            'closure_reason' => [
                'nullable',
                'string',
                'min:5',
                Rule::requiredIf(fn () => $this->input('status') === ReportStatus::Closed->value),
                Rule::prohibitedIf(fn () => $this->input('status') !== ReportStatus::Closed->value),
            ],
            // reopen_reason: no dedicated reopen action exists yet (ponytail — the
            // controller writes reopened_at/by were removed in the P0 #6 hardening),
            // so the field stays nullable. Once a real reopen path lands, this
            // becomes required_if transitioning from Closed/Archived.
            'reopen_reason' => ['nullable', 'string'],
        ];
    }
}
