<?php

namespace App\Modules\Surveys\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SurveySection extends Model
{
    protected $fillable = [
        'survey_id',
        'title',
        'description',
        'order',
        'is_visible',
        'visibility_rules',
    ];

    protected $casts = [
        'is_visible' => 'boolean',
        'visibility_rules' => 'array',
    ];

    // ========================================
    // العلاقات
    // ========================================

    public function survey(): BelongsTo
    {
        return $this->belongsTo(Survey::class);
    }

    public function fields(): HasMany
    {
        return $this->hasMany(SurveyField::class, 'section_id')->orderBy('order');
    }

    // ========================================
    // Helpers
    // ========================================

    /**
     * التحقق من ظهور القسم بناءً على الإجابات
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

        // تنسيق: { "field_key": "value" } أو { "field_key": ["value1", "value2"] }
        // أو { "operator": "and|or", "conditions": [...] }

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

        // شروط بسيطة - كلها AND
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
