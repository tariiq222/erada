<?php

namespace App\Modules\RiskManagement\Services;

use App\Modules\RiskManagement\Models\Risk;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV/PDF export for the RiskManagement module. The caller must pass an
 * already-org-scoped Builder so the same filters as the index endpoint
 * are applied. Mirrors OVR's IncidentExportService.
 */
class RiskExportService
{
    /**
     * @param  Builder<Risk>  $query
     */
    public function streamCsv(Builder $query, string $filename = 'risks.csv'): StreamedResponse
    {
        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $columns = [
            'code' => 'الرمز',
            'title' => 'العنوان',
            'type' => 'النوع',
            'status' => 'الحالة',
            'current_level' => 'المستوى',
            'current_score' => 'الدرجة',
            'department_id' => 'القسم',
            'owner_id' => 'المالك',
            'discovery_date' => 'تاريخ الاكتشاف',
            'target_close_date' => 'تاريخ الإغلاق المستهدف',
            'response_type' => 'نوع الاستجابة',
        ];

        return response()->stream(function () use ($query, $columns) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel/Arabic
            fputcsv($out, array_values($columns));

            $query->with(['department:id,name', 'owner:id,name'])
                ->chunk(500, function ($risks) use ($out, $columns) {
                    foreach ($risks as $risk) {
                        $row = [];
                        foreach (array_keys($columns) as $key) {
                            $value = match ($key) {
                                'type' => $risk->type?->label(),
                                'status' => $risk->status?->label(),
                                'response_type' => $risk->response_type?->label(),
                                'department_id' => $risk->department?->name,
                                'owner_id' => $risk->owner?->name,
                                'current_level' => ucfirst($risk->current_level ?? ''),
                                'discovery_date' => $risk->discovery_date?->format('Y-m-d'),
                                'target_close_date' => $risk->target_close_date?->format('Y-m-d'),
                                default => $risk->{$key},
                            };
                            $row[] = $value;
                        }
                        fputcsv($out, $row);
                    }
                });

            fclose($out);
        }, 200, $headers);
    }

    /**
     * @param  Builder<Risk>  $query
     */
    public function downloadPdf(Builder $query, string $filename = 'risks.pdf'): Response
    {
        $risks = $query->with(['department:id,name', 'owner:id,name'])->get();

        $html = view()->file(__DIR__.'/../resources/views/export-pdf.blade.php', [
            'risks' => $risks,
            'generatedAt' => now()->format('Y-m-d H:i'),
        ])->render();

        $pdf = Pdf::loadHTML($html)->setPaper('a4', 'landscape');

        return $pdf->download($filename);
    }
}
