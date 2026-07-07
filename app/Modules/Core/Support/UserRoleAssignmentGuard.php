<?php

namespace App\Modules\Core\Support;

use App\Modules\Core\Models\User;
use App\Modules\Core\Rules\AssignableRoleKey;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * UserRoleAssignmentGuard - نقطة التحقق الموحّدة لتعيين أدوار المستخدمين.
 *
 * Phase 3 (minimal-risk): يستبدل الفحوصات اليدوية المكرّرة في:
 *   - UserController::store / update (defense-in-depth قبل applyRoleAssignment)
 *   - RoleController::assignToUser (قبل applyRoleAssignment)
 *
 * القواعد (الترتيب مقصود):
 *   1. super_admin escalation: actor ليس super_admin لكن roles يحتوي super_admin ⇒ 403.
 *   2. super_admin bypass: actor super_admin ⇒ يسمح (وفق RoleHierarchy::canAssignAll).
 *   3. null-org actor ⇒ 403.
 *   4. cross-org target ⇒ 403.
 *   5. null-org target + actor ليس super_admin ⇒ 403.
 *   6. self-escalation: actor == target و roles يحتوي role أعلى من الحالي ⇒ 403.
 *   7. كل role يجب أن يكون قابلاً للتعيين (AssignableRoleKey) ⇒ 403 إن لم يكن.
 *   8. RoleHierarchy::canAssignAll ⇒ 403 إن رفض المصفوفة.
 *
 * لا يغيّر payload — يرفض بـ 403 أو يمر. لا يحذف super_admin بصمت.
 *
 * لا يزال FormRequest authorize() هو الـ AuthZ seam الرسمي (auth/access semantics).
 * هذا الـ Guard هو defense-in-depth + centralization layer فوقه.
 */
class UserRoleAssignmentGuard
{
    /**
     * فحص صلاحية actor لتعيين roles على target. يرمي AccessDeniedHttpException
     * عند أي فشل؛ لا يرمي عند النجاح.
     *
     * @param  array<int, string>  $roles  قائمة role_keys (Spatie names) المطلوب تعيينها
     */
    public function assertCanAssign(User $actor, User $target, array $roles): void
    {
        // empty ⇒ no-op (syncRoles([]) سيُفرغ الأدوار — هذا مقبول ويُترك للـ caller)
        if ($roles === []) {
            return;
        }

        // 1. super_admin escalation guard: لا يحذف من المصفوفة — يرفض بـ 403.
        if (in_array('super_admin', $roles, true) && ! $actor->isSuperAdmin()) {
            throw new AccessDeniedHttpException('فقط مدير النظام يمكنه تعيين دور super_admin');
        }

        // 2. super_admin bypass — يُسمح بكل ما هو متاح (وفق RoleHierarchy + AssignableRoleKey).
        if ($actor->isSuperAdmin()) {
            $this->assertRolesAreValid($roles);
            $this->assertRolesAllowedByHierarchy($actor, $roles);

            return;
        }

        // 3. actor بدون organization_id ⇒ fail-closed.
        if ($actor->organization_id === null) {
            throw new AccessDeniedHttpException('لا يمكن تعيين أدوار بدون مؤسسة');
        }

        // 4. cross-org: target في مؤسسة مختلفة ⇒ 403.
        if ($target->organization_id !== null
            && $target->organization_id !== $actor->organization_id) {
            throw new AccessDeniedHttpException('المستخدم خارج نطاق مؤسستك');
        }

        // 5. target null-org + actor ليس super_admin ⇒ 403.
        if ($target->organization_id === null) {
            throw new AccessDeniedHttpException('المستخدم الهدف بلا مؤسسة');
        }

        // 6. self-escalation: actor == target يحاول إضافة role بمستوى أعلى من
        //    أعلى مستوى حالي له. المقارنة عبر RoleHierarchy::level (عدد صحيح).
        //    super_admin يتجاوز لأن Bypass في الخطوة 2.
        if ($actor->id === $target->id) {
            $actorMaxLevel = RoleHierarchy::highestLevel($actor);
            foreach ($roles as $role) {
                if (RoleHierarchy::level($role) > $actorMaxLevel) {
                    throw new AccessDeniedHttpException('لا يمكن ترقية نفسك');
                }
            }
        }

        // 7. كل role يجب أن يكون قابلاً للتعيين (موجود كتعريف نشط أو compat role).
        $this->assertRolesAreValid($roles);

        // 8. RoleHierarchy escalation matrix (admin يرفض super_admin، viewer يرفض admin…).
        $this->assertRolesAllowedByHierarchy($actor, $roles);
    }

    /**
     * فحص أن كل role_key قابل للتعيين — يتحقق من وجوده في
     * scoped_role_definitions (org scope) أو ضمن COMPAT_SPATIE_ROLES.
     *
     * @param  array<int, string>  $roles
     */
    private function assertRolesAreValid(array $roles): void
    {
        foreach ($roles as $role) {
            if (! is_string($role) || trim($role) === '') {
                throw new AccessDeniedHttpException('الدور المحدد غير متاح.');
            }

            $rule = new AssignableRoleKey;
            $capturedMessage = null;

            // AssignableRoleKey->validate() يستدعي $fail($message) عند الرفض.
            // نلتقط الرسالة ونحوّلها إلى AccessDeniedHttpException لتوحيد شكل الـ 403.
            $rule->validate('roles.*', $role, function (string $message) use (&$capturedMessage) {
                $capturedMessage = $message;
            });

            if ($capturedMessage !== null) {
                throw new AccessDeniedHttpException($capturedMessage);
            }
        }
    }

    /**
     * فحص مصفوفة RoleHierarchy::canAssignAll — admin يرفض super_admin، إلخ.
     *
     * @param  array<int, string>  $roles
     */
    private function assertRolesAllowedByHierarchy(User $actor, array $roles): void
    {
        if (! RoleHierarchy::canAssignAll($actor, $roles)) {
            throw new AccessDeniedHttpException('لا تملك صلاحية تعيين أحد الأدوار المطلوبة');
        }
    }
}
