<?php

namespace App\Modules\Projects\Services\Project;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Models\ScopedRole;
use App\Modules\Core\Models\User;
use App\Modules\Projects\Exceptions\ProjectMemberAlreadyExistsException;
use App\Modules\Projects\Models\Project;
use Illuminate\Database\Eloquent\Collection;

class TeamService
{
    /**
     * تحويل الأدوار العربية إلى القيم المقبولة في قاعدة البيانات
     */
    protected const ROLE_MAPPING = [
        'مطور' => 'member',
        'محلل' => 'member',
        'مصمم' => 'member',
        'مختبر' => 'member',
        'قائد فريق' => 'manager',
        'مدير' => 'manager',
        'عضو' => 'member',
        'member' => 'member',
        'manager' => 'manager',
        'viewer' => 'viewer',
    ];

    /**
     * Resolve a free-text role label (Arabic or English) to the canonical
     * ScopedRole::PROJECT_* constant. Single source of truth for callers that
     * need the constant for downstream checks (e.g. the manager-escalation guard).
     */
    public static function resolveRoleConstant(string $rawRole): string
    {
        $resolved = self::ROLE_MAPPING[$rawRole] ?? 'member';

        return match ($resolved) {
            'manager' => ScopedRole::PROJECT_MANAGER,
            'viewer' => ScopedRole::PROJECT_VIEWER,
            default => ScopedRole::PROJECT_MEMBER,
        };
    }

    /**
     * إضافة أعضاء الفريق لمشروع
     */
    public function createTeamMembers(Project $project, array $teamMembers): void
    {
        foreach ($teamMembers as $memberData) {
            // During project creation the creator is already attached as the
            // scoped manager, so a duplicate in the supplied team list is
            // expected and tolerated here (addMember itself stays strict for
            // the direct add-member API).
            $userId = $memberData['user_id'] ?? null;
            if ($userId && $project->members()->where('user_id', $userId)->exists()) {
                continue;
            }

            $this->addMember($project, $memberData);
        }
    }

    /**
     * إضافة عضو واحد للفريق
     *
     * يُرمى ProjectMemberAlreadyExistsException عند محاولة إضافة عضو موجود مسبقاً،
     * وInvalidArgumentException عند تمرير دور غير معروف.
     */
    public function addMember(Project $project, array $data): bool
    {
        $userId = $data['user_id'] ?? null;
        if (empty($userId)) {
            return false;
        }

        if ($project->members()->where('user_id', $userId)->exists()) {
            throw new ProjectMemberAlreadyExistsException;
        }

        $rawRole = $data['role'] ?? 'member';
        if (! array_key_exists($rawRole, self::ROLE_MAPPING)) {
            throw new \InvalidArgumentException("دور غير معروف: {$rawRole}");
        }
        $mapped = self::ROLE_MAPPING[$rawRole];

        $user = User::find($userId);
        if (! $user) {
            return false;
        }

        $user->assignProjectRole($project, $mapped);

        return true;
    }

    /**
     * استبدال جميع أعضاء الفريق (حذف القدامى وإضافة الجدد)
     */
    public function replaceTeamMembers(Project $project, array $teamMembers): void
    {
        $project->scopedRoles()->whereIn('role', ['member', 'viewer'])->delete();

        // Mass delete bypasses ScopedRole model events; flush the decision cache.
        AccessDecision::flushCache();

        $this->createTeamMembers($project, $teamMembers);
    }

    /**
     * تحديث دور عضو في المشروع
     *
     * يُرجع false إذا المستخدم غير موجود أو ليس عضواً في المشروع.
     */
    public function updateMemberRole(Project $project, int $userId, string $role): bool
    {
        $user = User::find($userId);
        if (! $user) {
            return false;
        }

        if (! $project->members()->where('user_id', $userId)->exists()) {
            return false;
        }

        $mapped = self::ROLE_MAPPING[$role] ?? null;
        if ($mapped === null) {
            throw new \InvalidArgumentException("دور غير معروف: {$role}");
        }

        $constant = self::resolveRoleConstant($mapped);
        $user->assignProjectRole($project, $constant);

        return true;
    }

    /**
     * إزالة عضو من المشروع
     *
     * يُرجع false إذا المستخدم غير موجود أو ليس عضواً في المشروع.
     * بعد التوحيد: المدير دور سياقي أيضاً، فيُزال عبر revokeProjectRole.
     */
    public function removeMember(Project $project, int $userId): bool
    {
        $user = User::find($userId);
        if (! $user) {
            return false;
        }

        if (! $project->members()->where('user_id', $userId)->exists()) {
            return false;
        }

        return $user->revokeProjectRole($project);
    }

    /**
     * الأعضاء الذين يحملون دوراً معيناً في المشروع
     */
    public function getMembersByRole(Project $project, string $role): Collection
    {
        return $project->members()->wherePivot('role', $role)->get();
    }

    /**
     * عدد أعضاء المشروع
     */
    public function getMembersCount(Project $project): int
    {
        return $project->members()->count();
    }

    /**
     * هل المستخدم عضو في المشروع؟
     */
    public function isMember(Project $project, int $userId): bool
    {
        return $project->members()->where('user_id', $userId)->exists();
    }
}
