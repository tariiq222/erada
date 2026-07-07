<?php

namespace App\Modules\Surveys\Models;

use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Surveys\Enums\ConflictPolicy;
use App\Modules\Surveys\Enums\InsertPolicy;
use Database\Factories\DataMappingTemplateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DataMappingTemplate extends Model
{
    use HasFactory;

    protected static function newFactory()
    {
        return DataMappingTemplateFactory::new();
    }

    protected $fillable = [
        'survey_id',
        'name',
        'description',
        'target_model',
        'mappings',
        'insert_policy',
        'conflict_policy',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'mappings' => 'array',
        'insert_policy' => InsertPolicy::class,
        'conflict_policy' => ConflictPolicy::class,
        'is_active' => 'boolean',
    ];

    // ========================================
    // العلاقات
    // ========================================

    public function survey(): BelongsTo
    {
        return $this->belongsTo(Survey::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function importRequests(): HasMany
    {
        return $this->hasMany(DataImportRequest::class, 'template_id');
    }

    // ========================================
    // Scopes
    // ========================================

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    // ========================================
    // Helpers
    // ========================================

    /**
     * الجداول المتاحة للربط
     */
    public static function getAvailableTargetModels(): array
    {
        return [
            'departments' => [
                'label' => 'الأقسام',
                'model' => Department::class,
                'columns' => [
                    'name' => ['label' => 'اسم القسم', 'type' => 'string', 'required' => true],
                    'code' => ['label' => 'الرمز', 'type' => 'string'],
                    'email' => ['label' => 'البريد الإلكتروني', 'type' => 'email'],
                    'phone' => ['label' => 'الهاتف', 'type' => 'phone'],
                    'description' => ['label' => 'الوصف', 'type' => 'text'],
                    'level' => ['label' => 'المستوى الإداري', 'type' => 'integer'],
                    'parent_id' => ['label' => 'القسم الأب', 'type' => 'foreign', 'foreign_model' => 'departments'],
                    'manager_id' => ['label' => 'المدير', 'type' => 'foreign', 'foreign_model' => 'users'],
                ],
            ],
            'users' => [
                'label' => 'المستخدمين',
                'model' => User::class,
                'columns' => [
                    'name' => ['label' => 'الاسم', 'type' => 'string', 'required' => true],
                    'email' => ['label' => 'البريد الإلكتروني', 'type' => 'email', 'required' => true],
                    'phone' => ['label' => 'الهاتف', 'type' => 'phone'],
                    'job_title' => ['label' => 'المسمى الوظيفي', 'type' => 'string'],
                    'department_id' => ['label' => 'القسم', 'type' => 'foreign', 'foreign_model' => 'departments'],
                ],
            ],
        ];
    }

    public static function getSensitiveColumns(): array
    {
        return [
            'id',
            'organization_id',
            'password',
            'remember_token',
            'email_verified_at',
            'roles',
            'permissions',
            'is_active',
            'created_at',
            'updated_at',
            'deleted_at',
            'created_by',
            'updated_by',
            'two_factor_secret',
            'two_factor_recovery_codes',
            'two_factor_confirmed_at',
            'two_factor_required',
            'failed_login_attempts',
            'locked_until',
            'last_failed_login_at',
            'last_login_at',
        ];
    }

    public static function getAllowedColumnsForTarget(string $target): array
    {
        $columns = static::getAvailableTargetModels()[$target]['columns'] ?? [];

        return array_values(array_diff(array_keys($columns), static::getSensitiveColumns()));
    }

    public static function isAllowedColumn(string $target, string $column): bool
    {
        return in_array($column, static::getAllowedColumnsForTarget($target), true);
    }

    public function sanitizePayload(array $payload): array
    {
        return array_intersect_key($payload, array_flip(static::getAllowedColumnsForTarget($this->target_model)));
    }

    /**
     * الحصول على معلومات الجدول المستهدف
     */
    public function getTargetModelInfo(): ?array
    {
        return static::getAvailableTargetModels()[$this->target_model] ?? null;
    }

    /**
     * الحصول على class الـ Model
     */
    public function getModelClass(): ?string
    {
        return $this->getTargetModelInfo()['model'] ?? null;
    }

    /**
     * الحصول على حقول الـ upsert key
     */
    public function getUpsertKeyFields(): array
    {
        $keys = [];

        foreach ($this->mappings as $fieldKey => $mapping) {
            if (! empty($mapping['upsert_key'])) {
                $keys[$fieldKey] = $mapping['column'];
            }
        }

        return $keys;
    }

    /**
     * الحصول على الحقول المطلوبة
     */
    public function getRequiredFields(): array
    {
        $required = [];

        foreach ($this->mappings as $fieldKey => $mapping) {
            if (! empty($mapping['required'])) {
                $required[] = $fieldKey;
            }
        }

        return $required;
    }

    /**
     * التحقق من اكتمال البيانات المطلوبة
     */
    public function validateAnswers(array $answers): array
    {
        $errors = [];
        $required = $this->getRequiredFields();

        foreach ($required as $fieldKey) {
            if (! isset($answers[$fieldKey]) || $answers[$fieldKey] === '' || $answers[$fieldKey] === null) {
                $errors[$fieldKey] = "الحقل {$fieldKey} مطلوب";
            }
        }

        return $errors;
    }
}
