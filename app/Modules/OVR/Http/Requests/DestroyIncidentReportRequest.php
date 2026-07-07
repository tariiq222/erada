<?php

namespace App\Modules\OVR\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * DestroyIncidentReportRequest - authorize + validate deletion of an incident report.
 *
 * Authorization is delegated to IncidentReportPolicy::delete() (engine-first,
 * OVR_DELETE/OVR_DELETE_ALL capability path). No validation rules apply on
 * DELETE; the body is ignored by Laravel for non-form payloads.
 */
class DestroyIncidentReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $report = $this->route('report');

        return $this->user()->can('delete', $report);
    }

    public function rules(): array
    {
        return [];
    }
}
