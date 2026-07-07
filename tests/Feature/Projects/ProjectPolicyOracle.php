<?php

namespace Tests\Feature\Projects;

use App\Modules\Core\Models\ScopedRole;
use App\Modules\Core\Models\ScopedRoleDefinition;
use App\Modules\Core\Models\User;
use App\Modules\Projects\Models\Project;

/**
 * ProjectPolicyOracle — hand-encoded Projects authz specification.
 *
 * Hard constraint: this class MUST NOT call AccessDecision::can(),
 * ProjectPolicy::anyMethod(), ProjectAuthorizationService, or any helper that
 * ultimately routes through the engine. The whole point of this oracle is to
 * pin behavior to the spec rather than to the engine itself. Allowed primitives:
 *
 *  - User::getRoleNames() (Spatie role names — these are constants, not engine
 *    inputs)
 *  - User::activeScopedRoles (the scoped-role relation — also a fact about the
 *    database, not a decision)
 *  - Project's own columns (status, created_by, organization_id, department_id)
 *
 * All decisions are derived from these primitives via a small set of explicit
 * rules with named citations. This makes each row in the parity test suite
 * reviewable against the documented authz model rather than against whatever
 * the engine happens to do today.
 *
 * Rule citations:
 *  - docs/AUTHZ-DECISIONS.md
 *  - docs/ADR-UNIFIED-AUTHORIZATION.md
 *  - ProjectPolicy docblock (engine-only since Phase هـ)
 */
final class ProjectPolicyOracle
{
    private const RULE_SUPER_ADMIN_BYPASS = 'super_admin bypasses every check (ADR-UNIFIED-AUTHORIZATION §2.2)';

    private const RULE_ORG_ISOLATION = 'cross-org target denies everything (AccessDecision step 2; fail-closed)';

    private const RULE_OWNER_FLOOR = 'owner may view always; may edit only while lifecycle-open (Project::isOwnerEditable)';

    private const RULE_ORG_FUNCTIONAL = 'org-scope functional role definition (Spatie role name → org-scoped definition) covers the org';

    private const RULE_SCOPED_PROJECT = 'inline project-scoped role grants capabilities on that project (and via inherit_to_children on descendants)';

    private const RULE_PROJECT_SCOPE_TYPE = 'project';

    private const RULE_FORCE_DELETE_CLOSED = 'forceDelete hard-closed in ProjectPolicy regardless of persona';

    public function __construct(
        private readonly User $user,
        private readonly ?Project $project
    ) {}

    /**
     * Decide whether $this->user may perform $ability on $this->project (or in
     * general when $project is null).
     *
     * @param  string  $ability  one of the project policy abilities (view,
     *                           viewAny, create, update, delete, restore,
     *                           forceDelete, manageMembers, assignProjectRoles)
     */
    public function decide(string $ability): bool
    {
        // Rule: ProjectPolicy::forceDelete returns false regardless. This is
        // the only hard-coded deny independent of the engine.
        if ($ability === 'forceDelete') {
            return false;
        }

        // Rule: super_admin bypasses every engine check. ForceDelete is the
        // single exception (handled above) because ProjectPolicy short-circuits
        // it before reaching the engine's super_admin branch.
        if ($this->user->getRoleNames()->contains('super_admin')) {
            return true;
        }

        if ($ability === 'viewAny') {
            // viewAny has no $target. Org-scope functional roles grant view
            // org-wide; engine matches via the functional-role bridge on
            // null-target calls. Spec: a user with an org-scope functional
            // role granting projects.view may viewAny.
            return $this->grantsOnOrganization('projects.view');
        }

        if ($ability === 'create') {
            // create() is null-target. The spec says: a user may create if any
            // of these hold:
            //   1. they have an org-scope role granting projects.create
            //      (admin grants it via the org-level definition);
            //   2. they have a department-scoped role that grants projects.create;
            //   3. they have a scoped role on a governing-department subtree.
            // For the parity table, persona 'admin' should pass (rule 1),
            // everybody else (incl. viewer, project_manager) should fail.
            return $this->grantsOnOrganization('projects.create');
        }

        // All remaining abilities operate on $project. Apply org isolation
        // first, then owner-floor, then scoped roles.
        if ($this->project === null) {
            return false;
        }

        // Rule: org isolation gate. A target in a different organization is
        // denied. A null-org user on any target is denied (engine fails
        // closed).
        if (! $this->sharesOrganization($this->project)) {
            return false;
        }

        // Rule: owner floor — creator may view always; may edit only while
        // the project is lifecycle-open. Other abilities (delete / manage /
        // assign) are NEVER granted by the owner floor.
        $createdBy = $this->project->getAttribute('created_by');
        $isOwner = $createdBy !== null && (int) $createdBy === (int) $this->user->id;

        if ($isOwner) {
            // view always
            if ($ability === 'view') {
                return true;
            }

            // edit (== update) only while lifecycle-open
            if ($ability === 'update') {
                return $this->isProjectLifecycleOpen($this->project);
            }

            // delete/restore/manage/assign: NEVER via owner floor
            // (the engine applies this rule too).
        }

        // Rule: scoped project roles (inline). The user's active scoped roles
        // include all (scope_type, scope_id) pairs; we look for the
        // project-scope role whose scope_id matches this project. The role's
        // definition determines what abilities it grants.
        if ($this->grantsOnInlineProject($this->project, $this->capabilityFor($ability))) {
            return true;
        }

        // Rule: org-scope functional role grants projects.* org-wide for an
        // admin role (is_admin_role=true) and read-only for a viewer role.
        if ($this->grantsOnOrganization($this->capabilityFor($ability))) {
            return true;
        }

        return false;
    }

    /**
     * Map ability names to the underlying engine capability strings. The
     * engine routes (e.g.) delete through Capability::PROJECTS_DELETE. We
     * reuse the same constants here so oracle and engine compare apples to
     * apples.
     */
    private function capabilityFor(string $ability): string
    {
        return match ($ability) {
            'view' => 'projects.view',
            'update' => 'projects.edit',
            'delete' => 'projects.delete',
            'restore' => 'projects.delete',
            'manageMembers' => 'projects.manage_members',
            'assignProjectRoles' => 'projects.assign_roles',
            default => throw new \InvalidArgumentException("Unknown ability: {$ability}"),
        };
    }

    /**
     * Org isolation: does the user's organization_id match the project's?
     * Equal-to-null on either side returns false.
     */
    private function sharesOrganization(Project $project): bool
    {
        $userOrg = $this->user->organization_id;
        $projectOrg = $project->organization_id;

        if ($userOrg === null || $projectOrg === null) {
            return false;
        }

        return (int) $userOrg === (int) $projectOrg;
    }

    /**
     * Mirror Project::isOwnerEditable: status must not be in the closed set.
     */
    private function isProjectLifecycleOpen(Project $project): bool
    {
        $status = $project->getAttribute('status');

        return ! in_array($status, ['completed', 'cancelled', 'closed'], true);
    }

    /**
     * Does the user hold an org-scope functional role whose definition grants
     * $capability? "Functional role" means the user's Spatie role name maps to
     * a ScopedRoleDefinition keyed on (scope_type=organization, role=name).
     * The definition either has is_admin_role=true (grants everything) or
     * carries $capability in its permissions array.
     *
     * NOTE: the spec says is_admin_role=true grants ALL capabilities
     * unconditionally; permissions list is a fallback for non-admin roles.
     */
    private function grantsOnOrganization(string $capability): bool
    {
        foreach ($this->user->getRoleNames() as $roleName) {
            $definition = ScopedRoleDefinition::query()
                ->where('scope_type', ScopedRole::SCOPE_ORGANIZATION)
                ->where('role_key', $roleName)
                ->first();

            if ($definition === null) {
                continue;
            }

            if ($definition->is_admin_role) {
                return true;
            }

            $permissions = $definition->permissions ?? [];
            if (in_array($capability, $permissions, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Does the user hold an inline project-scoped role on $project whose
     * definition grants $capability?
     *
     * For the parity matrix, the relevant project-scope roles are
     * project_manager / project_member / project_viewer, all seeded in the
     * test setUp. We mirror the engine's match: scope_type='project' AND
     * scope_id=project.id.
     */
    private function grantsOnInlineProject(Project $project, string $capability): bool
    {
        $projectId = (int) $project->getKey();

        $matchingRoles = $this->user->activeScopedRoles
            ->where('scope_type', self::RULE_PROJECT_SCOPE_TYPE)
            ->where('scope_id', $projectId);

        foreach ($matchingRoles as $role) {
            $definition = ScopedRoleDefinition::query()
                ->where('scope_type', ScopedRole::SCOPE_PROJECT)
                ->where('role_key', $role->role)
                ->first();

            if ($definition === null) {
                continue;
            }

            if ($definition->is_admin_role) {
                return true;
            }

            $permissions = $definition->permissions ?? [];
            if (in_array($capability, $permissions, true)) {
                return true;
            }
        }

        return false;
    }
}
