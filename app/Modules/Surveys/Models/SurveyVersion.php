<?php

namespace App\Modules\Surveys\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SurveyVersion extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'survey_id',
        'version_hash',
        'snapshot_json',
        'fields_count',
        'sections_count',
        'created_at',
    ];

    protected $casts = [
        'snapshot_json' => 'array',
        'created_at' => 'datetime',
    ];

    // ========================================
    // العلاقات
    // ========================================

    public function survey(): BelongsTo
    {
        return $this->belongsTo(Survey::class);
    }

    public function responses(): HasMany
    {
        return $this->hasMany(SurveyResponse::class);
    }

    // ========================================
    // Static Methods
    // ========================================

    /**
     * إنشاء snapshot للاستبيان
     */
    public static function createFromSurvey(Survey $survey): self
    {
        $snapshot = [
            'survey' => [
                'id' => $survey->id,
                'code' => $survey->code,
                'revision' => $survey->revision,
                'title' => $survey->title,
                'type' => $survey->type->value,
                'consent_text' => $survey->consent_text,
                'consent_required' => $survey->consent_required,
            ],
            'sections' => $survey->sections->map(fn ($section) => [
                'id' => $section->id,
                'title' => $section->title,
                'description' => $section->description,
                'order' => $section->order,
                'is_visible' => $section->is_visible,
                'visibility_rules' => $section->visibility_rules,
            ])->toArray(),
            'fields' => $survey->fields->map(fn ($field) => [
                'id' => $field->id,
                'section_id' => $field->section_id,
                'field_key' => $field->field_key,
                'name' => $field->name,
                'label' => $field->label,
                'description' => $field->description,
                'type' => $field->type,
                'config' => $field->config,
                'is_required' => $field->is_required,
                'order' => $field->order,
                'is_visible' => $field->is_visible,
                'visibility_rules' => $field->visibility_rules,
            ])->toArray(),
        ];

        $hash = hash('sha256', json_encode($snapshot));

        // تحقق من وجود نفس النسخة
        $existing = static::where('version_hash', $hash)->first();
        if ($existing) {
            return $existing;
        }

        return static::create([
            'survey_id' => $survey->id,
            'version_hash' => $hash,
            'snapshot_json' => $snapshot,
            'fields_count' => $survey->fields()->count(),
            'sections_count' => $survey->sections()->count(),
            'created_at' => now(),
        ]);
    }

    // ========================================
    // Helpers
    // ========================================

    /**
     * الحصول على حقل من الـ snapshot
     */
    public function getField(string $fieldKey): ?array
    {
        $fields = $this->snapshot_json['fields'] ?? [];

        return collect($fields)->firstWhere('field_key', $fieldKey);
    }

    /**
     * الحصول على جميع الحقول
     */
    public function getFields(): array
    {
        return $this->snapshot_json['fields'] ?? [];
    }

    /**
     * الحصول على جميع الأقسام
     */
    public function getSections(): array
    {
        return $this->snapshot_json['sections'] ?? [];
    }
}
