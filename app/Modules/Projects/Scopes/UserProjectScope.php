<?php

namespace App\Modules\Projects\Scopes;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Authorization\Models\AuthorizationResource;
use App\Modules\Core\Authorization\Models\AuthorizationRoleAssignment;
use App\Modules\Core\Authorization\Support\CapabilityToAuthorizationRolePermission;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Projects\Services\ProjectAuthorizationService;
use Illuminate\Database\Eloquent\Builder;

/**
 * UserProjectScope - تطبيق فلتر صلاحيات المستخدم على استعلامات المشاريع
 *
 * نموذج الرؤية إضافي (OR) لا بوّابة صلبة: المستخدم (غير super_admin) يرى مشروعاً
 * إذا تحقّق أيٌّ مما يلي بعد فرض عزل المؤسسة:
 *   - ارتباط مباشر: منشئ / عضو / دور سياقي / صاحب مصلحة / مكلّف بمهمة (دائماً).
 *   - منحة محرّك AccessDecision: أدوار سياقية على القسم (مع شجرته الهابطة) أو على
 *     المشروع، أو دور وظيفي على مستوى المؤسسة (يمنح كامل المؤسسة).
 *   - توسعة السلّم المسطّح: view_projects → كامل المؤسسة؛ view_department_projects
 *     → قسمه وشجرته؛ view_own_projects → الارتباطات المباشرة فقط. الصلاحية المسطّحة
 *     تزيد المدى ولا تُعدّ شرطاً مسبقاً لرؤية ما يرتبط به المستخدم مباشرة.
 *   - بلا أي ارتباط ولا منحة ولا صلاحية → لا شيء.
 *
 * super_admin يرى الكل. عزل المؤسسة يُطبَّق أولاً دائماً فيمنع التسرب عبر
 * المؤسسات أياً كان مستوى الوصول. هذه هي المرجعية الوحيدة لقرار رؤية القوائم
 * (DashboardController / ProjectController / ProjectQueryService) كي لا يختلف
 * نطاق لوحة المعلومات عن نطاق قائمة المشاريع لنفس المستخدم.
 *
 * Phase CFA-04 — Cluster Full Authority widening (read-only):
 *   - When the actor holds BOTH Capability::PROJECTS_VIEW and
 *     Capability::CLUSTER_TREE_VIEW on actor.organization_id, the strict
 *     same-org floor widens to include descendant organizations via
 *     Organization::descendantIds() (BFS via parent_id, depth cap 32,
 *     fail-closed on cycle).
 *   - Cluster widening is ADDITIVE: when both grants are held, the
 *     actor.org floor is replaced with [actor.org] + descendants; the
 *     remaining OR-logic (direct relations, engine grants, governed
 *     types, flat-ladder widening) is preserved as-is.
 *   - Per CFA-00 owner decision (2026-07-09): NO project role/member
 *     assignment widening (CFA-04 only widens read + governance writes,
 *     not member assignment).
 *   - Per CFA-00 owner decision: writes (update / delete / create /
 *     assignProjectRoles) stay strict same-org; only status / PDCA
 *     transitions widen via PROJECTS_EDIT + CLUSTER_TREE_MANAGE (handled
 *     in ProjectPolicy, not here).
 */
class UserProjectScope
{
    /**
     * تطبيق فلتر الصلاحيات على استعلام المشاريع
     */
    public function apply(Builder $query, User $user): Builder
    {
        if ($user->isSuperAdmin()) {
            return $query;
        }

        // Phase CFA-04 — Cluster widening floor.
        // Default: strict same-org via actor.organization_id. When the actor
        // holds BOTH PROJECTS_VIEW + CLUSTER_TREE_VIEW on actor.org, the floor
        // widens to include descendant organizations (via parent_id BFS).
        // The remainder of the OR-logic below is preserved unchanged.
        $visibleOrgIds = $this->clusterVisibleOrgIds($user);
        if ($visibleOrgIds !== []) {
            $query->whereIn('organization_id', $visibleOrgIds);
        }

        // سقف الرؤية المسطّح هو المرجع حين توجد صلاحية مسطّحة: من يملك صلاحية على
        // السلّم (own/department/all) تحدّد صلاحيته سقفَ مداه — لا يوسّعه الجسر
        // الوظيفي على مستوى المؤسسة (الذي قد يحمل can_view_all مخلَّفاً من ترحيل
        // أدوار قديمة) فوق ذلك السقف. الجسر يعمل فقط حين لا صلاحية مسطّحة إطلاقاً.
        // ponytail: السلّم المسطّح القديم يترجم إلى منح المحرّك — own=project scopes،
        // department=dept scopes، all=org-level grant. منح المحرّك أوسع من السلّم
        // القديم لأن grant على القسم يشمل الشجرة الهابطة؛ السلوك الجديد متعمَّد.
        // Canonical organization and all-scope assignments establish the
        // engine-wide visibility ceiling.
        $engineScopes = $this->canonicalGrantingScopes($user, Capability::PROJECTS_VIEW);
        $engineGrantsOrg = ! empty($engineScopes['organization'] ?? [])
            || ! empty($engineScopes['all'] ?? []);
        $hasFlatAll = $engineGrantsOrg || ! empty($engineScopes['organization'] ?? []);
        $hasFlatDept = ! empty($engineScopes['department'] ?? []);
        $hasFlatOwn = ! empty($engineScopes['project'] ?? []);
        $hasAnyFlat = $hasFlatAll || $hasFlatDept || $hasFlatOwn;

        // وصول كامل المؤسسة: view_projects المسطّحة، أو — حين لا صلاحية مسطّحة —
        // دور وظيفي على مستوى المؤسسة يمنح projects.view. العزل أعلاه يبقى محكماً.
        if ($hasFlatAll
            || (! $hasAnyFlat && $engineGrantsOrg)) {
            return $query;
        }

        // رؤية القسم المُشرِّع على النوع: عضو القسم المُشرِّع يرى كل مشاريع نوعه في
        // المؤسسة. ومكتب إدارة المشاريع (المُشرِّع على النوع 'development') يشرف على
        // المحفظة كاملةً فيرى كل المشاريع بكل أنواعها.
        $governedTypes = app(ProjectAuthorizationService::class)->governedTypes($user);
        if (in_array('development', $governedTypes, true)) {
            return $query;
        }

        // وإلا: نطاق إضافي (OR) = ارتباط مباشر + منح المحرّك (قسم/مشروع) + توسعة
        // السلّم المسطّح على مستوى القسم + نوع يُشرِف عليه. أي واحد منها يكفي للرؤية.
        $deptIds = [];

        // توسعة view_department_projects: قسم المستخدم وشجرته الهابطة.
        if ($hasFlatDept && $user->department_id) {
            $deptIds = AccessDecision::subtreeDepartmentIds([$user->department_id]);
        }

        // الأقسام/المشاريع التي تمنحها أدوار المستخدم السياقية الرؤية (الموقع الصاعد).
        $scopes = $engineScopes;
        $roleDeptIds = AccessDecision::subtreeDepartmentIds($scopes['department'] ?? []);
        $projectIds = $scopes['project'] ?? [];

        $allDeptIds = array_values(array_unique(array_merge($deptIds, $roleDeptIds)));

        return $query->where(function (Builder $q) use ($user, $allDeptIds, $projectIds, $governedTypes) {
            $this->whereDirectlyRelated($q, $user);

            if ($allDeptIds !== []) {
                $q->orWhereIn('department_id', $allDeptIds);
            }
            if ($projectIds !== []) {
                $q->orWhereIn('id', $projectIds);
            }
            // Governed types (e.g. Quality oversees 'improvement') are visible
            // org-wide; org isolation is already applied above.
            if ($governedTypes !== []) {
                $q->orWhereIn('type', $governedTypes);
            }
        });
    }

    /**
     * الارتباط المباشر بالمشروع: منشئ/عضو/دور سياقي/صاحب مصلحة/مكلّف بمهمة.
     */
    protected function whereDirectlyRelated(Builder $q, User $user): void
    {
        $q->where('created_by', $user->id)
            ->orWhereHas('members', fn (Builder $m) => $m->where('user_id', $user->id))
            ->orWhereHas('stakeholders', fn (Builder $s) => $s->where('user_id', $user->id))
            ->orWhereHas('tasks', fn (Builder $t) => $t->where('assigned_to', $user->id));
    }

    /** @return array<string, list<int>> */
    private function canonicalGrantingScopes(User $user, string $capability): array
    {
        $mapping = CapabilityToAuthorizationRolePermission::map($capability);
        if ($mapping === null) {
            return [];
        }

        $resourceId = AuthorizationResource::query()->where('key', $mapping['resource'])->value('id');
        if ($resourceId === null) {
            return [];
        }

        $assignments = AuthorizationRoleAssignment::query()
            ->join('authorization_roles', 'authorization_roles.id', '=', 'authorization_role_assignments.authorization_role_id')
            ->join('authorization_role_permissions', function ($join) use ($resourceId, $mapping) {
                $join->on('authorization_role_permissions.authorization_role_id', '=', 'authorization_role_assignments.authorization_role_id')
                    ->where('authorization_role_permissions.authorization_resource_id', '=', $resourceId)
                    ->where('authorization_role_permissions.action', '=', $mapping['action']);
            })
            ->where('authorization_role_assignments.user_id', $user->id)
            ->where('authorization_roles.is_active', true)
            ->where(function ($query) {
                $query->whereNull('authorization_role_assignments.expires_at')
                    ->orWhere('authorization_role_assignments.expires_at', '>', now());
            })
            // CSD-CA23078-PROJECTS-001 / CSD-CA23078-CORE-002 — stale-org filter.
            // Mirrors AccessDecision::canonicalListAssignmentMatchesUserOrganization:
            // drop rows whose organization_id is non-null and NOT equal to the
            // user's current organization_id. Without this filter, a user moved
            // from Org A to Org B keeps seeing A-org projects via the flat-all
            // ladder widened by a stale A-scoped assignment.
            //
            // Exception (matches the canonical rule's scope_type='all' branch):
            // when the actor (the user being evaluated) is a canonical super_admin,
            // scope_type='all' rows pass even if their organization_id is stale.
            // super_admin already short-circuits in apply() so the exception
            // rarely fires in practice, but mirroring the engine keeps the read
            // path and the canonical grant evaluation consistent.
            ->where(function ($query) use ($user) {
                $query->whereNull('authorization_role_assignments.organization_id')
                    ->orWhere('authorization_role_assignments.organization_id', $user->organization_id);
                if ($user->isSuperAdmin()) {
                    $query->orWhere('authorization_role_assignments.scope_type', 'all');
                }
            })
            ->select([
                'authorization_role_assignments.scope_type',
                'authorization_role_assignments.scope_id',
                'authorization_role_assignments.organization_id',
                'authorization_role_permissions.reach',
            ])
            ->get();

        $out = [];
        foreach ($assignments as $assignment) {
            $reachMap = is_array($assignment->reach)
                ? $assignment->reach
                : (json_decode((string) $assignment->reach, true) ?: []);
            $reach = $reachMap['projects'] ?? 'all';
            if ($reach === 'own') {
                continue;
            }

            $scopeType = (string) $assignment->scope_type;
            $scopeId = $assignment->scope_id === null ? null : (int) $assignment->scope_id;
            if ($scopeType === 'all') {
                $out['all'][] = 0;
            } elseif ($scopeType === 'organization' && $reach === 'department' && $user->department_id !== null) {
                $out['department'][] = (int) $user->department_id;
            } elseif ($scopeId !== null) {
                $out[$scopeType][] = $scopeId;
            }
        }

        return array_map(fn (array $ids) => array_values(array_unique($ids)), $out);
    }

    /**
     * نسخة مطابقة للاستعلامات المتداخلة (whereHas('project')). تستخدم نفس منطق
     * السلّم؛ موجودة للحفاظ على واجهة applySimple التي يعتمدها UserTaskScope.
     */
    public function applySimple(Builder $query, User $user): Builder
    {
        return $this->apply($query, $user);
    }

    /**
     * Phase CFA-04 — Org floor for the cluster_tree widening (read-only).
     *
     * Returns the list of organization ids the actor may see under the
     * cluster_tree policy for Projects reads.
     *
     *   - Default: [actor.organization_id] only (strict same-org) when
     *     EITHER PROJECTS_VIEW or CLUSTER_TREE_VIEW is missing on actor.org.
     *     Preserves the pre-CFA-04 same-org behavior for users who do not
     *     hold both grants — the strict-equality gate remains in force.
     *
     *   - Widening (read-only): when the actor holds BOTH
     *     Capability::PROJECTS_VIEW + Capability::CLUSTER_TREE_VIEW on
     *     actor.organization_id, descendant organizations (via parent_id
     *     BFS) are added to the list. CFA-04 is read-only — no widening
     *     to write paths (CRUD stays strict same-org per CFA-00 owner
     *     decision).
     *
     * Returns an empty array for null-org actors — the engine then fails
     * closed at the strict-equality gate. super_admin is short-circuited
     * earlier in apply() and never reaches this helper.
     *
     * @return list<int>
     */
    protected function clusterVisibleOrgIds(User $user): array
    {
        if ($user->organization_id === null) {
            return [];
        }

        $orgId = (int) $user->organization_id;
        $visible = [$orgId];

        // Both grants required to widen cluster_tree. Missing either ⇒ strict same-org.
        $hasProjectsView = AccessDecision::can($user, Capability::PROJECTS_VIEW);
        $hasClusterTreeView = AccessDecision::can($user, Capability::CLUSTER_TREE_VIEW);
        if (! $hasProjectsView || ! $hasClusterTreeView) {
            return $visible;
        }

        $org = Organization::query()->find($orgId);
        if (! $org instanceof Organization) {
            return $visible;
        }

        return array_values(array_unique(array_merge($visible, $org->descendantIds())));
    }
}
