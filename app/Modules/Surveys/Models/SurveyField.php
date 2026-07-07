<?php

namespace App\Modules\Surveys\Models;

use App\Modules\Surveys\Enums\FieldType;
use Database\Factories\SurveyFieldFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SurveyField extends Model
{
    use HasFactory;

    protected static function newFactory()
    {
        return SurveyFieldFactory::new();
    }

    protected $fillable = [
        'survey_id',
        'section_id',
        'field_key',
        'name',
        'label',
        'description',
        'type',
        'config',
        'is_required',
        'order',
        'is_visible',
        'visibility_rules',
    ];

    protected $casts = [
        'type' => FieldType::class,
        'config' => 'array',
        'is_required' => 'boolean',
        'is_visible' => 'boolean',
        'visibility_rules' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (SurveyField $field) {
            // توليد field_key تلقائياً إذا لم يُحدد
            if (empty($field->field_key)) {
                $field->field_key = static::generateFieldKey($field->survey_id, $field->name);
            }
        });
    }

    public static function generateFieldKey(int $surveyId, string $name): string
    {
        // تحويل الاسم لـ snake_case
        $base = \Str::snake(\Str::ascii($name));
        $base = preg_replace('/[^a-z0-9_]/', '', $base);
        $base = substr($base, 0, 80) ?: 'field';

        $key = $base;
        $counter = 1;

        while (static::where('survey_id', $surveyId)->where('field_key', $key)->exists()) {
            $key = $base.'_'.$counter;
            $counter++;
        }

        return $key;
    }

    // ========================================
    // العلاقات
    // ========================================

    public function survey(): BelongsTo
    {
        return $this->belongsTo(Survey::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(SurveySection::class, 'section_id');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(SurveyFieldAnswer::class, 'field_id');
    }

    // ========================================
    // Config Helpers
    // ========================================

    public function getConfigValue(string $key, mixed $default = null): mixed
    {
        return data_get($this->config, $key, $default);
    }

    public function getOptions(): array
    {
        return $this->getConfigValue('options', []);
    }

    public function getMatrixRows(): array
    {
        return $this->getConfigValue('matrix_rows', []);
    }

    public function getMatrixColumns(): array
    {
        return $this->getConfigValue('matrix_columns', []);
    }

    public function getValidationRules(): array
    {
        $rules = [];

        if ($this->is_required && $this->type->storesValue()) {
            $rules[] = 'required';
        } else {
            $rules[] = 'nullable';
        }

        // قواعد حسب النوع
        switch ($this->type) {
            case FieldType::Email:
                $rules[] = 'email';
                break;

            case FieldType::Url:
                $rules[] = 'url';
                break;

            case FieldType::Number:
            case FieldType::Rating:
            case FieldType::Scale:
                $rules[] = 'numeric';
                if ($min = $this->getConfigValue('min')) {
                    $rules[] = "min:{$min}";
                }
                if ($max = $this->getConfigValue('max')) {
                    $rules[] = "max:{$max}";
                }
                break;

            case FieldType::Date:
                $rules[] = 'date';
                break;

            case FieldType::Time:
                $rules[] = 'date_format:H:i';
                break;

            case FieldType::Datetime:
                $rules[] = 'date';
                break;

            case FieldType::Select:
            case FieldType::Radio:
                $options = array_column($this->getOptions(), 'value');
                if ($options) {
                    $rules[] = 'in:'.implode(',', $options);
                }
                break;

            case FieldType::Checkbox:
            case FieldType::Multiselect:
                $rules[] = 'array';
                break;

            case FieldType::File:
            case FieldType::Image:
                $rules[] = 'array';
                break;
        }

        // قاعدة مخصصة من الإعدادات
        if ($pattern = $this->getConfigValue('validation_pattern')) {
            $rules[] = "regex:{$pattern}";
        }

        return $rules;
    }

    public function isSecuritySensitive(): bool
    {
        return $this->getConfigValue('security_sensitive', false);
    }

    // ========================================
    // Visibility Helpers
    // ========================================

    /**
     * التحقق من ظهور الحقل بناءً على الإجابات
     */
    public function isVisibleForAnswers(array $answers): bool
    {
        if (! $this->is_visible) {
            return false;
        }

        if (empty($this->visibility_rules)) {
            return true;
        }

        return $this->evaluateVisibilityRules($answers);
    }

    protected function evaluateVisibilityRules(array $answers): bool
    {
        $rules = $this->visibility_rules;

        if (isset($rules['operator'])) {
            $operator = $rules['operator'];
            $conditions = $rules['conditions'] ?? [];

            $results = array_map(
                fn ($condition) => $this->evaluateSingleCondition($condition, $answers),
                $conditions
            );

            return $operator === 'or'
                ? in_array(true, $results, true)
                : ! in_array(false, $results, true);
        }

        // شروط بسيطة
        foreach ($rules as $fieldKey => $expectedValue) {
            $actualValue = $answers[$fieldKey] ?? null;

            if (is_array($expectedValue)) {
                if (! in_array($actualValue, $expectedValue, true)) {
                    return false;
                }
            } elseif ($actualValue !== $expectedValue) {
                return false;
            }
        }

        return true;
    }

    protected function evaluateSingleCondition(array $condition, array $answers): bool
    {
        $fieldKey = $condition['field'] ?? null;
        $operator = $condition['operator'] ?? 'equals';
        $value = $condition['value'] ?? null;

        if (! $fieldKey) {
            return true;
        }

        $actualValue = $answers[$fieldKey] ?? null;

        return match ($operator) {
            'equals' => $actualValue === $value,
            'not_equals' => $actualValue !== $value,
            'contains' => is_string($actualValue) && str_contains($actualValue, $value),
            'in' => is_array($value) && in_array($actualValue, $value, true),
            'not_in' => is_array($value) && ! in_array($actualValue, $value, true),
            'greater_than' => is_numeric($actualValue) && $actualValue > $value,
            'less_than' => is_numeric($actualValue) && $actualValue < $value,
            'is_empty' => empty($actualValue),
            'is_not_empty' => ! empty($actualValue),
            default => true,
        };
    }
}
