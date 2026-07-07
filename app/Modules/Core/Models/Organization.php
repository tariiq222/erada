<?php

namespace App\Modules\Core\Models;

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
    use HasFactory, LogsActivity, SoftDeletes;

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
        'logo',
        'description',
        'email',
        'phone',
        'address',
        'website',
        'is_active',
    ];

    protected $fillable = [
        'name',
        'code',
        'logo',
        'description',
        'email',
        'phone',
        'address',
        'website',
        'settings',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'settings' => 'array',
        'is_active' => 'boolean',
    ];

    // ========== العلاقات ==========

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
     * الأدوار السياقية المرتبطة بهذه المؤسسة
     */
    public function scopedRoles(): HasMany
    {
        return $this->hasMany(ScopedRole::class, 'scope_id')
            ->where('scope_type', ScopedRole::SCOPE_ORGANIZATION);
    }

    /**
     * الاستبيانات التابعة للمؤسسة
     */
    public function surveys(): HasMany
    {
        return $this->hasMany(Survey::class);
    }

    // ========== Scopes ==========

    /**
     * المؤسسات النشطة فقط
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    // ========== Helpers ==========

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
}
