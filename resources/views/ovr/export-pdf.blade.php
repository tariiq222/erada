<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <style>
        @font-face {
            font-family: 'IBM Plex Sans Arabic';
            font-weight: normal;
            font-style: normal;
            src: url('{{ public_path('fonts/IBMPlexSansArabic-Regular.ttf') }}') format('truetype');
        }
        @font-face {
            font-family: 'IBM Plex Sans Arabic';
            font-weight: bold;
            font-style: normal;
            src: url('{{ public_path('fonts/IBMPlexSansArabic-Bold.ttf') }}') format('truetype');
        }
        * { font-family: 'IBM Plex Sans Arabic', sans-serif; }
        body { font-size: 10px; color: #1a1a1a; }
        h1 { font-size: 16px; margin: 0 0 4px; }
        .meta { color: #666; font-size: 9px; margin-bottom: 12px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #ddd; padding: 5px 6px; text-align: right; }
        th { background: #f3f4f6; font-weight: bold; }
        tr:nth-child(even) td { background: #fafafa; }
    </style>
</head>
<body>
    <h1>تقارير الحوادث (OVR)</h1>
    <div class="meta">تاريخ التوليد: {{ $generatedAt }} — العدد: {{ $reports->count() }}</div>
    <table>
        <thead>
            <tr>
                <th>رقم التقرير</th>
                <th>الحالة</th>
                <th>الخطورة</th>
                <th>المبلّغ</th>
                <th>نوع الحادثة</th>
                <th>تاريخ الحادثة</th>
                <th>متعلق بمريض</th>
                <th>المعالج</th>
                <th>موعد الاستحقاق</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($reports as $report)
                <tr>
                    <td>{{ $report->report_number }}</td>
                    <td>{{ $report->status?->label() }}</td>
                    <td>{{ $report->severity_level?->label() }}</td>
                    <td>{{ $report->reporter_name }}</td>
                    <td>{{ $report->incidentType?->name_ar }}</td>
                    <td>{{ $report->incident_datetime?->format('Y-m-d H:i') }}</td>
                    <td>{{ $report->is_patient_related ? 'نعم' : 'لا' }}</td>
                    <td>{{ $report->assignee?->name }}</td>
                    <td>{{ $report->due_date?->format('Y-m-d H:i') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
