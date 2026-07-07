<?php

namespace App\Modules\OVR\Http\Requests;

use App\Modules\OVR\Enums\SeverityLevel;
use App\Modules\OVR\Models\IncidentType;
use App\Modules\OVR\Models\ReportableType;
use App\Modules\OVR\Services\OvrAuthorizationService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreIncidentReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }

        // Context-aware gate: dept member may only create for their own department;
        // the governing-department member may create for any department. When no
        // reporter_department_id is supplied the user's own department is assumed.
        $departmentId = $this->input('reporter_department_id');

        return app(OvrAuthorizationService::class)->canCreate(
            $user,
            $departmentId !== null && $departmentId !== '' ? (int) $departmentId : null
        );
    }

    public function rules(): array
    {
        return [
            // Optional: governing-dept members may target a specific department.
            // For all others, the controller pins it to the reporter's own department.
            'reporter_department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'incident_datetime' => ['required', 'date', 'before_or_equal:now'],
            'is_patient_related' => ['required', 'boolean'],
            'patient_name' => ['nullable', 'string', 'max:255', 'required_if:is_patient_related,true'],
            'patient_file_number' => ['nullable', 'string', 'max:1000'],
            'patient_gender' => ['nullable', Rule::in(['male', 'female', 'unspecified'])],
            'patient_dob' => ['nullable', 'date'],
            'informed_authority' => ['required', 'boolean'],
            'incident_type_id' => ['required', 'string', 'uuid', 'exists:ovr_incident_types,id'],
            'reportable_incident_type_id' => ['nullable', 'string', 'uuid', 'exists:ovr_reportable_types,id'],
            'incident_description' => ['required', 'string'],
            'actions_taken' => ['nullable', 'string'],
            'contributing_factors' => ['nullable', 'array'],
            'immediate_action_required' => ['required', 'boolean'],
            'severity_level' => ['required', Rule::enum(SeverityLevel::class)],
            'is_confidential' => ['required', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->hasAny(['incident_type_id', 'reportable_incident_type_id'])) {
                return;
            }

            $incidentType = IncidentType::find($this->input('incident_type_id'));

            if (! $incidentType) {
                return;
            }

            $reportableId = $this->input('reportable_incident_type_id');

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

    public function attributes(): array
    {
        return [
            'incident_datetime' => 'تاريخ ووقت الحادثة',
            'is_patient_related' => 'متعلقة بمريض',
            'patient_name' => 'اسم المريض',
            'patient_file_number' => 'رقم ملف المريض',
            'incident_type_id' => 'نوع الحادثة',
            'reportable_incident_type_id' => 'النوع الفرعي للحادثة',
            'incident_description' => 'وصف الحادثة',
            'severity_level' => 'مستوى الخطورة',
        ];
    }
}
