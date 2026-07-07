<?php

namespace App\Modules\HR\Models;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Contracts\ScopeAware;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\ScopedRole;
use App\Modules\Core\Models\User;
use App\Modules\Projects\Models\Project;
use App\Modules\Shared\Traits\LogsActivity;
use App\Modules\Tasks\Models\Task;
use Database\Factories\DepartmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

class Department extends Model implements ScopeAware
{
    use HasFactory, LogsActivity, SoftDeletes;

    /**
     * الحقول التي نريد تتبعها في سجل التغييرات
     */
    protected array $trackedFields = [
        'name',
        'email',
        'code',
        'description',
        'parent_id',
        'level',
        'manager_id',
        'is_active',
        'organization_id',
    ];

    protected static function newFactory(): DepartmentFactory
    {
        return DepartmentFactory::new();
    }

    // مستويات الأقسام (تسلسل هرمي ثابت)
    const LEVEL_TOP_MANAGEMENT = 1;      // الإدارة العليا

    const LEVEL_EXECUTIVE = 2;           // إدارة تنفيذية

    const LEVEL_DEPARTMENT = 3;          // إدارة

    const LEVEL_SECTION = 4;             // قسم

    const LEVEL_UNIT = 5;                // وحدة

    const LEVEL_DIVISION = 6;            // شعبة

    // المستويات المسموح بها كأبناء لكل مستوى
    const ALLOWED_CHILD_LEVELS = [
        self::LEVEL_TOP_MANAGEMENT => [self::LEVEL_EXECUTIVE, self::LEVEL_DEPARTMENT],
        self::LEVEL_EXECUTIVE => [self::LEVEL_DEPARTMENT, self::LEVEL_SECTION],
        self::LEVEL_DEPARTMENT => [self::LEVEL_SECTION, self::LEVEL_UNIT],
        self::LEVEL_SECTION => [self::LEVEL_UNIT, self::LEVEL_DIVISION],
        self::LEVEL_UNIT => [self::LEVEL_DIVISION],
        self::LEVEL_DIVISION => [],
    ];

    protected $fillable = [
        'name',
        'email',
        'code',
        'description',
        'parent_id',
        'level',
        'manager_id',
        'is_active',
        'organization_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'level' => 'integer',
    ];

    // ========== العلاقات ==========

    /**
     * القسم الأب
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'parent_id');
    }

    /**
     * الأقسام الفرعية المباشرة
     */
    public function children(): HasMany
    {
        return $this->hasMany(Department::class, 'parent_id');
    }

    /**
     * مدير القسم (مرتبط بـ users)
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    /**
     * المؤسسة التابعة لها
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * مستخدمو القسم
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * مشاريع القسم
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /**
     * جميع الأقسام الفرعية (Eager Loading)
     */
    public function allChildren(): HasMany
    {
        return $this->children()->with('allChildren');
    }

    // ========== دوال الهيكل الهرمي ==========

    /**
     * الحصول على جميع IDs الأقسام الفرعية (شاملة هذا القسم)
     * مهم جداً للصلاحيات الهرمية
     *
     * محسّن: يستخدم query واحدة لجلب كل الأقسام ثم يبني الشجرة في الذاكرة
     */
    public function getAllChildrenIds(): array
    {
        $allDepartments = static::select('id', 'parent_id')
            ->get()
            ->keyBy('id');

        return $this->collectChildIds($this->id, $allDepartments);
    }

    /**
     * Resolve this department plus every descendant via the indexed materialized
     * path (departments.path), replacing the O(all departments) PHP recursion.
     *
     * Self is matched explicitly because a node's own path ends in /{self}/ and
     * the descendant LIKE uses the trailing slash boundary ('/.../{self}/%') to
     * avoid sibling-prefix collisions (e.g. /1/ must not match /11/).
     *
     * @return array<int, int>
     */
    public function descendantIdsViaPath(): array
    {
        if (! $this->path) {
            return [$this->id];
        }

        return static::query()
            ->where(function ($q) {
                $q->where('path', 'like', $this->path.'%')
                    ->orWhere('id', $this->id);
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * دالة مساعدة: بناء قائمة IDs من collection محملة
     */
    private function collectChildIds(int $parentId, Collection $all): array
    {
        $ids = [$parentId];
        $children = $all->where('parent_id', $parentId);
        foreach ($children as $child) {
            $ids = array_merge($ids, $this->collectChildIds($child->id, $all));
        }

        return $ids;
    }

    /**
     * الحصول على جميع الأقسام الفرعية كـ Collection
     */
    public function getAllChildren(): Collection
    {
        $children = collect();

        foreach ($this->children as $child) {
            $children->push($child);
            $children = $children->merge($child->getAllChildren());
        }

        return $children;
    }

    /**
     * الحصول على سلسلة الآباء (من الأعلى للأسفل)
     */
    public function getAncestors(): Collection
    {
        $ancestors = collect();
        $parent = $this->parent;

        while ($parent) {
            $ancestors->prepend($parent);
            $parent = $parent->parent;
        }

        return $ancestors;
    }

    /**
     * الحصول على المسار الكامل للقسم
     * مثال: "الإدارة الطبية > إدارة الطوارئ > قسم الإسعاف"
     */
    public function getFullPath(): string
    {
        $path = $this->getAncestors()->pluck('name')->toArray();
        $path[] = $this->name;

        return implode(' > ', $path);
    }

    /**
     * عمق القسم في الهيكل الهرمي
     */
    public function getDepth(): int
    {
        return $this->getAncestors()->count();
    }

    /**
     * هل هذا قسم جذر (إدارة رئيسية)؟
     */
    public function isRoot(): bool
    {
        return is_null($this->parent_id);
    }

    /**
     * هل هذا قسم ورقة (لا أقسام تحته)؟
     */
    public function isLeaf(): bool
    {
        return $this->children()->count() === 0;
    }

    // ========== دوال الصلاحيات الهرمية ==========

    /**
     * الحصول على جميع مستخدمي هذا القسم وكل الأقسام الفرعية
     */
    public function getAllUsers(): Collection
    {
        $departmentIds = $this->getAllChildrenIds();

        return User::whereIn('department_id', $departmentIds)->get();
    }

    /**
     * الحصول على جميع المشاريع لهذا القسم وكل الأقسام الفرعية
     */
    public function getAllProjects(): Collection
    {
        $departmentIds = $this->getAllChildrenIds();

        return Project::whereIn('department_id', $departmentIds)->get();
    }

    /**
     * مهام القسم (من موديول Tasks الموحد)
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * مهام القسم من نوع department فقط
     */
    public function departmentTasks(): HasMany
    {
        return $this->hasMany(Task::class)
            ->where('type', 'department');
    }

    /**
     * الأدوار السياقية المرتبطة بهذا القسم
     */
    public function scopedRoles(): HasMany
    {
        return $this->hasMany(ScopedRole::class, 'scope_id')
            ->where('scope_type', ScopedRole::SCOPE_DEPARTMENT);
    }

    /**
     * الحصول على جميع المهام لهذا القسم وكل الأقسام الفرعية
     */
    public function getAllTasks(): Collection
    {
        $departmentIds = $this->getAllChildrenIds();

        return Task::whereIn('department_id', $departmentIds)->get();
    }

    // ========== Scopes ==========

    /**
     * الأقسام النشطة
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * الأقسام الجذرية (الإدارات الرئيسية)
     */
    public function scopeRoot($query)
    {
        return $query->whereNull('parent_id');
    }

    /**
     * حسب المستوى
     */
    public function scopeLevel($query, int $level)
    {
        return $query->where('level', $level);
    }

    /**
     * الإدارات العليا
     */
    public function scopeTopManagement($query)
    {
        return $query->where('level', self::LEVEL_TOP_MANAGEMENT);
    }

    /**
     * نطاق المؤسسة
     */
    public function scopeForOrganization($query, ?int $organizationId)
    {
        return $query->when($organizationId, function ($q) use ($organizationId) {
            return $q->where('organization_id', $organizationId);
        });
    }

    // ========== Helpers ==========

    /**
     * أسماء المستويات
     */
    public static function getLevelName(int $level): string
    {
        return match ($level) {
            self::LEVEL_TOP_MANAGEMENT => 'الإدارة العليا',
            self::LEVEL_EXECUTIVE => 'إدارة تنفيذية',
            self::LEVEL_DEPARTMENT => 'إدارة',
            self::LEVEL_SECTION => 'قسم',
            self::LEVEL_UNIT => 'وحدة',
            self::LEVEL_DIVISION => 'شعبة',
            default => 'غير محدد',
        };
    }

    /**
     * الحصول على جميع المستويات
     */
    public static function getAllLevels(): array
    {
        return [
            self::LEVEL_TOP_MANAGEMENT => 'الإدارة العليا',
            self::LEVEL_EXECUTIVE => 'إدارة تنفيذية',
            self::LEVEL_DEPARTMENT => 'إدارة',
            self::LEVEL_SECTION => 'قسم',
            self::LEVEL_UNIT => 'وحدة',
            self::LEVEL_DIVISION => 'شعبة',
        ];
    }

    /**
     * الحصول على اسم مستوى هذا القسم
     */
    public function getLevelNameAttribute(): string
    {
        return static::getLevelName($this->level ?? 1);
    }

    /**
     * التحقق من صحة التسلسل الهرمي
     */
    public static function isValidHierarchy(?int $parentId, int $childLevel): bool
    {
        if ($parentId === null) {
            return $childLevel === self::LEVEL_TOP_MANAGEMENT;
        }

        $parent = static::find($parentId);
        if (! $parent) {
            return false;
        }

        $allowedLevels = self::ALLOWED_CHILD_LEVELS[$parent->level] ?? [];

        return in_array($childLevel, $allowedLevels);
    }

    /**
     * الحصول على المستويات المسموح بها كأبناء لمستوى معين
     */
    public static function getAllowedChildLevels(?int $parentId): array
    {
        if ($parentId === null) {
            return [self::LEVEL_TOP_MANAGEMENT => 'الإدارة العليا'];
        }

        $parent = static::find($parentId);
        if (! $parent) {
            return [];
        }

        $allowedLevels = self::ALLOWED_CHILD_LEVELS[$parent->level] ?? [];
        $result = [];
        foreach ($allowedLevels as $level) {
            $result[$level] = static::getLevelName($level);
        }

        return $result;
    }

    /**
     * الحصول على رسالة خطأ التسلسل الهرمي
     */
    public static function getHierarchyErrorMessage(?int $parentId, int $childLevel): string
    {
        if ($parentId === null && $childLevel !== self::LEVEL_TOP_MANAGEMENT) {
            return 'القسم بدون أب يجب أن يكون من مستوى "الإدارة العليا"';
        }

        $parent = static::find($parentId);
        if (! $parent) {
            return 'القسم الأب غير موجود';
        }

        $parentLevelName = static::getLevelName($parent->level);
        $childLevelName = static::getLevelName($childLevel);
        $allowedLevels = self::ALLOWED_CHILD_LEVELS[$parent->level] ?? [];

        if (empty($allowedLevels)) {
            return "لا يمكن إضافة أقسام فرعية تحت مستوى \"{$parentLevelName}\"";
        }

        $allowedNames = array_map([static::class, 'getLevelName'], $allowedLevels);
        $allowedStr = implode('، ', $allowedNames);

        return "لا يمكن وضع \"{$childLevelName}\" تحت \"{$parentLevelName}\". المستويات المسموحة: {$allowedStr}";
    }

    // ========== ScopeAware ==========

    public function scopeParent(): ?Model
    {
        return AccessDecision::resolveScopeParent(Department::class, $this->parent_id ?: null);
    }

    public function scopeTypeKey(): string
    {
        return 'department';
    }

    public function scopeOrganizationId(): ?int
    {
        return $this->organization_id ? (int) $this->organization_id : null;
    }
}
