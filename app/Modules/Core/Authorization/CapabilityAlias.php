<?php

namespace App\Modules\Core\Authorization;

/**
 * CapabilityAlias — the single place that maps a legacy flat permission string
 * to its canonical `module.action` Capability (Phase 2 of ADR-UNIFIED-ROLE-ACCESS).
 *
 * `Capability` (module.action) is the single maintained capability vocabulary.
 * The flat Spatie permission strings that the SPA still gates on
 * (view_projects, edit_departments, ...) are DERIVED aliases over that
 * vocabulary — declared here once, not hand-maintained as an independent list.
 *
 * Two buckets:
 *   1. flat string HAS a canonical Capability  -> maps to that constant.
 *   2. flat string has NO canonical Capability yet -> maps to null and is
 *      kept as a TRANSITION ALIAS (ponytail: remove once the SPA route guards
 *      move to module.action or the capability is introduced).
 *
 * This map is projection/vocabulary only. It does NOT grant anything and MUST
 * NOT be consulted by AccessDecision — the decision path stays engine-only.
 *
 * Phase 9 note: the legacy kebab Meetings strings (view-meetings,
 * manage-meetings, record-decisions) were retired. Only the canonical
 * dotted strings remain in this map and in the Permission enum.
 */
final class CapabilityAlias
{
    /**
     * legacy flat string => canonical Capability constant (or null for a
     * transition alias with no capability equivalent yet).
     *
     * @return array<string, string|null>
     */
    public static function map(): array
    {
        return [
            // ── Organizations / Core admin ──
            'view_organizations' => Capability::CORE_VIEW_ORGANIZATIONS,
            'assign_roles' => Capability::CORE_ASSIGN_ROLES,
            // ponytail: no create/edit/delete-organization capability yet — transition aliases, remove in Phase 4.
            'create_organizations' => null,
            'edit_organizations' => null,
            'delete_organizations' => null,

            // ── Users ──
            'view_users' => Capability::USERS_VIEW,
            'create_users' => Capability::USERS_CREATE,
            'edit_users' => Capability::USERS_EDIT,
            'delete_users' => Capability::USERS_DELETE,

            // ── Dashboard / Reports ──
            // Phase 8-C: view_dashboard now resolves to a canonical capability
            // (dashboard.view). view_reports / export_reports remain ponytails
            // until a reports module is introduced.
            'view_dashboard' => Capability::DASHBOARD_VIEW,
            'view_reports' => null,
            'export_reports' => null,

            // ── Projects ──
            'view_projects' => Capability::PROJECTS_VIEW,
            'create_projects' => Capability::PROJECTS_CREATE,
            'edit_projects' => Capability::PROJECTS_EDIT,
            'delete_projects' => Capability::PROJECTS_DELETE,

            // ── Tasks ──
            'view_tasks' => Capability::TASKS_VIEW,
            'create_tasks' => Capability::TASKS_CREATE,
            'edit_tasks' => Capability::TASKS_EDIT,
            'delete_tasks' => Capability::TASKS_DELETE,

            // ── Roles ──
            'view_roles' => Capability::ROLES_VIEW,
            'create_roles' => Capability::ROLES_CREATE,
            'edit_roles' => Capability::ROLES_EDIT,
            'delete_roles' => Capability::ROLES_DELETE,

            // ── Attachments ──
            'upload_attachments' => Capability::ATTACHMENTS_UPLOAD,
            'download_attachments' => Capability::ATTACHMENTS_VIEW,
            'delete_attachments' => Capability::ATTACHMENTS_DELETE,

            // ── Comments ──
            'create_comments' => Capability::COMMENTS_CREATE,
            'edit_comments' => Capability::COMMENTS_EDIT,
            'delete_comments' => Capability::COMMENTS_DELETE,
            // ponytail: no "any comment" capability variant — transition aliases, remove in Phase 4.
            'edit_any_comment' => null,
            'delete_any_comment' => null,

            // ── Audit ──
            'view_audit_logs' => Capability::AUDIT_VIEW,
            'export_audit_logs' => Capability::AUDIT_EXPORT,

            // ── Strategy ──
            'view_strategy' => Capability::STRATEGY_VIEW,
            'create_strategy' => Capability::STRATEGY_CREATE,
            'edit_strategy' => Capability::STRATEGY_EDIT,
            'delete_strategy' => Capability::STRATEGY_DELETE,

            // Historical database vocabulary kept only for reconciliation and
            // canonical integrity reporting while stored aliases are normalized.
            //
            // CSD-CA23078-CORE-001 — department-scoped rationale: these flat
            // strings encode department-reach semantics (`edit_<scope>_<module>`
            // was the legacy ladder shape: <scope> in {own, department} and
            // <module> in {projects, tasks}). Resolving them to the un-reach-capped
            // canonical `PROJECTS_EDIT` / `TASKS_EDIT` masks the reach restriction:
            // the canonical pivot would carry `reach=null` and the engine would
            // grant all-reach. The correct narrowing is `reach={"projects":
            // "department"}` on the pivot created by migration
            // `2026_07_03_000010_backfill_authorization_role_permissions`, applied
            // by the safety-net migration `2026_07_12_000016_narrow_legacy_department_aliases`
            // for pivots whose audit marker carries the legacy permission name.
            //
            // Returning `null` here drops the alias resolution path entirely so
            // any FUTURE occurrence of the legacy string (e.g. a stale Spatie row
            // re-introduced by an operator) is treated as a transition alias and
            // the engine consults the post-cutover reach map instead of falling
            // through to the unrestricted canonical capability.
            'edit_department_projects' => null,
            'edit_department_tasks' => null,

            // ── Meetings ──
            'meetings.view' => Capability::MEETINGS_VIEW,
            'meetings.create' => Capability::MEETINGS_CREATE,
            'meetings.edit' => Capability::MEETINGS_EDIT,
            'meetings.delete' => Capability::MEETINGS_DELETE,
            'meetings.record_decisions' => Capability::MEETINGS_RECORD_DECISIONS,

            // ── Surveys ──
            // Phase 8-D: corrected mapping. The legacy 'view_survey_responses'
            // Spatie permission has historically gated RESPONSE data
            // (SurveyResponseController::index/show/flag/review and
            // SurveyController::export — both return PII from the
            // survey_responses table), not the survey metadata. The
            // historical alias pointed at SURVEYS_VIEW (the survey-metadata
            // capability) which was a Phase 1 cutover inconsistency: users
            // granted only `view_survey_responses` would have passed the
            // Spatie middleware but failed the engine check on the inner
            // authorize() calls (which all use SURVEYS_REVIEW_RESPONSES).
            // Resolving the alias to SURVEYS_REVIEW_RESPONSES matches the
            // route semantics and the inner authorize() decision, and
            // surfaces a single canonical capability for the
            // `permission:view_survey_responses` route guards.
            'view_survey_responses' => Capability::SURVEYS_REVIEW_RESPONSES,
            'review_survey_responses' => Capability::SURVEYS_REVIEW_RESPONSES,
            // Phase 8-C: review_data_imports now resolves to a canonical capability
            // (surveys.review_data_imports).
            'review_data_imports' => Capability::SURVEYS_REVIEW_DATA_IMPORTS,

            // ── Departments ──
            'view_departments' => Capability::DEPARTMENTS_VIEW,
            'create_departments' => Capability::DEPARTMENTS_CREATE,
            'edit_departments' => Capability::DEPARTMENTS_EDIT,
            'delete_departments' => Capability::DEPARTMENTS_DELETE,

            // ── OVR (already module.action-shaped for most) ──
            'ovr.view_all' => Capability::OVR_VIEW_ALL,
            'ovr.confidential' => Capability::OVR_CONFIDENTIAL,
            'ovr.create' => Capability::OVR_CREATE,
            'ovr.edit_all' => Capability::OVR_EDIT,
            'ovr.change_status' => Capability::OVR_CHANGE_STATUS,
            'ovr.assign' => Capability::OVR_ASSIGN,
            'ovr.comment' => Capability::OVR_COMMENT,
            'ovr.view_internal_comments' => Capability::OVR_VIEW_INTERNAL_COMMENTS,
            'ovr.export' => Capability::OVR_EXPORT,
            'ovr.view_statistics' => Capability::OVR_VIEW_STATISTICS,

            // ── Risk Management (own/department ladder handled by the engine's
            // scope, not a distinct capability) ──
            // ponytail: scope-ladder flat strings have no capability equivalent —
            // the engine resolves reach via scope. Transition aliases, remove in Phase 4.
            'view_department_risks' => null,
            'view_own_risks' => null,
            'edit_department_risks' => null,
            'edit_own_risks' => null,
        ];
    }

    /**
     * The canonical Capability for a legacy flat string, or null if the string
     * has no capability equivalent (transition alias).
     */
    public static function toCapability(string $flat): ?string
    {
        return self::map()[$flat] ?? null;
    }

    /**
     * Flat strings that still have NO canonical capability (transition aliases
     * to remove in Phase 4). Kept as documentation/verification helper.
     *
     * @return array<int, string>
     */
    public static function transitionAliases(): array
    {
        return array_keys(array_filter(self::map(), fn ($cap) => $cap === null));
    }
}
