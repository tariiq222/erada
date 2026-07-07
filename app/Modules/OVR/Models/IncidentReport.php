<?php

namespace App\Modules\OVR\Models;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Authorization\Contracts\ScopeAware;
use App\Modules\Core\Authorization\Contracts\SensitivelyScoped;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\OVR\Enums\ReportStatus;
use App\Modules\OVR\Enums\SeverityLevel;
use App\Modules\OVR\Services\OvrAuthorizationService;
use App\Modules\Shared\Traits\LogsActivity;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class IncidentReport extends Model implements ScopeAware, SensitivelyScoped
{
    use HasFactory, HasUuids, LogsActivity, SoftDeletes;

    protected $table = 'ovr_incident_reports';

    protected $fillable = [
        'report_number',
        'organization_id',
        'reporter_id',
        'reporter_name',
        'reporter_email',
        'reporter_extension',
        'reporter_job_title',
        'reporter_department_id',
        'reporter_section_id',
        'incident_datetime',
        'is_patient_related',
        'patient_name',
        'patient_file_number',
        'patient_gender',
        'patient_dob',
        'informed_authority',
        'incident_type_id',
        'reportable_incident_type_id',
        'incident_description',
        'actions_taken',
        'contributing_factors',
        'immediate_action_required',
        'severity_level',
        'status',
        'assigned_to',
        'assigned_at',
        'due_date',
        'sla_notified_at',
        'resolved_at',
        'closed_at',
        'closed_by',
        'closure_reason',
        'reopened_at',
        'reopened_by',
        'reopen_reason',
        'is_confidential',
    ];

    protected $casts = [
        'incident_datetime' => 'datetime',
        'is_patient_related' => 'boolean',
        'patient_dob' => 'date',
        'informed_authority' => 'boolean',
        'contributing_factors' => 'array',
        'immediate_action_required' => 'boolean',
        'severity_level' => SeverityLevel::class,
        'status' => ReportStatus::class,
        'assigned_at' => 'datetime',
        'due_date' => 'datetime',
        'sla_notified_at' => 'datetime',
        'resolved_at' => 'datetime',
        'closed_at' => 'datetime',
        'reopened_at' => 'datetime',
        'is_confidential' => 'boolean',
        // PII — application-level encryption (Laravel encrypted cast)
        'patient_file_number' => 'encrypted',
        'patient_name' => 'encrypted',
    ];

    protected array $trackedFields = [
        'status',
        'severity_level',
        'is_confidential',
        'assigned_to',
        'assigned_at',
        'due_date',
        'resolved_at',
        'closed_at',
        'closed_by',
        'closure_reason',
        'reopened_at',
        'reopened_by',
        'reopen_reason',
    ];

    protected static function booted(): void
    {
        static::creating(function (IncidentReport $report) {
            if (empty($report->report_number)) {
                $report->report_number = static::generateReportNumber();
            }
            if (empty($report->status)) {
                $report->status = ReportStatus::Draft;
            }
        });
    }

    /**
     * Route-model binding resolves incidents by their human-facing report number
     * (e.g. OVR-2026-0001), which is what every API client passes. The UUID primary
     * key is never used in URLs.
     */
    public function getRouteKeyName(): string
    {
        return 'report_number';
    }

    public static function generateReportNumber(): string
    {
        $year = date('Y');
        $prefix = "OVR-{$year}-";

        $last = static::withTrashed()
            ->where('report_number', 'like', $prefix.'%')
            ->orderByRaw('CAST(RIGHT(report_number, 4) AS INTEGER) DESC')
            ->first();

        $next = $last ? intval(substr($last->report_number, -4)) + 1 : 1;

        return $prefix.str_pad($next, 4, '0', STR_PAD_LEFT);
    }

    // ========================================
    // Relations
    // ========================================

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function reporterDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'reporter_department_id');
    }

    public function reporterSection(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'reporter_section_id');
    }

    public function incidentType(): BelongsTo
    {
        return $this->belongsTo(IncidentType::class, 'incident_type_id');
    }

    public function reportableType(): BelongsTo
    {
        return $this->belongsTo(ReportableType::class, 'reportable_incident_type_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function reopener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(ReportComment::class, 'report_id')->orderBy('created_at');
    }

    public function publicComments(): HasMany
    {
        return $this->hasMany(ReportComment::class, 'report_id')
            ->where('is_internal', false)
            ->orderBy('created_at');
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(StatusHistory::class, 'report_id')->orderBy('created_at');
    }

    /**
     * Employees invited as participants to this report (cross-department access).
     */
    public function participants(): HasMany
    {
        return $this->hasMany(IncidentParticipant::class, 'incident_report_id');
    }

    // ========================================
    // Scopes
    // ========================================

    public function scopeForOrganization($query, ?int $organizationId)
    {
        // Null org must not bypass isolation — scope to NULL-org records only.
        return $query->where('organization_id', $organizationId);
    }

    /**
     * Restrict an incident-report query to those the user may see.
     *
     * Additive (OR) visibility — preserves all existing flat-permission paths AND
     * adds engine-based subtree, governing-department (org-wide), and participant access.
     *
     * Organization isolation is applied by the caller (forOrganization).
     * Confidentiality gate is applied separately (AND), unchanged.
     */
    public function scopeVisibleTo($query, User $user)
    {
        $svc = app(OvrAuthorizationService::class);

        // super_admin sees every report via the policy before() hook + the bypass
        // in canViewAny. The bypass is duplicated here so the list filter
        // (scopeVisibleTo is applied directly to SQL and does NOT pass through
        // can()) doesn't strip out the rows a super-admin is entitled to.
        if ($user->isSuperAdmin()) {
            return $query;
        }

        // Organization isolation is a hard floor for every non-super-admin, applied
        // by scopeVisibleTo itself (not left to the caller's forOrganization()). An
        // org-functional role grants within the user's OWN organization only, so an
        // org-level OVR_VIEW grant must never surface another org's reports.
        $query->where($this->getTable().'.organization_id', $user->organization_id);

        // The OVR governing department oversees all reports (equivalent to view_all).
        $governsWholeOrg = $svc->governs($user);

        if (! $governsWholeOrg) {
            // Engine scoped grants: departments where the user holds ovr.view (subtree).
            $engineDeptIds = AccessDecision::subtreeDepartmentIds(
                AccessDecision::grantingScopes($user, Capability::OVR_VIEW)['department'] ?? []
            );

            $query->where(function ($axis) use ($user, $engineDeptIds) {
                // Engine org-level grant: no restriction needed (whole org).
                if (AccessDecision::grantsAtOrganization($user, Capability::OVR_VIEW)) {
                    return;
                }

                $axis->where(function ($branch) use ($user, $engineDeptIds) {
                    // Reporter / assigned relations (flat view_own + baseline).
                    $branch->orWhere('reporter_id', $user->id)
                        ->orWhere('assigned_to', $user->id);

                    // Engine subtree: departments the user governs via scoped roles
                    // (replaces flat ovr.view_department — engine grants already include
                    // the user's own department when a scoped role covers it).
                    if ($engineDeptIds !== []) {
                        $branch->orWhereIn('reporter_department_id', $engineDeptIds);
                    }

                    // Participant invitation: an invited employee sees the report.
                    $branch->orWhereHas('participants', fn ($p) => $p->where('user_id', $user->id));
                });
            });
        }

        // Confidentiality gate (applied as AND). STRICT need-to-know, mirroring
        // mayAccessSensitive(): only an explicit OVR confidential grant (or being
        // the reporter/assignee) unlocks confidential rows. is_admin_role alone does
        // NOT — otherwise an org admin would see confidential reports in the list while
        // the per-record policy (mayAccessSensitive) correctly denies them.
        if (! $this->userMayViewConfidential($user)) {
            $query->where(function ($c) use ($user) {
                $c->where('is_confidential', false)
                    ->orWhere('reporter_id', $user->id)
                    ->orWhere('assigned_to', $user->id);
            });
        }

        return $query;
    }

    /**
     * Does the user hold an explicit OVR confidential grant via any active
     * scoped role? Delegates to OvrAuthorizationService::mayViewConfidential so
     * the LIST confidentiality gate and the per-record gate stay consistent. The
     * list caller also ORs the reporter/assignee floor in SQL; that floor is
     * already covered by the delegated method, but the SQL OR is kept as defense
     * in depth.
     */
    private function userMayViewConfidential(User $user): bool
    {
        return app(OvrAuthorizationService::class)
            ->mayViewConfidential($user, $this);
    }

    public function scopeByStatus($query, ReportStatus $status)
    {
        return $query->where('status', $status);
    }

    public function scopeBySeverity($query, SeverityLevel $severity)
    {
        return $query->where('severity_level', $severity);
    }

    // ========================================
    // Helpers
    // ========================================

    public function isClosed(): bool
    {
        return in_array($this->status, [ReportStatus::Closed, ReportStatus::Archived, ReportStatus::Rejected], true);
    }

    public function canEdit(): bool
    {
        return $this->status->canEdit();
    }

    public function canTransitionTo(ReportStatus $target): bool
    {
        return $this->status->canTransitionTo($target);
    }

    public function calculateDueDate(): ?Carbon
    {
        $hours = $this->severity_level->slaHours();

        return $this->created_at->copy()->addHours($hours);
    }

    public function recordStatusChange(ReportStatus $from, ReportStatus $to, int $userId, ?string $reason = null): StatusHistory
    {
        return $this->statusHistory()->create([
            'from_status' => $from->value,
            'to_status' => $to->value,
            'changed_by' => $userId,
            'reason' => $reason,
        ]);
    }

    // ========== ScopeAware ==========

    public function scopeParent(): ?Model
    {
        // The reporter department is the scope parent. Resolved through the engine
        // (cached by id) so a list of incidents sharing one department does not
        // re-fetch it per record (N+1 fix), mirroring Risk/Project/Task. A fully
        // eager-loaded reporterDepartment relation is reused without a query; a
        // partial (column-projected) load falls through to the engine.
        if ($this->reporter_department_id !== null) {
            if (AccessDecision::scopeParentFullyLoaded($this, 'reporterDepartment')) {
                return $this->getRelation('reporterDepartment');
            }

            return AccessDecision::resolveScopeParent(Department::class, (int) $this->reporter_department_id);
        }

        return null;
    }

    public function scopeTypeKey(): string
    {
        return 'incident';
    }

    public function scopeOrganizationId(): ?int
    {
        return $this->organization_id ? (int) $this->organization_id : null;
    }

    // ========== SensitivelyScoped ==========

    /**
     * A confidential incident is sensitive: it must not leak upward via hierarchy.
     */
    public function isSensitive(): bool
    {
        return (bool) $this->is_confidential;
    }

    /**
     * Need-to-know access to a confidential incident. Delegates to
     * OvrAuthorizationService::mayViewConfidential so the engine and the policy
     * agree: the reporter, the assigned user, or any holder of an active scoped
     * role whose definition lists an OVR confidential capability. is_admin_role
     * alone does NOT grant (the definition must explicitly carry an OVR
     * confidential capability in permissions[]).
     */
    public function mayAccessSensitive(User $user): bool
    {
        return app(OvrAuthorizationService::class)
            ->mayViewConfidential($user, $this);
    }
}
