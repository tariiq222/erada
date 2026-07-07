<?php

namespace App\Modules\Core\Traits;

use App\Modules\Core\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * HasOrganizationHierarchy — Phase 9-B.
 *
 * يضيف لـ Organization:
 *   - relations: parent, children, activeChildren
 *   - scopes:    scopeRoots, scopeChildrenOf, scopeOfType
 *   - helpers:   isCluster / isHospital / isCenter / isStandaloneOrganization
 *               / isOther / hasChildren / isChildOf / isRoot / canHaveChildren
 *               / allowedChildTypes / canAcceptChildType / activeChildrenCount
 *
 * ملاحظة حاسمة — النطاق:
 * الـ trait يوفّر "هيكلة بيانات" فقط (org chart). **لا يطبّق ScopeAware** ولا
 * يمشي شجرة المنظمات داخل AccessDecision. الـ authorization engine لا يزال
 * strict equality على organization_id. أي visibility عبر hierarchy يتطلب
 * cluster_tree (مرحلة منفصلة، مؤجّلة).
 *
 * الـ constants (TYPE_*) تبقى على class Organization — هذا الـ trait للسلوك فقط.
 *
 * @phpstan-require-extends Organization
 */
trait HasOrganizationHierarchy
{
    // ========== Relations ==========

    /**
     * المؤسسة الأم (parent في الشجرة). null إذا كانت root.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * المؤسسات الفرعية المباشرة (كل الحالات، active و inactive).
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * المؤسسات الفرعية النشطة فقط (is_active=true).
     */
    public function activeChildren(): HasMany
    {
        return $this->children()->where('is_active', true);
    }

    // ========== Scopes ==========

    /**
     * المؤسسات الجذر (parent_id IS NULL).
     *
     * @param  Builder<Organization>  $query
     * @return Builder<Organization>
     */
    public function scopeRoots(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }

    /**
     * المؤسسات الفرعية المباشرة لمؤسسة معيّنة.
     *
     * @param  Builder<Organization>  $query
     * @return Builder<Organization>
     */
    public function scopeChildrenOf(Builder $query, int $parentId): Builder
    {
        return $query->where('parent_id', $parentId);
    }

    /**
     * فلترة حسب نوع المؤسسة.
     *
     * @param  Builder<Organization>  $query
     * @return Builder<Organization>
     */
    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    // ========== Type predicates ==========

    /**
     * هل هذه المؤسسة من نوع cluster (التجمع الصحي مثلاً)؟
     */
    public function isCluster(): bool
    {
        return $this->type === Organization::TYPE_CLUSTER;
    }

    /**
     * هل هذه المؤسسة مستشفى؟
     */
    public function isHospital(): bool
    {
        return $this->type === Organization::TYPE_HOSPITAL;
    }

    /**
     * هل هذه المؤسسة مركز صحي؟
     */
    public function isCenter(): bool
    {
        return $this->type === Organization::TYPE_CENTER;
    }

    /**
     * هل هذه مؤسسة standalone (organization type، بدون children policy)؟
     */
    public function isStandaloneOrganization(): bool
    {
        return $this->type === Organization::TYPE_ORGANIZATION;
    }

    /**
     * هل هذه مؤسسة من نوع "other" (escape hatch)؟
     */
    public function isOther(): bool
    {
        return $this->type === Organization::TYPE_OTHER;
    }

    // ========== Tree predicates ==========

    /**
     * هل هذه المؤسسة جذر في الشجرة (parent_id NULL)؟
     */
    public function isRoot(): bool
    {
        return $this->parent_id === null;
    }

    /**
     * هل عند هذه المؤسسة مؤسسة واحدة على الأقل تابعة لها (مباشرة)؟
     *
     * ملاحظة: لا يحسب children المعطّلين (is_active=false).
     */
    public function hasChildren(): bool
    {
        if (! $this->exists) {
            return false;
        }

        return $this->children()->exists();
    }

    /**
     * هل هذه المؤسسة ابن مباشر لمؤسسة أخرى معيّنة؟
     */
    public function isChildOf(self $parent): bool
    {
        return $this->parent_id !== null && (int) $this->parent_id === (int) $parent->getKey();
    }

    // ========== Child-type policy ==========

    /**
     * هل هذه المؤسسة من النوع الذي يقبل children؟
     */
    public function canHaveChildren(): bool
    {
        return $this->allowedChildTypes() !== [];
    }

    /**
     * قائمة الأنواع المسموح أن تكون children لهذه المؤسسة.
     *
     * @return list<string>
     */
    public function allowedChildTypes(): array
    {
        return Organization::ALLOWED_CHILD_TYPES[$this->type] ?? [];
    }

    /**
     * هل يقبل هذا الـ type كابن مباشر؟
     *
     * @param  string  $childType  أحد ثوابت TYPE_*
     */
    public function canAcceptChildType(string $childType): bool
    {
        return in_array($childType, $this->allowedChildTypes(), true);
    }

    // ========== Counters ==========

    /**
     * عدد المؤسسات الفرعية المباشرة (active فقط).
     */
    public function activeChildrenCount(): int
    {
        if (! $this->exists) {
            return 0;
        }

        return $this->activeChildren()->count();
    }
}
