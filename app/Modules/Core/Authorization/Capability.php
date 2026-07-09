<?php

namespace App\Modules\Core\Authorization;

/**
 * Capability — سجل ثوابت القدرات في محرّك AuthZ
 *
 * كل قدرة تأخذ الصيغة: module.action
 * الثوابت منظمة حسب الموديول.
 *
 * تُستخدم هذه الثوابت في السياسات الموصولة بالمحرّك عبر
 * AccessDecision::can(User, Capability, ?Model).
 */
final class Capability
{
    // ========================================================
    // المشاريع — Projects
    // ========================================================

    const PROJECTS_VIEW = 'projects.view';

    const PROJECTS_CREATE = 'projects.create';

    const PROJECTS_EDIT = 'projects.edit';

    const PROJECTS_DELETE = 'projects.delete';

    const PROJECTS_ASSIGN_ROLES = 'projects.assign_roles';

    // DEPRECATED (audit 2026-07-06): these capabilities are registered but
    // not enforced anywhere — status changes and closure flow through
    // `PROJECTS_EDIT`, and member management through `PROJECTS_ASSIGN_ROLES`
    // (unified with `/projects/{id}/roles/*`). They are kept as PHP
    // constants for backward compatibility with the historical migration
    // `2026_06_20_100002_backfill_functional_roles_to_scoped_org` which
    // references them at compile time (LR-004 forbids editing applied
    // migrations). New code must NOT check or grant these — the engine
    // surface that consumed them has been removed. To delete them entirely,
    // first ship a data-cleanup migration that strips these keys from every
    // `scoped_role_definitions.permissions` JSON column, then remove the
    // constants here in a subsequent release.
    const PROJECTS_MANAGE_MEMBERS = 'projects.manage_members';

    const PROJECTS_CHANGE_STATUS = 'projects.change_status';

    const PROJECTS_CLOSE = 'projects.close';

    // ========================================================
    // المهام — Tasks
    // ========================================================

    const TASKS_VIEW = 'tasks.view';

    const TASKS_CREATE = 'tasks.create';

    const TASKS_EDIT = 'tasks.edit';

    const TASKS_DELETE = 'tasks.delete';

    const TASKS_COMPLETE = 'tasks.complete';

    const TASKS_ASSIGN = 'tasks.assign';

    // ========================================================
    // الأقسام — Departments
    // ========================================================

    const DEPARTMENTS_VIEW = 'departments.view';

    const DEPARTMENTS_CREATE = 'departments.create';

    const DEPARTMENTS_EDIT = 'departments.edit';

    const DEPARTMENTS_DELETE = 'departments.delete';

    const DEPARTMENTS_MANAGE_MEMBERS = 'departments.manage_members';

    const DEPARTMENTS_ASSIGN_ROLES = 'departments.assign_roles';

    // ========================================================
    // الموارد البشرية — HR
    // ========================================================

    const HR_VIEW = 'hr.view';

    const HR_CREATE = 'hr.create';

    const HR_EDIT = 'hr.edit';

    const HR_DELETE = 'hr.delete';

    const HR_MANAGE_PROFILES = 'hr.manage_profiles';

    const HR_MANAGE = 'hr.manage';

    // ========================================================
    // الاستراتيجية (محافظ وبرامج) — Strategy
    // ========================================================

    const STRATEGY_VIEW = 'strategy.view';

    const STRATEGY_CREATE = 'strategy.create';

    const STRATEGY_EDIT = 'strategy.edit';

    const STRATEGY_DELETE = 'strategy.delete';

    const STRATEGY_MANAGE_PRIORITY = 'strategy.manage_priority';

    const STRATEGY_CHANGE_STATUS = 'strategy.change_status';

    const STRATEGY_ASSIGN_OWNER = 'strategy.assign_owner';

    const STRATEGY_MANAGE_PROJECTS = 'strategy.manage_projects';

    // ========================================================
    // إدارة المخاطر — Risks
    // ========================================================

    const RISKS_VIEW = 'risks.view';

    const RISKS_CREATE = 'risks.create';

    const RISKS_EDIT = 'risks.edit';

    const RISKS_DELETE = 'risks.delete';

    const RISKS_REASSESS = 'risks.reassess';

    const RISKS_CHANGE_STATUS = 'risks.change_status';

    const RISKS_VIEW_REPORTS = 'risks.view_reports';

    // ========================================================
    // بلاغات الحوادث — OVR
    // ========================================================

    const OVR_VIEW = 'ovr.view';

    const OVR_CREATE = 'ovr.create';

    const OVR_EDIT = 'ovr.edit';

    const OVR_DELETE = 'ovr.delete';

    const OVR_INVESTIGATE = 'ovr.investigate';

    const OVR_CLOSE = 'ovr.close';

    const OVR_VIEW_ALL = 'ovr.view_all';

    const OVR_CONFIDENTIAL = 'ovr.confidential';

    /**
     * @deprecated 2026-07-06 — retired at the data layer by the
     * `2026_07_07_000010_strip_legacy_ovr_view_confidential` migration, which
     * renames every `ovr.view_confidential` entry in
     * `scoped_role_definitions.permissions` to `ovr.confidential`. After the
     * migration runs, no application code path consults this constant.
     *
     * Retained ONLY as a class-load shim for the already-applied backfill
     * migration `2026_07_05_000027_backfill_authorization_role_permissions_ovr_confidential`,
     * which references `Capability::OVR_VIEW_CONFIDENTIAL` at compile time
     * (LR-004 forbids editing applied migrations, so this shim must remain in
     * place for `migrate:fresh` / test reseeds to load). It is also kept for
     * the legacy-key TDD pins in `Phase214bOvrConfidentialPivotBackfillTest`
     * and `AdminRoleUnifiedAuthzTest`.
     *
     * New code MUST use `Capability::OVR_CONFIDENTIAL`. To delete this constant,
     * first ship a data-cleanup migration that deletes the backfill migration's
     * file from the `migrations` table (so it is no longer re-runnable), then
     * delete the constant in a subsequent release.
     */
    const OVR_VIEW_CONFIDENTIAL = 'ovr.view_confidential';

    const OVR_CHANGE_STATUS = 'ovr.change_status';

    const OVR_ASSIGN = 'ovr.assign';

    const OVR_COMMENT = 'ovr.comment';

    const OVR_VIEW_INTERNAL_COMMENTS = 'ovr.view_internal_comments';

    const OVR_EXPORT = 'ovr.export';

    const OVR_VIEW_STATISTICS = 'ovr.view_statistics';

    const OVR_MANAGE_TYPES = 'ovr.manage_types';

    const OVR_DELETE_ALL = 'ovr.delete_all';

    // ========================================================
    // Performance (KPIs)
    // ========================================================

    const KPIS_VIEW = 'kpis.view';

    const KPIS_CREATE = 'kpis.create';

    const KPIS_EDIT = 'kpis.edit';

    const KPIS_DELETE = 'kpis.delete';

    const KPIS_MANAGE = 'kpis.manage';

    // ========================================================
    // Meetings (incl. Decisions and Recommendations)
    // ========================================================

    const MEETINGS_VIEW = 'meetings.view';

    const MEETINGS_CREATE = 'meetings.create';

    const MEETINGS_EDIT = 'meetings.edit';

    const MEETINGS_DELETE = 'meetings.delete';

    // Phase 5: canonical decision-recording capability replaces the
    // legacy 'record-decisions' Spatie string. CapabilityAlias still
    // maps record-decisions -> MEETINGS_RECORD_DECISIONS during the
    // compatibility window.
    const MEETINGS_RECORD_DECISIONS = 'meetings.record_decisions';

    // ========================================================
    // Recommendations — توصيات الاجتماعات
    // ========================================================

    const RECOMMENDATIONS_VIEW = 'recommendations.view';

    const RECOMMENDATIONS_CREATE = 'recommendations.create';

    const RECOMMENDATIONS_EDIT = 'recommendations.edit';

    const RECOMMENDATIONS_DELETE = 'recommendations.delete';

    // Direction B (Phase R1): ruling-side lifecycle capabilities. These
    // back the approve/reject/defer transitions on Recommendation rows
    // where kind='ruling'. Action_item transitions continue to use the
    // existing RECOMMENDATIONS_ACCEPT / RECOMMENDATIONS_COMPLETE below.
    const RECOMMENDATIONS_APPROVE = 'recommendations.approve';

    const RECOMMENDATIONS_REJECT = 'recommendations.reject';

    const RECOMMENDATIONS_DEFER = 'recommendations.defer';

    const RECOMMENDATIONS_ACCEPT = 'recommendations.accept';

    const RECOMMENDATIONS_COMPLETE = 'recommendations.complete';

    // ========================================================
    // Meeting Resolutions — Phase 1 / Direction R
    // ========================================================
    // Typed outputs of a meeting (kind = recommendation | decision). No
    // approve / reject / adopt / deliberate lifecycle exists; status moves
    // forward through open → in_progress → (converted_to_tasks | completed
    // | cancelled), with a metadata-only `hold` triple.

    const MEETING_RESOLUTIONS_VIEW = 'meeting_resolutions.view';

    const MEETING_RESOLUTIONS_CREATE = 'meeting_resolutions.create';

    const MEETING_RESOLUTIONS_UPDATE = 'meeting_resolutions.update';

    const MEETING_RESOLUTIONS_DELETE = 'meeting_resolutions.delete';

    const MEETING_RESOLUTIONS_HOLD = 'meeting_resolutions.hold';

    const MEETING_RESOLUTIONS_RELEASE_HOLD = 'meeting_resolutions.release_hold';

    const MEETING_RESOLUTIONS_CONVERT_TO_TASKS = 'meeting_resolutions.convert_to_tasks';

    const MEETING_RESOLUTIONS_COMPLETE = 'meeting_resolutions.complete';

    const MEETING_RESOLUTIONS_CANCEL = 'meeting_resolutions.cancel';

    // ========================================================
    // Surveys
    // ========================================================

    const SURVEYS_VIEW = 'surveys.view';

    const SURVEYS_CREATE = 'surveys.create';

    const SURVEYS_EDIT = 'surveys.edit';

    const SURVEYS_DELETE = 'surveys.delete';

    const SURVEYS_REVIEW_RESPONSES = 'surveys.review_responses';

    // Phase 8-C: surfaces the engine capability for the legacy
    // `review_data_imports` Spatie permission. The route
    // `/api/data-imports/{id}/(approve|reject|apply|retry|bulk-*)`
    // was previously gated by `permission:review_data_imports`; it
    // now uses `engine_capability:surveys.review_data_imports`.
    // Existing scoped_role_definitions.permissions[] rows that
    // carried the legacy key were backfilled by the
    // 2026_07_12_000001 migration.
    const SURVEYS_REVIEW_DATA_IMPORTS = 'surveys.review_data_imports';

    // ========================================================
    // Dashboard — لوحة التحكم
    // ========================================================

    // Phase 8-C: surfaces the engine capability for the legacy
    // `view_dashboard` Spatie permission. The route group
    // `/api/dashboard/*` was previously gated by
    // `can:view_dashboard`; it now uses
    // `engine_capability:dashboard.view`. Existing
    // scoped_role_definitions.permissions[] rows that carried the
    // legacy key were backfilled by the
    // 2026_07_12_000001 migration.
    const DASHBOARD_VIEW = 'dashboard.view';

    // ========================================================
    // الأدوار والمستخدمون والإعدادات — Admin
    // ========================================================

    const ROLES_VIEW = 'roles.view';

    const ROLES_CREATE = 'roles.create';

    const ROLES_EDIT = 'roles.edit';

    const ROLES_DELETE = 'roles.delete';

    const ROLES_ASSIGN = 'roles.assign';

    const USERS_VIEW = 'users.view';

    const USERS_CREATE = 'users.create';

    const USERS_EDIT = 'users.edit';

    const USERS_DELETE = 'users.delete';

    const USERS_MANAGE_ACCESS = 'users.manage_access';

    // Core — administration of the platform's top-level org/role/audit layer.
    // Used by OrganizationController, ScopeTypeController, RoleController, and
    // ScopedRoleController — replaces the legacy Spatie `view_organizations` /
    // `assign_roles` / `view_audit_logs` flat-string paths that fell through
    // to Spatie's User::hasPermissionTo() (engine-cutover consistency fix,
    // 2026-06-29 security re-audit).
    const CORE_VIEW_ORGANIZATIONS = 'core.view_organizations';

    const CORE_ASSIGN_ROLES = 'core.assign_roles';

    /**
     * Phase 9-D-B — Minimal cluster_tree engine primitive.
     *
     * Enables the cross-org rescue branch in `AccessDecision::whyCan()` ONLY
     * when ALL of these conditions hold:
     *   1. the requested capability is exactly this constant
     *      (no widening to users.view / projects.view / etc.),
     *   2. the user's organization is an ancestor of the target's organization
     *      via the `parent_id` walk (depth cap 32, fail-closed on cycle),
     *   3. the user holds a ScopedRole on `user.organization_id` whose
     *      `ScopedRoleDefinition.permissions[]` contains this capability
     *      (no is_admin_role shortcut, no inherit_to_children shortcut),
     *   4. the target is NOT a `SensitivelyScoped` record with `isSensitive() = true`
     *      (no bypassing the OVR confidential floor),
     *   5. the target's organization is non-null and differs from the user's.
     *
     * Read-only at this stage. The capability is decoupled from per-module
     * view capabilities by design — Phase 9-D-D (per-module widening) is
     * what would authorize reading specific resource types under this primitive.
     */
    const CLUSTER_TREE_VIEW = 'core.cluster_tree.view';

    /**
     * Phase CFA-01 — Cluster Full Authority: cluster_tree MANAGE primitive.
     *
     * Sibling to CLUSTER_TREE_VIEW. Activates the same rescue branch in
     * `AccessDecision::clusterTreeRescueApplies()` for governance-level
     * write operations (status / priority / approve / reassess / escalate)
     * across descendant organizations. Required IN ADDITION TO the
     * module-specific write capability — never implied by it.
     *
     * Strict contract:
     *   - The module's edit/manage/change_status/approve/etc. capability
     *     MUST be held on actor.org (otherwise same-org path returns false).
     *   - This primitive does NOT widen to module read capabilities.
     *   - This primitive does NOT widen to module export capabilities.
     *   - This primitive does NOT bypass the OVR / Tasks confidential floor.
     *   - This primitive does NOT allow project role/member assignment
     *     (CFA-00 owner decision, 2026-07-09).
     *
     * See: docs/audits/phase-cfa-00-cluster-full-authority-audit.md (CFA-00)
     * for the full contract, exclusion list, and stop conditions.
     */
    const CLUSTER_TREE_MANAGE = 'core.cluster_tree.manage';

    /**
     * Phase CFA-01 — Cluster Full Authority: cluster_tree EXPORT primitive.
     *
     * Sibling to CLUSTER_TREE_VIEW. Activates the same rescue branch in
     * `AccessDecision::clusterTreeRescueApplies()` for row-level data
     * exports (CSV / PDF / XLSX) across descendant organizations.
     * Required IN ADDITION TO the module's export capability (or
     * audit.export for the cluster_auditor role) — never implied by it.
     *
     * Strict contract:
     *   - The module's export capability MUST be held on actor.org.
     *   - For the cluster_auditor role: `audit.export` is the paired capability.
     *   - This primitive does NOT widen to module read or write capabilities.
     *   - This primitive does NOT bypass the OVR / Surveys / HR PII floor
     *     (aggregate/de-identified exports only — see CFA-00 audit).
     *   - Module controllers (CFA-02..CFA-11) gate each export endpoint
     *     individually; this primitive only fires when both grants are held.
     *
     * See: docs/audits/phase-cfa-00-cluster-full-authority-audit.md (CFA-00)
     * for the full contract, exclusion list, and stop conditions.
     */
    const CLUSTER_TREE_EXPORT = 'core.cluster_tree.export';

    const AUDIT_VIEW = 'audit.view';

    const AUDIT_EXPORT = 'audit.export';

    const SETTINGS_VIEW = 'settings.view';

    const SETTINGS_EDIT = 'settings.edit';

    const SETTINGS_MANAGE = 'settings.manage';

    // ========================================================
    // المرفقات — Attachments
    // ========================================================

    const ATTACHMENTS_VIEW = 'attachments.view';

    const ATTACHMENTS_UPLOAD = 'attachments.upload';

    const ATTACHMENTS_DELETE = 'attachments.delete';

    // ========================================================
    // Comments — التعليقات
    // ========================================================

    const COMMENTS_VIEW = 'comments.view';

    const COMMENTS_CREATE = 'comments.create';

    const COMMENTS_EDIT = 'comments.edit';

    const COMMENTS_DELETE = 'comments.delete';

    // ========================================================
    // Helper — جميع القدرات
    // ========================================================

    /**
     * إرجاع جميع ثوابت القدرات كمصفوفة مسطّحة.
     */
    private static ?array $allCache = null;

    public static function all(): array
    {
        if (self::$allCache !== null) {
            return self::$allCache;
        }

        return self::$allCache = array_values((new \ReflectionClass(self::class))->getConstants());
    }
}
