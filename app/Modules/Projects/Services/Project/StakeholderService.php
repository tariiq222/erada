<?php

namespace App\Modules\Projects\Services\Project;

use App\Modules\Core\Models\ScopedRole;
use App\Modules\Core\Models\User;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\Stakeholder;

class StakeholderService
{
    /**
     * القيم المسموحة للـ role (للتوافق مع check constraint في قاعدة البيانات)
     */
    protected const ALLOWED_ROLES = ['end_user', 'implementer', 'consultant', 'governance', 'operations', 'influencer', 'other'];

    /**
     * إنشاء أصحاب المصلحة لمشروع
     */
    public function createStakeholders(Project $project, array $stakeholders): void
    {
        foreach ($stakeholders as $stakeholderData) {
            $name = trim($stakeholderData['name'] ?? '');
            if (empty($name)) {
                continue;
            }

            $this->createStakeholder($project, $stakeholderData);
        }
    }

    /**
     * إنشاء صاحب مصلحة واحد
     */
    public function createStakeholder(Project $project, array $data): Stakeholder
    {
        $role = trim($data['role'] ?? '');
        if (empty($role) || ! in_array($role, self::ALLOWED_ROLES)) {
            $role = 'other';
        }

        return $project->stakeholders()->create([
            'user_id' => $data['user_id'] ?? null,
            'name' => trim($data['name']),
            'role' => $role,
            'email' => $data['contact'] ?? $data['email'] ?? null,
            'influence' => $data['influence'] ?? 'medium',
        ]);
    }

    /**
     * تحديث صاحب مصلحة
     */
    public function updateStakeholder(Stakeholder $stakeholder, array $data): Stakeholder
    {
        $updateData = array_filter([
            'name' => isset($data['name']) ? trim($data['name']) : null,
            'role' => isset($data['role']) && in_array($data['role'], self::ALLOWED_ROLES) ? $data['role'] : null,
            'email' => $data['contact'] ?? $data['email'] ?? null,
            'influence' => $data['influence'] ?? null,
        ], fn ($value) => $value !== null);

        $stakeholder->update($updateData);

        return $stakeholder->fresh();
    }

    /**
     * حذف صاحب مصلحة
     */
    public function deleteStakeholder(Stakeholder $stakeholder): bool
    {
        return $stakeholder->delete();
    }

    /**
     * استبدال جميع أصحاب المصلحة (حذف القدامى وإضافة الجدد)
     */
    public function replaceStakeholders(Project $project, array $stakeholders): void
    {
        $project->stakeholders()->delete();
        $this->createStakeholders($project, $stakeholders);
    }

    /**
     * إضافة الراعي والقائد والمشرف كأصحاب مصلحة
     */
    public function addProjectLeadersAsStakeholders(Project $project): array
    {
        $existingStakeholderUserIds = $project->stakeholders()
            ->whereNotNull('user_id')
            ->pluck('user_id')
            ->toArray();

        $managerIds = $project->members()
            ->wherePivot('role', ScopedRole::PROJECT_MANAGER)
            ->pluck('users.id')
            ->all();

        if (empty($managerIds)) {
            return $existingStakeholderUserIds;
        }

        $usersToAdd = User::whereIn('id', $managerIds)
            ->whereNotIn('id', $existingStakeholderUserIds)
            ->get();

        foreach ($usersToAdd as $user) {
            $project->stakeholders()->create([
                'user_id' => $user->id,
                'name' => $user->name,
                'role' => 'implementer',
                'email' => $user->email,
                'influence' => 'high',
            ]);
            $existingStakeholderUserIds[] = $user->id;
        }

        return $existingStakeholderUserIds;
    }

    /**
     * الحصول على أصحاب المصلحة ذوي التأثير العالي
     */
    public function getHighInfluenceStakeholders(Project $project)
    {
        return $project->stakeholders()
            ->where('influence', 'high')
            ->get();
    }

    /**
     * الحصول على أصحاب المصلحة حسب الدور
     */
    public function getStakeholdersByRole(Project $project, string $role)
    {
        return $project->stakeholders()
            ->where('role', $role)
            ->get();
    }
}
