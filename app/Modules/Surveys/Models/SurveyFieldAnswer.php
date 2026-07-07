<?php

namespace App\Modules\Surveys\Models;

use App\Modules\Surveys\Enums\FieldType;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SurveyFieldAnswer extends Model
{
    protected $fillable = [
        'response_id',
        'field_id',
        'field_key',
        'answer_value',
        'answer_text',
        'answer_number',
        'answer_date',
    ];

    protected $casts = [
        'answer_value' => 'array',
        'answer_date' => 'date',
    ];

    // ========================================
    // العلاقات
    // ========================================

    public function response(): BelongsTo
    {
        return $this->belongsTo(SurveyResponse::class, 'response_id');
    }

    public function field(): BelongsTo
    {
        return $this->belongsTo(SurveyField::class, 'field_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(SurveyAnswerFile::class, 'answer_id');
    }

    // ========================================
    // Helpers
    // ========================================

    /**
     * الحصول على القيمة المعروضة
     */
    public function getDisplayValue(): string
    {
        $field = $this->field;

        if (! $field) {
            return (string) ($this->answer_text ?? json_encode($this->answer_value));
        }

        return match ($field->type) {
            FieldType::Select,
            FieldType::Radio => $this->getOptionLabel($this->answer_value),

            FieldType::Checkbox,
            FieldType::Multiselect => $this->getMultipleOptionLabels($this->answer_value),

            FieldType::Rating => $this->formatRating($this->answer_value),

            FieldType::Scale => (string) $this->answer_number,

            FieldType::Date => $this->answer_date?->format('Y-m-d') ?? '',

            FieldType::File,
            FieldType::Image => $this->formatFiles(),

            FieldType::Matrix => $this->formatMatrix($this->answer_value),

            default => $this->answer_text ?? (string) json_encode($this->answer_value),
        };
    }

    protected function getOptionLabel(mixed $value): string
    {
        if (! $this->field || empty($value)) {
            return '';
        }

        $options = $this->field->getOptions();
        foreach ($options as $option) {
            if (($option['value'] ?? null) === $value) {
                return $option['label'] ?? $value;
            }
        }

        return (string) $value;
    }

    protected function getMultipleOptionLabels(mixed $values): string
    {
        if (! is_array($values)) {
            return '';
        }

        $labels = array_map(fn ($v) => $this->getOptionLabel($v), $values);

        return implode('، ', $labels);
    }

    protected function formatRating(mixed $value): string
    {
        $rating = (int) ($value['rating'] ?? $value ?? 0);
        $max = $this->field?->getConfigValue('max', 5) ?? 5;

        return str_repeat('★', $rating).str_repeat('☆', $max - $rating);
    }

    protected function formatFiles(): string
    {
        $count = $this->files()->count();

        return $count > 0 ? "{$count} ملف" : 'لا توجد ملفات';
    }

    protected function formatMatrix(mixed $value): string
    {
        if (! is_array($value)) {
            return '';
        }

        $lines = [];
        foreach ($value as $row => $columns) {
            $columnValues = is_array($columns) ? implode(', ', $columns) : $columns;
            $lines[] = "{$row}: {$columnValues}";
        }

        return implode("\n", $lines);
    }

    /**
     * إنشاء إجابة من قيمة خام
     */
    public static function createFromValue(
        SurveyResponse $response,
        SurveyField $field,
        mixed $value
    ): self {
        $answer = new self;
        $answer->response_id = $response->id;
        $answer->field_id = $field->id;
        $answer->field_key = $field->field_key;

        // تحديد كيفية التخزين حسب نوع الحقل
        $answer->answer_value = $value;

        // نسخة نصية للبحث
        if (is_string($value)) {
            $answer->answer_text = $value;
        } elseif (is_array($value)) {
            $answer->answer_text = implode(', ', array_filter($value, 'is_string'));
        }

        // قيمة رقمية
        if (is_numeric($value)) {
            $answer->answer_number = $value;
        } elseif (is_array($value) && isset($value['rating'])) {
            $answer->answer_number = $value['rating'];
        }

        // تاريخ
        if ($field->type === FieldType::Date && $value) {
            try {
                $answer->answer_date = Carbon::parse($value)->toDateString();
            } catch (\Exception $e) {
                // تجاهل إذا لم يكن تاريخ صالح
            }
        }

        $answer->save();

        return $answer;
    }
}
