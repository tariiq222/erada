<?php

namespace App\Modules\Core\Enums;

/**
 * Permission — the legacy flat permission vocabulary (Spatie strings) still
 * consumed by the seeder and SPA route guards.
 *
 * As of Phase 2 of ADR-UNIFIED-ROLE-ACCESS the canonical capability vocabulary
 * is App\Modules\Core\Authorization\Capability (module.action). Each flat string
 * here is mapped to its canonical Capability (or marked a transition alias) in a
 * SINGLE place — App\Modules\Core\Authorization\CapabilityAlias::map(). When
 * adding or removing a case, update that map too; CapabilityAliasTest fails on
 * drift. The Meetings legacy kebab cases (`view-meetings`, `manage-meetings`,
 * `record-decisions`) were retired in Phase 9; canonical `meetings.view`,
 * `meetings.create`, `meetings.edit`, `meetings.delete`,
 * `meetings.record_decisions` remain the only Meetings enum cases.
 */
enum Permission: string
{
    // ========== Organizations ==========
    case VIEW_ORGANIZATIONS = 'view_organizations';
    case CREATE_ORGANIZATIONS = 'create_organizations';
    case EDIT_ORGANIZATIONS = 'edit_organizations';
    case DELETE_ORGANIZATIONS = 'delete_organizations';

    // ========== Users ==========
    case VIEW_USERS = 'view_users';
    case CREATE_USERS = 'create_users';
    case EDIT_USERS = 'edit_users';
    case DELETE_USERS = 'delete_users';

    // ========== Dashboard ==========
    case VIEW_DASHBOARD = 'view_dashboard';

    // ========== Projects ==========
    // own/department ladder strings removed in Wave 4 (engine handles visibility
    // via Capability::PROJECTS_VIEW + grantingScopes/subtreeDepartmentIds).
    case VIEW_PROJECTS = 'view_projects';
    case CREATE_PROJECTS = 'create_projects';
    case EDIT_PROJECTS = 'edit_projects';
    case DELETE_PROJECTS = 'delete_projects';

    // ========== Tasks ==========
    // own/department ladder strings removed in Wave 4.
    case VIEW_TASKS = 'view_tasks';
    case CREATE_TASKS = 'create_tasks';
    case EDIT_TASKS = 'edit_tasks';
    case DELETE_TASKS = 'delete_tasks';

    // ========== Reports ==========
    case VIEW_REPORTS = 'view_reports';
    case EXPORT_REPORTS = 'export_reports';

    // ========== Roles ==========
    case VIEW_ROLES = 'view_roles';
    case CREATE_ROLES = 'create_roles';
    case EDIT_ROLES = 'edit_roles';
    case DELETE_ROLES = 'delete_roles';
    case ASSIGN_ROLES = 'assign_roles';

    // ========== Attachments ==========
    case UPLOAD_ATTACHMENTS = 'upload_attachments';
    case DOWNLOAD_ATTACHMENTS = 'download_attachments';
    case DELETE_ATTACHMENTS = 'delete_attachments';

    // ========== Comments ==========
    case CREATE_COMMENTS = 'create_comments';
    case EDIT_COMMENTS = 'edit_comments';
    case DELETE_COMMENTS = 'delete_comments';
    case EDIT_ANY_COMMENT = 'edit_any_comment';
    case DELETE_ANY_COMMENT = 'delete_any_comment';

    // ========== Audit Logs ==========
    case VIEW_AUDIT_LOGS = 'view_audit_logs';
    case EXPORT_AUDIT_LOGS = 'export_audit_logs';

    // ========== Strategy ==========
    case VIEW_STRATEGY = 'view_strategy';
    case CREATE_STRATEGY = 'create_strategy';
    case EDIT_STRATEGY = 'edit_strategy';
    case DELETE_STRATEGY = 'delete_strategy';

    // ========== Meetings ==========
    // Phase 5: canonical dotted capabilities. Legacy kebab strings
    // (`view-meetings`, `manage-meetings`, `record-decisions`) were
    // removed in Phase 9 after the compatibility window. New installs
    // grant canonical names only.
    case MEETINGS_VIEW = 'meetings.view';
    case MEETINGS_CREATE = 'meetings.create';
    case MEETINGS_EDIT = 'meetings.edit';
    case MEETINGS_DELETE = 'meetings.delete';
    case MEETINGS_RECORD_DECISIONS = 'meetings.record_decisions';

    // ========== Surveys ==========
    case VIEW_SURVEY_RESPONSES = 'view_survey_responses';
    case REVIEW_SURVEY_RESPONSES = 'review_survey_responses';
    case REVIEW_DATA_IMPORTS = 'review_data_imports';

    // ========== Departments ==========
    case VIEW_DEPARTMENTS = 'view_departments';
    case CREATE_DEPARTMENTS = 'create_departments';
    case EDIT_DEPARTMENTS = 'edit_departments';
    case DELETE_DEPARTMENTS = 'delete_departments';

    // ========== OVR (Incident Reports) ==========
    // own/department ladder strings removed in Wave 4 (engine + Capability::OVR_VIEW).
    case OVR_VIEW_ALL = 'ovr.view_all';
    case OVR_CONFIDENTIAL_VIEW = 'ovr.confidential';
    case OVR_CREATE = 'ovr.create';
    case OVR_EDIT_ALL = 'ovr.edit_all';
    case OVR_CHANGE_STATUS = 'ovr.change_status';
    case OVR_ASSIGN = 'ovr.assign';
    case OVR_COMMENT = 'ovr.comment';
    case OVR_VIEW_INTERNAL_COMMENTS = 'ovr.view_internal_comments';
    case OVR_EXPORT = 'ovr.export';
    case OVR_VIEW_STATISTICS = 'ovr.view_statistics';

    // ========== Risk Management ==========
    // سلّم النطاق: own < department < all (المجرّدة view_risks = الكل)
    case VIEW_DEPARTMENT_RISKS = 'view_department_risks';
    case VIEW_OWN_RISKS = 'view_own_risks';
    case EDIT_DEPARTMENT_RISKS = 'edit_department_risks';
    case EDIT_OWN_RISKS = 'edit_own_risks';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
