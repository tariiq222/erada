<?php

namespace App\Modules\Core\Models;

use App\Modules\Core\Traits\HasOrganizationHierarchy;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use App\Modules\Shared\Traits\LogsActivity;
use App\Modules\Surveys\Models\Survey;
use App\Modules\Tasks\Models\Task;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Organization extends Model
{
    use HasFactory, HasOrganizationHierarchy, LogsActivity, SoftDeletes;

    /**
     * أنواع المؤسسات المسموحة (Phase 9-B).
     *
     * القاموس مغلق في CHECK constraint على مستوى DB (انظر migration
     * add_hierarchy_to_organizations_table). إضافة قيمة جديدة هنا
     * تتطلب ALTER للـ CHECK في migration لاحقة.
     */
    public const TYPE_CLUSTER = 'cluster';

    public const TYPE_HOSPITAL = 'hospital';

    public const TYPE_CENTER = 'center';

    public const TYPE_ORGANIZATION = 'organization';

    public const TYPE_OTHER = 'other';

    /**
     * كل الأنواع المسموحة — يُستخدم في validation rules و scopes.
     *
     * @var list<string>
     */
    public const TYPES = [
        self::TYPE_CLUSTER,
        self::TYPE_HOSPITAL,
        self::TYPE_CENTER,
        self::TYPE_ORGANIZATION,
        self::TYPE_OTHER,
    ];

    /**
     * قواعد "من يستطيع أن يكون ابن من" (Phase 9-B).
     *
     * مصفوفة static بدلاً من منطق في الدوال لجعل الاختبار
     * ولوحة admin في الـ UI واضحين.
     *
     * - cluster: الجذر المعتاد — يقبل hospital/center/organization
     * - hospital: في هذه المرحلة لا يقبل children (ورقة سياسات لاحقاً)
     * - center: في هذه المرحلة لا يقبل children
     * - organization: يمكن أن يكون standalone root أو ابن لـ cluster
     * - other: escape hatch — لا يقبل children إلا إذا أُضيفت سياسة
     *
     * @var array<string, list<string>>
     */
    public const ALLOWED_CHILD_TYPES = [
        self::TYPE_CLUSTER => [
            self::TYPE_HOSPITAL,
            self::TYPE_CENTER,
            self::TYPE_ORGANIZATION,
        ],
        self::TYPE_HOSPITAL => [],
        self::TYPE_CENTER => [],
        self::TYPE_ORGANIZATION => [],
        self::TYPE_OTHER => [],
    ];

    protected static function newFactory()
    {
        return OrganizationFactory::new();
    }

    /**
     * الحقول التي نريد تتبعها في سجل التغييرات
     */
    protected array $trackedFields = [
        'name',
        'code',
        'type',
        'parent_id',
        'logo',
        'description',
        'email',
        'phone',
        'address',
        'website',
        'is_active',
        'sort_order',
    ];

    protected $fillable = [
        'name',
        'code',
        'type',
        'parent_id',
        'logo',
        'description',
        'email',
        'phone',
        'address',
        'website',
        'settings',
        'is_active',
        'sort_order',
        'created_by',
    ];

    protected $casts = [
        'settings' => 'array',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'parent_id' => 'integer',
    ];

    // ========== العلاقات الأساسية (غير المتعلقة بالـ hierarchy) ==========

    /**
     * المستخدمون التابعون للمؤسسة
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * الأقسام التابعة للمؤسسة
     */
    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }

    /**
     * المشاريع التابعة للمؤسسة
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /**
     * المهام التابعة للمؤسسة
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * من أنشأ المؤسسة
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * الاستبيانات التابعة للمؤسسة
     */
    public function surveys(): HasMany
    {
        return $this->hasMany(Survey::class);
    }

    // ========== Scopes الأساسية ==========

    /**
     * المؤسسات النشطة فقط
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    // ========== Helpers الأساسية ==========

    /**
     * الحصول على إعداد معين
     */
    public function getSetting(string $key, $default = null)
    {
        return data_get($this->settings, $key, $default);
    }

    /**
     * تعيين إعداد معين
     */
    public function setSetting(string $key, $value): self
    {
        $settings = $this->settings ?? [];
        data_set($settings, $key, $value);
        $this->settings = $settings;

        return $this;
    }

    /**
     * هل هذه المؤسسة الافتراضية؟
     */
    public function isDefault(): bool
    {
        return $this->code === 'DEFAULT';
    }

    // ========== Phase 9-B Hierarchy methods ==========
    //
    // الـ relations (parent, children, activeChildren) والـ scopes
    // (scopeRoots, scopeChildrenOf, scopeOfType) والـ helpers
    // (isCluster / isHospital / ... / canAcceptChildType) كلها موجودة في
    // HasOrganizationHierarchy trait. الـ class هذا يحتفظ بالـ TYPE_*
    // constants والـ ALLOWED_CHILD_TYPES map فقط.
    //
    // لا يزال Organization غير مطبّق ScopeAware — الـ engine لا يمشي شجرة
    // المؤسسات. إضافة parent_id لا يمنح parent visibility لـ child records.
}
