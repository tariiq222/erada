<?php

namespace App\Modules\Core\Support;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\User;

class RoleHierarchy
{
    public const SUPER_ADMIN = 'super_admin';

    public const ADMIN = 'admin';

    public const VIEWER = 'viewer';

    /**
     * Hierarchy levels — higher value = more authority.
     * super_admin (3) > admin (2) > viewer (1).
     *
     * ملاحظة: أدوار المشاريع (مدير/عضو) لم تعد أدوار نظام — تُسنَد كأدوار
     * سياقية (scoped roles) على مستوى المشروع.
     *
     * @var array<string, int>
     */
    public const LEVELS = [
        self::SUPER_ADMIN => 3,
    ];

    public static function level(string $roleName): int
    {
        return self::LEVELS[$roleName] ?? 0;
    }

    public static function highestLevel(User $user): int
    {
        $max = 0;
        foreach ($user->canonicalRoleNames() as $role) {
            $max = max($max, self::level($role));
        }

        return $max;
    }

    public static function isSuperAdmin(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public static function isAdmin(User $user): bool
    {
        // تير الإدارة مقاد بالصلاحية لا باسم الدور (بعد حذف دور admin).
        return AccessDecision::canonicalTrace($user, Capability::SETTINGS_MANAGE)['granted'];
    }

    /**
     * Whether the actor may grant the target role.
     *
     * super_admin may grant any role. admin may grant any role EXCEPT
     * super_admin (which is reserved for super_admin). Everyone else
     * (project_manager, member, viewer) may only grant roles STRICTLY BELOW
     * their own highest level (so a project_manager cannot grant
     * project_manager, admin, or super_admin — only member/viewer).
     */
    public static function canAssignTo(User $actor, string $targetRole): bool
    {
        if (self::isSuperAdmin($actor)) {
            return true;
        }

        if (self::isAdmin($actor) && $targetRole !== self::SUPER_ADMIN) {
            return true;
        }

        return self::highestLevel($actor) > self::level($targetRole);
    }

    /**
     * @param  array<int, string>  $targetRoles
     */
    public static function canAssignAll(User $actor, array $targetRoles): bool
    {
        foreach ($targetRoles as $role) {
            if (! self::canAssignTo($actor, $role)) {
                return false;
            }
        }

        return true;
    }
}
