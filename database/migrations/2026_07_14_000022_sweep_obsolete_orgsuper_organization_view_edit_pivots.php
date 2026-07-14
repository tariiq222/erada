<?php

use App\Modules\Core\Authorization\Actions\SweepObsoleteOrgSuperOrganizationViewEditPivotsAction;
use Illuminate\Database\Migrations\Migration;

/**
 * CSD-CA23078-CORE-009 (OrgSuper rewrite — Task 5 targeted sweep).
 *
 * Targeted sweep of obsolete authorization_role_permissions pivots caused by
 * the previous `core.cluster_tree` → `Organization::class` mapping alias in
 * `CapabilityToAuthorizationRolePermission::PREFIX_TO_RESOURCE`.
 *
 * Scope (deliberately narrow):
 *   - authorization_role_id corresponding to name = 'organization_super_admin'
 *   - authorization_resource_id corresponding to Organization::class
 *   - action IN ('view', 'edit')
 *
 * Out of scope (intentionally):
 *   - organizations.settings column on the organizations table — UNTOUCHED.
 *     The new contract writes to `organization_settings`, never to
 *     `organizations.settings`; this migration does not read or write that
 *     column.
 *   - cluster_auditor role — its pivots on `Organization` are legitimate
 *     cluster_tree pivots and must NOT be swept.
 *   - admin, super_admin, viewer, dept_manager, member, project_*,
 *     dept_member, pmo_*, quality_manager, risk_manager — none of their
 *     pivots are touched.
 *   - any other resource (User, Department, Project, Task, Meeting, etc.).
 *
 * Idempotent + convergent: each pivot deletion is mirrored by an audit row
 * in the SAME transaction (delete+audit atomicity — a mid-sweep failure
 * leaves zero orphan deletions AND zero orphan audits). On re-run,
 * recreated obsolete pivots are re-deleted, but no duplicate audit row is
 * written for a pivot already audited by a prior run of this migration.
 * Forward-only: `down()` is intentionally a no-op so a rollback does not
 * re-introduce the obsolete pivots.
 *
 * PostgreSQL-only: the audit table comparison uses jsonb containment
 * semantics; SQLite is forbidden at the project level (CI guard job).
 *
 * The sweep engine itself lives in
 * `App\Modules\Core\Authorization\Actions\SweepObsoleteOrgSuperOrganizationViewEditPivotsAction`
 * so the strengthened unit test that owns the deletion + audit invariants
 * runs the same code the operator ships.
 */
return new class extends Migration
{
    public function up(): void
    {
        SweepObsoleteOrgSuperOrganizationViewEditPivotsAction::execute();
    }

    public function down(): void
    {
        // Forward-only — see class-level docblock.
    }
};
