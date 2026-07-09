<?php

namespace App\Modules\Core\Policies;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\RoleHierarchy;
use Illuminate\Auth\Access\HandlesAuthorization;

class UserPolicy
{
    use HandlesAuthorization;

    /**
     * Super Admin يتجاوز كل الصلاحيات
     *
     * super_admin already short-circuits inside AccessDecision::can() (step 1 of
     * whyCan), so this before() is a belt-and-braces bypass for any call site
     * that invokes the policy method without going through the engine. Keeping
     * it means a stale `User->can('update', $other)` from a feature test or
     * non-engine path still fails closed if the engine is removed later.
     */
    public function before(User $user, string $ability): ?bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return null;
    }

    /**
     * عرض قائمة المستخدمين
     *
     * Engine handles super_admin bypass + role mapping at the organization level
     * (admin functional role grants USERS_VIEW). No model target: capability is
     * evaluated against organization-scoped roles only.
     */
    public function viewAny(User $user): bool
    {
        return AccessDecision::can($user, Capability::USERS_VIEW);
    }

    /**
     * عرض مستخدم معين
     */
    public function view(User $user, User $model): bool
    {
        // المستخدم يرى نفسه
        if ($user->id === $model->id) {
            return true;
        }

        // super_admin already bypassed above; engine handles the role grant.
        if (! AccessDecision::can($user, Capability::USERS_VIEW)) {
            return false;
        }

        // User model is NOT ScopeAware — the engine cannot derive organization_id
        // from it via the scope chain. We must gate cross-org access explicitly so
        // a user with USERS_VIEW in org A cannot read org B's users. The engine
        // already validated grants; this layer enforces isolation.
        return $this->belongsToUserOrganization($user, $model);
    }

    /**
     * إنشاء مستخدم
     */
    public function create(User $user): bool
    {
        return AccessDecision::can($user, Capability::USERS_CREATE);
    }

    /**
     * تعديل مستخدم
     */
    public function update(User $user, User $model): bool
    {
        // المستخدم يعدل نفسه
        if ($user->id === $model->id) {
            return true;
        }

        if (! AccessDecision::can($user, Capability::USERS_EDIT)) {
            return false;
        }

        // لا يمكن لغير Super Admin تعديل Super Admin آخر — defense-in-depth:
        // super_admin already short-circuits in before(), so this branch is only
        // reachable for non-super_admin, and they must not edit another super_admin.
        if ($model->isSuperAdmin()) {
            return false;
        }

        // كل مستخدم غير Super Admin يعدل مستخدمي مؤسسته فقط
        return $this->belongsToUserOrganization($user, $model);
    }

    /**
     * حذف مستخدم
     */
    public function delete(User $user, User $model): bool
    {
        // لا يمكن حذف نفسك
        if ($user->id === $model->id) {
            return false;
        }

        if (! AccessDecision::can($user, Capability::USERS_DELETE)) {
            return false;
        }

        // لا يمكن لغير Super Admin حذف Super Admin
        if ($model->isSuperAdmin()) {
            return false;
        }

        // كل مستخدم غير Super Admin يحذف مستخدمي مؤسسته فقط
        return $this->belongsToUserOrganization($user, $model);
    }

    /**
     * استعادة مستخدم محذوف
     */
    public function restore(User $user, User $model): bool
    {
        return $this->delete($user, $model);
    }

    /**
     * حذف نهائي
     */
    public function forceDelete(User $user, User $model): bool
    {
        return false; // لا يمكن الحذف النهائي للمستخدمين
    }

    /**
     * هل يمكن للمُعطي تعيين الأدوار المطلوبة؟
     *
     * super_admin و admin يستطيعان تعيين أي دور.
     * غيرهم يستطيعون تعيين الأدوار الأدنى من دورهم فقط (لا المساوي ولا الأعلى).
     */
    public function canAssignRole(User $actor, User $model, array $roles): bool
    {
        return RoleHierarchy::canAssignAll($actor, $roles);
    }

    /**
     * Phase CFA-07 - Cluster limited directory read gate (HIGH PII).
     *
     * Decoupled from `view()` on purpose: `view()` keeps its existing
     * same-org semantics (returns false for any descendant-org target),
     * and `viewDirectory()` is the SOLE authz seam for cluster widening to
     * a directory-shape response (UserDirectoryResource). Returning true
     * here does NOT enable writes, role assignment, or UserResource - it
     * only routes the read through the dedicated directory resource.
     *
     * Two-path rescue:
     *   - Capability::USERS_VIEW on actor.organization_id (the actor
     *     module capability - never implied by cluster_tree alone).
     *   - Capability::CLUSTER_TREE_VIEW on actor.organization_id (the
     *     cluster_tree primitive - read-only, no implicit widening).
     *   - actor.organization_id MUST be an ancestor of target.organization_id
     *     via the parent_id walk (depth cap 32, fail-closed on cycle).
     *   - super_admin short-circuits to true (the bypass is local to
     *     this method for call sites that skip before()).
     *   - null-org actor => false (fail-closed, matches the engine convention).
     *   - siblings are isolated by the ancestor walk (one-directional).
     *
     * This is a TWO-PATH grant: the actor must satisfy BOTH the module cap
     * and the cluster_tree primitive. Either alone returns false. CFA-00
     * stop conditions apply unchanged (no field leaks, no write widening).
     */
    public function viewDirectory(User $user, User $model): bool
    {
        // super_admin already short-circuited in before() - the explicit branch
        // here is a belt-and-braces fallback for call sites that skip before()
        // (e.g. ad-hoc `$user->can('viewDirectory', $target)` outside the engine).
        if ($user->isSuperAdmin()) {
            return true;
        }

        return self::directoryClusterAdmitted($user, $model);
    }

    /**
     * التحقق من انتماء المستخدم لنفس المؤسسة
     *
     * User is not ScopeAware, so the engine's organization-isolation gate cannot
     * resolve a target organization_id for a User model. Every per-record User
     * policy method that is NOT "self" MUST call this helper, or a viewer in
     * org A could enumerate org B's users despite having no scoped role on them.
     */
    private function belongsToUserOrganization(User $user, User $model): bool
    {
        if ($user->organization_id === null || $model->organization_id === null) {
            return false;
        }

        return $user->organization_id === $model->organization_id;
    }

    /**
     * Non-super_admin cluster directory admissibility check (Phase CFA-07).
     *
     * Folds every prereq into a single boolean so viewDirectory() stays
     * readable. Per-condition reasons are documented inline at each guard.
     */
    private static function directoryClusterAdmitted(User $user, User $model): bool
    {
        // null-org on either side => fail-closed (engine convention).
        if ($user->organization_id === null || $model->organization_id === null) {
            return false;
        }

        // Same-org reads route through `view()` + UserResource unchanged; the
        // directory shape is reserved for cross-org cluster reads.
        if ((int) $user->organization_id === (int) $model->organization_id) {
            return false;
        }

        // Both grants must hold on actor.organization_id:
        //   - the actor module capability (USERS_VIEW), and
        //   - the cluster_tree primitive (CLUSTER_TREE_VIEW).
        // Either alone returns false - no implicit widening.
        if (! AccessDecision::can($user, Capability::USERS_VIEW)) {
            return false;
        }

        if (! AccessDecision::can($user, Capability::CLUSTER_TREE_VIEW)) {
            return false;
        }

        // Ancestor walk: actor.organization_id must appear in target.organization_id's
        // ancestor chain via parent_id (depth cap 32, cycle-guarded by
        // HasOrganizationHierarchy::ancestorIds()). Sibling orgs and unrelated
        // orgs are isolated by the same walk.
        $targetOrg = Organization::query()->find((int) $model->organization_id);
        if (! $targetOrg instanceof Organization) {
            return false;
        }

        return in_array((int) $user->organization_id, $targetOrg->ancestorIds(), true);
    }
}
