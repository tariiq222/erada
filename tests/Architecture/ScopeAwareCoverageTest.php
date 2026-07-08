<?php

namespace Tests\Architecture;

use App\Modules\Core\Authorization\Contracts\ScopeAware;
use App\Modules\Core\Models\EmailOtp;
use App\Modules\Core\Models\GovernanceRule;
use App\Modules\Core\Models\LoginAttempt;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\ScopedRole;
use App\Modules\Core\Models\ScopedRoleDefinition;
use App\Modules\Core\Models\ScopeType;
use App\Modules\Core\Models\SystemSettings;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\DepartmentCapacityRole;
use App\Modules\HR\Models\EmployeeCertificate;
use App\Modules\HR\Models\EmployeePersonalInfo;
use App\Modules\HR\Models\EmployeeProfile;
use App\Modules\Meetings\Models\MeetingAgendaItem;
use App\Modules\Meetings\Models\MeetingAttendee;
use App\Modules\Meetings\Models\MeetingCategory;
use App\Modules\Meetings\Models\MeetingSettings;
use App\Modules\Meetings\Models\ResolutionLink;
use App\Modules\OVR\Models\IncidentParticipant;
use App\Modules\OVR\Models\IncidentType;
use App\Modules\OVR\Models\OvrSetting;
use App\Modules\OVR\Models\ReportableType;
use App\Modules\OVR\Models\ReportComment;
use App\Modules\OVR\Models\StatusHistory;
use App\Modules\Performance\Models\KpiLink;
use App\Modules\Performance\Models\KpiMeasurement;
use App\Modules\Projects\Models\Milestone;
use App\Modules\Projects\Models\MilestoneDeliverable;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectExpense;
use App\Modules\Projects\Models\ProjectRisk;
use App\Modules\Projects\Models\ProjectSetting;
use App\Modules\Projects\Models\Stakeholder;
use App\Modules\RiskManagement\Models\RiskActionUpdate;
use App\Modules\RiskManagement\Models\RiskAlert;
use App\Modules\RiskManagement\Models\RiskImpactType;
use App\Modules\RiskManagement\Models\RiskSetting;
use App\Modules\RiskManagement\Models\RiskStatusChange;
use App\Modules\RiskManagement\Models\RiskType;
use App\Modules\Shared\Models\ActivityLog;
use App\Modules\Shared\Models\Attachment;
use App\Modules\Shared\Models\Comment;
use App\Modules\Strategy\Models\StrategicObjective;
use App\Modules\Surveys\Models\DataMappingTemplate;
use App\Modules\Surveys\Models\SurveyAnswerFile;
use App\Modules\Surveys\Models\SurveyField;
use App\Modules\Surveys\Models\SurveyFieldAnswer;
use App\Modules\Surveys\Models\SurveyInvitation;
use App\Modules\Surveys\Models\SurveyResponse;
use App\Modules\Surveys\Models\SurveySection;
use App\Modules\Surveys\Models\SurveyVersion;
use App\Modules\Tasks\Models\Task;
use Illuminate\Database\Eloquent\Model;
use Tests\TestCase;

/**
 * ScopeAwareCoverageTest — enforces that every concrete operational Eloquent model
 * under app/Modules declares its scope by implementing ScopeAware, OR is explicitly
 * listed in NON_SCOPED_ALLOWLIST with a reason. A new model is "offending" until it
 * is classified, which forces the scope decision to be made consciously rather than
 * forgotten — misclassifying an operational record as non-scoped is a security gap.
 */
class ScopeAwareCoverageTest extends TestCase
{
    /**
     * Non-operational models exempt from ScopeAware. Each entry MUST carry a reason.
     *
     * Categories considered non-operational: identity, organization/scope roots,
     * lookups/reference data, configuration/settings, audit/notification logs,
     * pivots/join rows, and child value-objects whose parent is the governed record
     * (they are reached and authorized through the parent, never addressed directly
     * by the AuthZ engine).
     */
    private const NON_SCOPED_ALLOWLIST = [
        // ---- Identity / authentication (not a governed resource) ----
        User::class,                  // reason: identity
        Organization::class,          // reason: scope root, not a scoped record
        EmailOtp::class,              // reason: auth/OTP credential
        LoginAttempt::class,          // reason: auth security log

        // ---- Scope/role system internals and configuration ----
        ScopeType::class,             // reason: scope-system lookup
        ScopedRole::class,            // reason: the role-assignment pivot itself
        ScopedRoleDefinition::class,  // reason: role definition (config)
        GovernanceRule::class,        // reason: governance policy config (governing unit per resource type)
        SystemSettings::class,        // reason: system configuration
        DepartmentCapacityRole::class,  // reason: capacity-policy config row

        // ---- Reference / lookup data ----
        IncidentType::class,           // reason: lookup
        ReportableType::class,         // reason: lookup
        RiskImpactType::class, // reason: lookup
        RiskType::class,    // reason: lookup
        MeetingCategory::class,   // reason: lookup

        // ---- Module settings ----
        MeetingSettings::class,   // reason: module settings
        ProjectSetting::class,    // reason: global project settings (key/value)
        OvrSetting::class,        // reason: module settings
        RiskSetting::class,       // reason: module settings (key/value)

        // ---- Pivots / join rows ----
        MeetingAttendee::class,   // reason: meeting<->user pivot (composite key)
        ResolutionLink::class,    // reason: meeting-resolution<->project|risk pivot; governed through MeetingResolution (link is informational, not authoritative for visibility — see MeetingResolution::scopeVisibleTo docblock)

        // ---- Audit / history / notification logs ----
        ActivityLog::class,         // reason: audit log
        StatusHistory::class,          // reason: status-change audit trail
        RiskStatusChange::class, // reason: status-change audit trail
        RiskAlert::class,   // reason: notification/alert log
        RiskActionUpdate::class, // reason: progress-update log of an (already ScopeAware) RiskAction

        // ---- Generic comments / attachments (governed through their polymorphic parent) ----
        Comment::class,             // reason: polymorphic comment; governed via commentable parent
        Attachment::class,          // reason: polymorphic attachment; governed via attachable parent
        ReportComment::class,          // reason: comment on IncidentReport; governed via the report

        // ---- HR employee profile sub-records (identity/profile domain) ----
        EmployeeProfile::class,         // reason: employee profile (identity/HR record, not org-vertical scoped resource)
        EmployeePersonalInfo::class,    // reason: employee personal info (1:1 profile detail)
        EmployeeCertificate::class,     // reason: employee certificate (profile sub-record)

        // ---- Child value-objects of an already-governed parent (reached/authorized via the parent) ----
        KpiLink::class,        // reason: child of Kpi; governed via the KPI
        KpiMeasurement::class, // reason: child of Kpi; governed via the KPI
        Milestone::class,         // reason: child of Project; governed via the project
        MilestoneDeliverable::class, // reason: child of Milestone -> Project
        ProjectExpense::class,    // reason: child of Project; governed via the project
        ProjectRisk::class,       // reason: child of Project; governed via the project
        Stakeholder::class,       // reason: child of Project; governed via the project
        MeetingAgendaItem::class, // reason: child of Meeting; governed via the meeting
        IncidentParticipant::class, // reason: child of IncidentReport; governed via the report
        SurveySection::class,      // reason: child of Survey; governed via the survey
        SurveyField::class,        // reason: child of Survey; governed via the survey
        SurveyVersion::class,      // reason: child of Survey; governed via the survey
        SurveyInvitation::class,   // reason: child of Survey; governed via the survey
        SurveyResponse::class,     // reason: respondent submission of a Survey; governed via the survey
        SurveyFieldAnswer::class,  // reason: child of SurveyResponse -> Survey
        SurveyAnswerFile::class,   // reason: child of SurveyFieldAnswer -> Survey
        DataMappingTemplate::class, // reason: child config of a Survey; governed via the survey

        // ---- Legacy / dead (backing table dropped) ----
        StrategicObjective::class, // reason: legacy polymorphic-metadata class; strategic_objectives table was dropped (2026_01_16)
    ];

    public function test_every_operational_model_is_scope_aware(): void
    {
        $scanned = $this->modelClasses();

        // Sanity: the suite must actually discover the known ScopeAware models.
        $this->assertContains(Project::class, $scanned);
        $this->assertContains(Task::class, $scanned);

        $offenders = [];

        foreach ($scanned as $class) {
            if (in_array($class, self::NON_SCOPED_ALLOWLIST, true)) {
                continue;
            }
            if (! in_array(ScopeAware::class, class_implements($class) ?: [], true)) {
                $offenders[] = $class;
            }
        }

        sort($offenders);

        $this->assertSame([], $offenders,
            "Models must implement ScopeAware or be added to NON_SCOPED_ALLOWLIST with a reason:\n"
            .implode("\n", $offenders));
    }

    public function test_reports_scanned_model_count(): void
    {
        $count = count($this->modelClasses());
        fwrite(STDERR, "\n[ScopeAwareCoverage] scanned {$count} concrete Eloquent models under app/Modules\n");
        $this->assertGreaterThan(0, $count);
    }

    /**
     * Enumerate every concrete Eloquent model under app/Modules/<Module>/Models.
     *
     * @return array<int, class-string<Model>>
     */
    private function modelClasses(): array
    {
        $classes = [];

        foreach (glob(base_path('app/Modules/*/Models/*.php')) as $file) {
            $fqcn = $this->fqcnFromPath($file);
            if ($fqcn === null || ! class_exists($fqcn)) {
                continue;
            }

            $ref = new \ReflectionClass($fqcn);
            if ($ref->isAbstract() || ! $ref->isSubclassOf(Model::class)) {
                continue;
            }

            $classes[] = $fqcn;
        }

        sort($classes);

        return $classes;
    }

    /**
     * Map app/Modules/<Module>/Models/<Class>.php to App\Modules\<Module>\Models\<Class>,
     * honoring the repo's PSR-4 mapping (app/ => App\).
     */
    private function fqcnFromPath(string $file): ?string
    {
        $appPath = base_path('app').DIRECTORY_SEPARATOR;
        if (! str_starts_with($file, $appPath)) {
            return null;
        }

        $relative = substr($file, strlen($appPath));
        $relative = preg_replace('/\.php$/', '', $relative);

        return 'App\\'.str_replace(DIRECTORY_SEPARATOR, '\\', $relative);
    }
}
