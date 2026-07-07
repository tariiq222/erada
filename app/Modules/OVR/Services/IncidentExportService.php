<?php

namespace App\Modules\OVR\Services;

use App\Modules\OVR\Models\IncidentReport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class IncidentExportService
{
    /**
     * Stream the given incident reports as a UTF-8 CSV download.
     *
     * @param  Builder<IncidentReport>  $query
     */
    public function streamCsv(Builder $query, string $filename = 'ovr-incidents.csv'): StreamedResponse
    {
        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $columns = [
            'report_number' => 'رقم التقرير',
            'status' => 'الحالة',
            'severity_level' => 'الخطورة',
            'reporter_name' => 'المبلّغ',
            'incident_type' => 'نوع الحادثة',
            'incident_datetime' => 'تاريخ الحادثة',
            'is_patient_related' => 'متعلق بمريض',
            'assigned_to' => 'المعالج',
            'due_date' => 'موعد الاستحقاق',
            'created_at' => 'تاريخ الإنشاء',
        ];

        return response()->stream(function () use ($query, $columns) {
            $out = fopen('php://output', 'w');
            // BOM so Excel reads Arabic correctly.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_values($columns));

            $query->with(['incidentType', 'assignee'])
                ->chunk(500, function ($reports) use ($out) {
                    foreach ($reports as $report) {
                        fputcsv($out, [
                            $report->report_number,
                            $report->status?->label(),
                            $report->severity_level?->label(),
                            $report->reporter_name,
                            $report->incidentType?->name_ar,
                            $report->incident_datetime?->format('Y-m-d H:i'),
                            $report->is_patient_related ? 'نعم' : 'لا',
                            $report->assignee?->name,
                            $report->due_date?->format('Y-m-d H:i'),
                            $report->created_at?->format('Y-m-d H:i'),
                        ]);
                    }
                });

            fclose($out);
        }, 200, $headers);
    }

    /**
     * Render the given incident reports as a downloadable PDF.
     *
     * @param  Builder<IncidentReport>  $query
     */
    public function downloadPdf(Builder $query, string $filename = 'ovr-incidents.pdf'): Response
    {
        $reports = $query->with(['incidentType', 'assignee'])->get();

        $pdf = Pdf::loadView('ovr.export-pdf', [
            'reports' => $reports,
            'generatedAt' => now()->format('Y-m-d H:i'),
        ])->setPaper('a4', 'landscape');

        return $pdf->download($filename);
    }
}
