<?php

namespace App\Modules\OVR\Http\Requests;

use App\Modules\OVR\Enums\SeverityLevel;
use App\Modules\OVR\Models\IncidentType;
use App\Modules\OVR\Models\ReportableType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateIncidentReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $report = $this->route('report');

        return $this->user()->can('update', $report);
    }

    public function rules(): array
    {
        return [
            'incident_datetime' => ['sometimes', 'date', 'before_or_equal:now'],
            'is_patient_related' => ['sometimes', 'boolean'],
            'patient_name' => ['nullable', 'string', 'max:255'],
            'patient_file_number' => ['nullable', 'string', 'max:1000'],
            'patient_gender' => ['nullable', Rule::in(['male', 'female', 'unspecified'])],
            'patient_dob' => ['nullable', 'date'],
            'informed_authority' => ['sometimes', 'boolean'],
            'incident_type_id' => ['sometimes', 'string', 'uuid', 'exists:ovr_incident_types,id'],
            'reportable_incident_type_id' => ['nullable', 'string', 'uuid', 'exists:ovr_reportable_types,id'],
            'incident_description' => ['sometimes', 'string'],
            'actions_taken' => ['nullable', 'string'],
            'contributing_factors' => ['nullable', 'array'],
            'immediate_action_required' => ['sometimes', 'boolean'],
            'severity_level' => ['sometimes', Rule::enum(SeverityLevel::class)],
            'is_confidential' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (! $this->has('incident_type_id') && ! $this->has('reportable_incident_type_id')) {
                return;
            }

            if ($validator->errors()->hasAny(['incident_type_id', 'reportable_incident_type_id'])) {
                return;
            }

            $report = $this->route('report');

            $incidentTypeId = $this->input('incident_type_id', $report?->incident_type_id);
            $reportableId = $this->has('reportable_incident_type_id')
                ? $this->input('reportable_incident_type_id')
                : $report?->reportable_incident_type_id;

            $incidentType = IncidentType::find($incidentTypeId);

            if (! $incidentType) {
                return;
            }

            if ($incidentType->requires_reportable_type && ! $reportableId) {
                $validator->errors()->add(
                    'reportable_incident_type_id',
                    'النوع الفرعي مطلوب لهذا النوع من الحوادث'
                );

                return;
            }

            if ($reportableId && ! ReportableType::where('id', $reportableId)->where('incident_type_id', $incidentType->id)->exists()) {
                $validator->errors()->add(
                    'reportable_incident_type_id',
                    'النوع الفرعي المحدد لا يتبع نوع الحادثة المختار'
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'incident_datetime.before_or_equal' => 'لا يمكن اختيار تاريخ مستقبلي',
        ];
    }
}
