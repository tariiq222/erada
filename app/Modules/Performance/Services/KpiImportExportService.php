<?php

namespace App\Modules\Performance\Services;

use App\Modules\Performance\Models\Kpi;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class KpiImportExportService
{
    private const COLUMNS = [
        'code',
        'name',
        'description',
        'measurement_method',
        'category',
        'baseline',
        'target',
        'current_value',
        'unit',
        'frequency',
        'direction',
        'status',
        'owner_id',
        'order',
    ];

    private const STATUSES = ['active', 'inactive', 'archived'];

    /**
     * @param  Builder<Kpi>  $query
     */
    public function streamCsv(Builder $query, string $filename = 'performance-kpis.csv'): StreamedResponse
    {
        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, self::COLUMNS, ',', '"', '');

            $query->chunk(500, function ($kpis) use ($out) {
                foreach ($kpis as $kpi) {
                    fputcsv($out, $this->rowFor($kpi), ',', '"', '');
                }
            });

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @param  Builder<Kpi>  $query
     */
    public function streamXlsx(Builder $query, string $filename = 'performance-kpis.xlsx'): StreamedResponse
    {
        return response()->streamDownload(function () use ($query) {
            $spreadsheet = new Spreadsheet;
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('KPIs');
            $sheet->fromArray(self::COLUMNS, null, 'A1');

            $rowNumber = 2;
            $query->chunk(500, function ($kpis) use ($sheet, &$rowNumber) {
                foreach ($kpis as $kpi) {
                    $sheet->fromArray($this->rowFor($kpi), null, 'A'.$rowNumber);
                    $rowNumber++;
                }
            });

            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * @return array{created:int, updated:int, skipped:int, errors:array<int, array{row:int, messages:array<int, string>}>}
     */
    public function import(UploadedFile $file, int $organizationId, ?int $actorId): array
    {
        [$headers, $rows] = $this->rowsFromFile($file);

        if (! in_array('name', $headers, true)) {
            throw ValidationException::withMessages([
                'file' => __('performance.import_name_column_required'),
            ]);
        }

        $summary = [
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => [],
        ];

        foreach ($rows as $row) {
            $data = $this->normalizeRow($row['values']);

            if ($data === []) {
                continue;
            }

            $validator = Validator::make($data, $this->rules($organizationId));

            if ($validator->fails()) {
                $this->skipRow($summary, $row['number'], $validator->errors()->all());

                continue;
            }

            $validated = $validator->validated();
            $existing = $this->existingKpiForImport($validated['code'] ?? null, $organizationId, $row['number'], $summary);

            if (($validated['code'] ?? null) !== null && $existing === false) {
                continue;
            }

            try {
                if ($existing instanceof Kpi) {
                    $payload = $this->payloadForUpdate($validated);
                    $existing->update($payload);
                    $summary['updated']++;

                    continue;
                }

                $payload = $this->payloadForCreate($validated, $actorId);
                $kpi = new Kpi($payload);
                $kpi->forceFill(['organization_id' => $organizationId])->save();
                $summary['created']++;
            } catch (Throwable $exception) {
                $this->skipRow($summary, $row['number'], [$exception->getMessage()]);
            }
        }

        return $summary;
    }

    /**
     * @return array<int, mixed>
     */
    private function rowFor(Kpi $kpi): array
    {
        return [
            $kpi->code,
            $kpi->name,
            $kpi->description,
            $kpi->measurement_method,
            $kpi->category,
            $kpi->baseline,
            $kpi->target,
            $kpi->current_value,
            $kpi->unit,
            $kpi->frequency,
            $kpi->direction,
            $kpi->status,
            $kpi->owner_id,
            $kpi->order,
        ];
    }

    /**
     * @return array{0:array<int, string>, 1:array<int, array{number:int, values:array<string, mixed>}>}
     */
    private function rowsFromFile(UploadedFile $file): array
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());

        try {
            $rows = match ($extension) {
                'xlsx' => $this->readXlsx($file),
                'csv', 'txt' => $this->readCsv($file),
                default => throw ValidationException::withMessages([
                    'file' => __('performance.import_format_invalid'),
                ]),
            };
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw ValidationException::withMessages([
                'file' => __('performance.import_unreadable'),
            ]);
        }

        return $this->normalizeRows($rows);
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    private function readCsv(UploadedFile $file): array
    {
        $handle = fopen($file->getRealPath(), 'r');

        if ($handle === false) {
            throw ValidationException::withMessages([
                'file' => __('performance.import_unopenable'),
            ]);
        }

        $rows = [];

        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    private function readXlsx(UploadedFile $file): array
    {
        $spreadsheet = IOFactory::load($file->getRealPath());
        $rows = $spreadsheet->getActiveSheet()->toArray(null, true, true, false);
        $spreadsheet->disconnectWorksheets();

        return $rows;
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows
     * @return array{0:array<int, string>, 1:array<int, array{number:int, values:array<string, mixed>}>}
     */
    private function normalizeRows(array $rows): array
    {
        $headerRow = null;
        $headerRowNumber = 0;

        foreach ($rows as $index => $row) {
            if (! $this->rowIsEmpty($row)) {
                $headerRow = $row;
                $headerRowNumber = $index + 1;
                break;
            }
        }

        if ($headerRow === null) {
            throw ValidationException::withMessages([
                'file' => __('performance.import_empty'),
            ]);
        }

        $columnMap = [];

        foreach ($headerRow as $index => $header) {
            $normalized = $this->normalizeHeader($header);

            if (in_array($normalized, self::COLUMNS, true)) {
                $columnMap[$index] = $normalized;
            }
        }

        $normalizedRows = [];

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 1;

            if ($rowNumber <= $headerRowNumber || $this->rowIsEmpty($row)) {
                continue;
            }

            $values = [];

            foreach ($columnMap as $cellIndex => $column) {
                $values[$column] = $row[$cellIndex] ?? null;
            }

            $normalizedRows[] = [
                'number' => $rowNumber,
                'values' => $values,
            ];
        }

        return [array_values($columnMap), $normalizedRows];
    }

    private function normalizeHeader(mixed $header): string
    {
        $header = strtolower(trim((string) $header));
        $header = preg_replace('/^\xEF\xBB\xBF/', '', $header) ?? $header;

        return str_replace([' ', '-'], '_', $header);
    }

    /**
     * @param  array<int, mixed>  $row
     */
    private function rowIsEmpty(array $row): bool
    {
        foreach ($row as $value) {
            if ($this->normalizeValue($value) !== null) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normalizeRow(array $row): array
    {
        $normalized = [];

        foreach ($row as $key => $value) {
            $normalized[$key] = $this->normalizeValue($value);
        }

        return array_filter($normalized, fn ($value) => $value !== null);
    }

    private function normalizeValue(mixed $value): mixed
    {
        if (is_string($value)) {
            $value = preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;
            $value = trim($value);

            return $value === '' ? null : $value;
        }

        return $value === '' ? null : $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(int $organizationId): array
    {
        return [
            'code' => ['nullable', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'measurement_method' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:100'],
            'baseline' => ['nullable', 'numeric', 'min:-1000000000000', 'max:1000000000000'],
            'target' => ['nullable', 'numeric', 'min:-1000000000000', 'max:1000000000000'],
            'current_value' => ['nullable', 'numeric', 'min:-1000000000000', 'max:1000000000000'],
            'unit' => ['nullable', 'string', 'max:50'],
            'frequency' => ['nullable', Rule::in(array_keys(Kpi::FREQUENCY_LABELS))],
            'direction' => ['nullable', Rule::in(array_keys(Kpi::DIRECTION_LABELS))],
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'owner_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where('organization_id', $organizationId),
            ],
            'order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ];
    }

    private function existingKpiForImport(?string $code, int $organizationId, int $rowNumber, array &$summary): Kpi|false|null
    {
        if ($code === null || $code === '') {
            return null;
        }

        $existing = Kpi::withTrashed()->where('code', $code)->first();

        if (! $existing) {
            return null;
        }

        if ((int) $existing->organization_id !== $organizationId) {
            $this->skipRow($summary, $rowNumber, [__('performance.import_code_cross_org')]);

            return false;
        }

        if ($existing->trashed()) {
            $this->skipRow($summary, $rowNumber, [__('performance.import_code_deleted')]);

            return false;
        }

        return $existing;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function payloadForCreate(array $validated, ?int $actorId): array
    {
        $payload = $validated;
        $payload['created_by'] = $actorId;
        $payload['status'] = $payload['status'] ?? 'active';
        $payload['frequency'] = $payload['frequency'] ?? 'monthly';
        $payload['direction'] = $payload['direction'] ?? Kpi::DIRECTION_INCREASE;
        $payload['current_value'] = $payload['current_value'] ?? $payload['baseline'] ?? 0;

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function payloadForUpdate(array $validated): array
    {
        if (($validated['current_value'] ?? null) === null) {
            unset($validated['current_value']);
        }

        return $validated;
    }

    /**
     * @param  array{created:int, updated:int, skipped:int, errors:array<int, array{row:int, messages:array<int, string>}>}  $summary
     * @param  array<int, string>  $messages
     */
    private function skipRow(array &$summary, int $rowNumber, array $messages): void
    {
        $summary['skipped']++;
        $summary['errors'][] = [
            'row' => $rowNumber,
            'messages' => $messages,
        ];
    }
}
