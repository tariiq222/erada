<?php

namespace App\Modules\OVR\Http\Resources;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Shared\Support\ElementAbilities;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

class IncidentReportResource extends JsonResource
{
    private const MODE_SUMMARY = 'summary';

    private const MODE_DETAIL = 'detail';

    public function __construct($resource, private readonly string $mode = self::MODE_DETAIL)
    {
        parent::__construct($resource);
    }

    public static function summary($resource): self
    {
        return new self($resource, self::MODE_SUMMARY);
    }

    public static function detail($resource): self
    {
        return new self($resource, self::MODE_DETAIL);
    }

    public static function summaryCollection($resource): AnonymousResourceCollection
    {
        return self::collection($resource)->additional(['privacy_mode' => self::MODE_SUMMARY]);
    }

    public function toArray(Request $request): array
    {
        if ($this->resource instanceof self) {
            return $this->resource->toArray($request);
        }

        // MODE_SUMMARY shape — list / dashboard surfaces (index, recent). Carries
        // only metadata needed for badges, filters, navigation. Patient PII and
        // incident narrative MUST NOT leak here (P2 #13 — code-quality audit).
        $summary = [
            'id' => $this->id,
            'report_number' => $this->report_number,
            'organization_id' => $this->organization_id,
            'reporter_id' => $this->reporter_id,
            'reporter_name' => $this->reporter_name,
            'reporter_department_id' => $this->reporter_department_id,
            'incident_datetime' => $this->incident_datetime?->toDateTimeString(),
            'is_patient_related' => $this->is_patient_related,
            'informed_authority' => $this->informed_authority,
            'incident_type_id' => $this->incident_type_id,
            'reportable_incident_type_id' => $this->reportable_incident_type_id,
            'immediate_action_required' => $this->immediate_action_required,
            'severity_level' => $this->severity_level?->value,
            'status' => $this->status?->value,
            'assigned_to' => $this->assigned_to,
            'due_date' => $this->due_date?->toDateTimeString(),
            'resolved_at' => $this->resolved_at?->toDateTimeString(),
            'closed_at' => $this->closed_at?->toDateTimeString(),
            'is_confidential' => $this->is_confidential,
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
            // Relations
            'reporter' => $this->whenLoaded('reporter', fn () => [
                'id' => $this->reporter->id,
                'name' => $this->reporter->name,
            ]),
            'incident_type' => $this->whenLoaded('incidentType', fn () => [
                'id' => $this->incidentType->id,
                'name' => $this->incidentType->name,
                'name_ar' => $this->incidentType->name_ar,
            ]),
            'reportable_type' => $this->whenLoaded('reportableType', fn () => [
                'id' => $this->reportableType->id,
                'name' => $this->reportableType->name,
                'name_ar' => $this->reportableType->name_ar,
            ]),
            'assignee' => $this->whenLoaded('assignee', fn () => [
                'id' => $this->assignee->id,
                'name' => $this->assignee->name,
            ]),
            'comments_count' => $this->whenCounted('comments'),
            'status_history_count' => $this->whenCounted('statusHistory'),
        ];

        if ($this->mode === self::MODE_SUMMARY) {
            return $summary;
        }

        // MODE_DETAIL only — single-report / show endpoints. Patient PII and
        // incident narrative are gated on the detail mode so list endpoints and
        // dashboard widgets never carry patient identifiers or free-text fields
        // that may contain them (P2 #13).
        return array_merge($summary, [
            'privacy_mode' => self::MODE_DETAIL,
            'reporter_email' => $this->reporter_email,
            'reporter_extension' => $this->reporter_extension,
            'reporter_job_title' => $this->reporter_job_title,
            'reporter_section_id' => $this->reporter_section_id,
            'incident_description' => $this->incident_description,
            'actions_taken' => $this->actions_taken,
            'contributing_factors' => $this->contributing_factors,
            'closure_reason' => $this->closure_reason,
            'reopened_at' => $this->reopened_at?->toDateTimeString(),
            'reopened_by' => $this->reopened_by,
            'reopen_reason' => $this->reopen_reason,
            'assigned_at' => $this->assigned_at?->toDateTimeString(),
            'closed_by' => $this->closed_by,
            'patient_name' => $this->patient_name,
            'patient_file_number' => $this->patient_file_number,
            'patient_gender' => $this->patient_gender,
            'patient_dob' => $this->patient_dob?->toDateString(),

            // Per-record abilities — resolved through AccessDecision so the
            // frontend never re-derives scope-chain logic.
            'abilities' => ElementAbilities::resolve(
                $request?->user() ?? request()->user(),
                $this->resource,
                [
                    'view' => Capability::OVR_VIEW,
                    'edit' => Capability::OVR_EDIT,
                    'investigate' => Capability::OVR_INVESTIGATE,
                    'close' => Capability::OVR_CLOSE,
                    'assign' => Capability::OVR_ASSIGN,
                ]
            ),
        ]);
    }
}
