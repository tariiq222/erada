<?php

namespace App\Modules\Core\Traits;

use App\Modules\Core\Models\ScopedRole;
use App\Modules\Projects\Models\Project;
use Illuminate\Support\Collection;

/**
 * HasProjectRoles Trait
 *
 * يوفر إدارة الأدوار على مستوى المشاريع
 */
trait HasProjectRoles
{
    // ========== استعلامات أدوار المشاريع ==========

    /**
     * الحصول على دور المستخدم في مشروع معين
     */
    public function roleInProject(int|Project $project): ?string
    {
        $projectId = $project instanceof Project ? $project->id : $project;

        $role = $this->activeScopedRoles()
            ->inScope(ScopedRole::SCOPE_PROJECT, $projectId)
            ->first();

        return $role?->role;
    }

    /**
     * هل لديه دور في المشروع؟
     */
    public function hasRoleInProject(int|Project $project, string|array|null $roles = null): bool
    {
        $userRole = $this->roleInProject($project);

        if (! $userRole) {
            return false;
        }

        if ($roles === null) {
            return true;
        }

        $roles = is_array($roles) ? $roles : [$roles];

        return in_array($userRole, $roles);
    }

    /**
     * هل هو مدير/مشرف في المشروع؟
     */
    public function isProjectAdmin(int|Project $project): bool
    {
        $role = $this->roleInProject($project);

        return $role && ScopedRole::isProjectAdminRole($role);
    }

    /**
     * الحصول على جميع المشاريع التي لديه دور فيها
     */
    public function getProjectsWithRoles(): Collection
    {
        $projectIds = $this->activeScopedRoles()
            ->ofType(ScopedRole::SCOPE_PROJECT)
            ->pluck('scope_id');

        return Project::whereIn('id', $projectIds)->get();
    }

    // ========== تعيين وإزالة أدوار المشاريع ==========

    /**
     * تعيين دور في مشروع
     */
    public function assignProjectRole(
        int|Project $project,
        string $role,
        ?int $grantedBy = null,
        ?\DateTimeInterface $expiresAt = null
    ): ScopedRole {
        $projectId = $project instanceof Project ? $project->id : $project;

        return $this->assignScopedRole(
            $role,
            ScopedRole::SCOPE_PROJECT,
            $projectId,
            $grantedBy,
            false, // المشاريع لا تدعم الوراثة
            $expiresAt
        );
    }

    /**
     * إزالة دور من مشروع
     */
    public function revokeProjectRole(int|Project $project): bool
    {
        $projectId = $project instanceof Project ? $project->id : $project;

        return $this->revokeScopedRole(ScopedRole::SCOPE_PROJECT, $projectId);
    }
}
