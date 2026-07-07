<?php

namespace App\Modules\Surveys\Services;

use App\Modules\Surveys\Models\Survey;
use App\Modules\Surveys\Models\SurveyResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class SurveyExportService
{
    /**
     * تصدير إجابات الاستبيان بتنسيق CSV
     */
    public function exportToCsv(Survey $survey, array $filters = []): string
    {
        $responses = $this->getFilteredResponses($survey, $filters);
        $fields = $survey->fields()->orderBy('order')->get();

        $headers = $this->buildCsvHeaders($fields);
        $rows = $this->buildCsvRows($responses, $fields);

        $filename = "survey-{$survey->code}-export-".now()->format('Y-m-d-His').'.csv';
        $path = "exports/{$filename}";

        $csvContent = $this->generateCsvContent($headers, $rows);
        Storage::disk('local')->put($path, $csvContent);

        return $path;
    }

    /**
     * تصدير إجابات الاستبيان بتنسيق JSON
     */
    public function exportToJson(Survey $survey, array $filters = []): string
    {
        $responses = $this->getFilteredResponses($survey, $filters);
        $fields = $survey->fields()->orderBy('order')->get();

        $data = [
            'survey' => [
                'id' => $survey->id,
                'code' => $survey->code,
                'title' => $survey->title,
                'exported_at' => now()->toISOString(),
            ],
            'fields' => $fields->map(fn ($f) => [
                'name' => $f->name,
                'label' => $f->label,
                'type' => $f->type,
            ])->toArray(),
            'responses' => $responses->map(fn ($r) => $this->formatResponseForJson($r, $fields))->toArray(),
        ];

        $filename = "survey-{$survey->code}-export-".now()->format('Y-m-d-His').'.json';
        $path = "exports/{$filename}";

        Storage::disk('local')->put($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return $path;
    }

    /**
     * الحصول على الإجابات المفلترة
     */
    protected function getFilteredResponses(Survey $survey, array $filters): Collection
    {
        $query = $survey->responses()
            ->with(['answers.field', 'respondent:id,name,email'])
            ->orderBy('submitted_at', 'desc');

        // فلترة حسب الحالة
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        // فلترة حسب التاريخ
        if (! empty($filters['from_date'])) {
            $query->where('submitted_at', '>=', $filters['from_date']);
        }

        if (! empty($filters['to_date'])) {
            $query->where('submitted_at', '<=', $filters['to_date']);
        }

        return $query->get();
    }

    /**
     * بناء رؤوس CSV
     */
    protected function buildCsvHeaders(Collection $fields): array
    {
        $headers = ['رقم الإجابة', 'اسم المجيب', 'البريد الإلكتروني', 'الحالة', 'تاريخ البدء', 'تاريخ الإرسال'];

        foreach ($fields as $field) {
            $headers[] = $field->label;
        }

        return $headers;
    }

    /**
     * بناء صفوف CSV
     */
    protected function buildCsvRows(Collection $responses, Collection $fields): array
    {
        $rows = [];

        foreach ($responses as $response) {
            $row = [
                $response->id,
                $response->respondent?->name ?? $response->respondent_name ?? 'مجهول',
                $response->respondent?->email ?? $response->respondent_email ?? '-',
                $this->translateStatus($response->status),
                $response->started_at?->format('Y-m-d H:i'),
                $response->submitted_at?->format('Y-m-d H:i'),
            ];

            foreach ($fields as $field) {
                $answer = $response->answers->firstWhere('field_id', $field->id);
                $row[] = $this->formatAnswerValue($answer?->answer_value, $field->type->value ?? (string) $field->type);
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * توليد محتوى CSV
     */
    protected function generateCsvContent(array $headers, array $rows): string
    {
        $output = fopen('php://temp', 'r+');

        // إضافة BOM لـ UTF-8
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

        fputcsv($output, $this->escapeCsvRow($headers));

        foreach ($rows as $row) {
            fputcsv($output, $this->escapeCsvRow($row));
        }

        rewind($output);
        $content = stream_get_contents($output);
        fclose($output);

        return $content;
    }

    protected function escapeCsvRow(array $row): array
    {
        return array_map(fn ($value) => $this->escapeCsvCell($value), $row);
    }

    public function escapeCsvCell(mixed $value): mixed
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        return preg_match('/^[=+\-@\t\r\n]/', $value) === 1 ? "'{$value}" : $value;
    }

    /**
     * تنسيق الإجابة لـ JSON
     */
    protected function formatResponseForJson(SurveyResponse $response, Collection $fields): array
    {
        $answers = [];
        foreach ($fields as $field) {
            $answer = $response->answers->firstWhere('field_id', $field->id);
            $answers[$field->name] = $answer?->answer_value;
        }

        return [
            'id' => $response->id,
            'respondent' => $response->respondent ? [
                'id' => $response->respondent->id,
                'name' => $response->respondent->name,
                'email' => $response->respondent->email,
            ] : null,
            'status' => $response->status,
            'started_at' => $response->started_at?->toISOString(),
            'submitted_at' => $response->submitted_at?->toISOString(),
            'answers' => $answers,
        ];
    }

    /**
     * تنسيق قيمة الإجابة
     */
    protected function formatAnswerValue(mixed $value, string $fieldType): string
    {
        if ($value === null) {
            return '';
        }

        if (is_array($value)) {
            return implode(', ', $value);
        }

        if ($fieldType === 'checkbox') {
            return $value ? 'نعم' : 'لا';
        }

        return (string) $value;
    }

    /**
     * ترجمة الحالة
     */
    protected function translateStatus(mixed $status): string
    {
        $status = $status->value ?? (string) $status;

        return match ($status) {
            'draft' => 'مسودة',
            'submitted' => 'مرسل',
            'flagged' => 'مؤشر',
            default => $status,
        };
    }
}
