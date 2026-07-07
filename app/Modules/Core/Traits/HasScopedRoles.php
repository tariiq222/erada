<?php

namespace App\Modules\Core\Traits;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Models\ScopedRole;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * HasScopedRoles Trait
 *
 * يوفر إدارة الأدوار السياقية للمستخدمين
 * - أدوار على مستوى المشروع (HasProjectRoles)
 * - أدوار على مستوى القسم مع وراثة هرمية (HasDepartmentRoles)
 * - أدوار على مستوى المؤسسة
 */
trait HasScopedRoles
{
    use HasDepartmentRoles;
    use HasProjectRoles;

    // ========== العلاقات ==========

    /**
     * جميع الأدوار السياقية للمستخدم
     */
    public function scopedRoles(): HasMany
    {
        return $this->hasMany(ScopedRole::class, 'user_id');
    }

    /**
     * الأدوار السارية فقط (غير منتهية)
     */
    public function activeScopedRoles(): HasMany
    {
        return $this->scopedRoles()->active();
    }

    // ========== أدوار المؤسسة ==========

    /**
     * الحصول على دور المستخدم في المؤسسة
     */
    public function roleInOrganization(int $organizationId): ?string
    {
        $role = $this->activeScopedRoles()
            ->inScope(ScopedRole::SCOPE_ORGANIZATION, $organizationId)
            ->first();

        return $role?->role;
    }

    // ========== تعيين وإزالة الأدوار العامة ==========

    /**
     * تعيين دور للمستخدم في سياق معين
     */
    public function assignScopedRole(
        string $role,
        string $scopeType,
        int $scopeId,
        ?int $grantedBy = null,
        bool $inheritToChildren = true,
        ?\DateTimeInterface $expiresAt = null
    ): ScopedRole {
        // حذف أي دور سابق في نفس السياق
        $this->revokeScopedRole($scopeType, $scopeId);

        return ScopedRole::create([
            'user_id' => $this->id,
            'role' => $role,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'inherit_to_children' => $inheritToChildren,
            'granted_by' => $grantedBy ?? auth()->id(),
            'expires_at' => $expiresAt,
        ]);
    }

    /**
     * إزالة دور من سياق معين
     */
    public function revokeScopedRole(string $scopeType, int $scopeId): bool
    {
        $deleted = $this->scopedRoles()
            ->where('scope_type', $scopeType)
            ->where('scope_id', $scopeId)
            ->delete() > 0;

        // Mass delete bypasses ScopedRole model events; flush the decision cache.
        AccessDecision::flushUserCache((int) $this->id);

        return $deleted;
    }

    // ========== Source-aware automatic grants ==========
    //
    // These helpers power the capacity-aware department-role automation. They are
    // additive and never touch source='manual' rows. assignScopedRole() above is
    // NOT reused here because it deletes any prior role on the scope (single-role
    // semantics) and would clobber a manual delegation.

    /**
     * Idempotently grant a role on a scope as source='auto'.
     * No-op if ANY row already exists for (role, scope_type, scope_id) -- this is
     * source-agnostic on purpose, so a manual row shadows and protects the grant
     * (the unique key is user_id+role+scope_type+scope_id, source is not in it).
     */
    public function grantAutoScopedRole(string $role, string $scopeType, int $scopeId): void
    {
        $exists = $this->scopedRoles()
            ->where('role', $role)
            ->where('scope_type', $scopeType)
            ->where('scope_id', $scopeId)
            ->exists();

        if ($exists) {
            return;
        }

        $this->scopedRoles()->create([
            'role' => $role,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'inherit_to_children' => true,
            'source' => 'auto',
            'granted_by' => null,
        ]);
    }

    /**
     * Ensure exactly $roleKeys exist as source='auto' on the given scope:
     * delete auto rows on that scope not in $roleKeys, then grant the missing ones.
     * source='manual' rows are never touched.
     */
    public function syncAutoScopedRolesForScope(string $scopeType, int $scopeId, array $roleKeys): void
    {
        // remove auto rows on this scope that are no longer expected (manual untouched)
        $this->scopedRoles()
            ->where('source', 'auto')
            ->where('scope_type', $scopeType)
            ->where('scope_id', $scopeId)
            ->whereNotIn('role', $roleKeys ?: ['__none__'])
            ->delete();

        // Mass delete bypasses ScopedRole model events; flush the decision cache.
        AccessDecision::flushUserCache((int) $this->id);

        foreach ($roleKeys as $role) {
            $this->grantAutoScopedRole($role, $scopeType, $scopeId);
        }
    }

    /**
     * Remove every source='auto' role on the given scope. Manual rows are kept.
     */
    public function revokeAutoScopedRolesForScope(string $scopeType, int $scopeId): void
    {
        $this->scopedRoles()
            ->where('source', 'auto')
            ->where('scope_type', $scopeType)
            ->where('scope_id', $scopeId)
            ->delete();

        // Mass delete bypasses ScopedRole model events; flush the decision cache.
        AccessDecision::flushUserCache((int) $this->id);
    }

    // ========== Helpers ==========

    /**
     * الحصول على جميع الأدوار السياقية للعرض
     */
    public function getAllScopedRolesForDisplay(): array
    {
        $roles = [
            'projects' => [],
            'departments' => [],
        ];

        foreach ($this->activeScopedRoles as $scopedRole) {
            $item = [
                'role' => $scopedRole->role,
                'role_display' => $scopedRole->display_name,
                'scope_id' => $scopedRole->scope_id,
                'expires_at' => $scopedRole->expires_at?->toISOString(),
                'inherit_to_children' => $scopedRole->inherit_to_children,
            ];

            if ($scopedRole->scope_type === ScopedRole::SCOPE_PROJECT) {
                $roles['projects'][] = $item;
            } elseif ($scopedRole->scope_type === ScopedRole::SCOPE_DEPARTMENT) {
                $roles['departments'][] = $item;
            }
        }

        return $roles;
    }
}
