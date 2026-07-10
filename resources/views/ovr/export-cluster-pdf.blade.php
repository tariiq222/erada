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
    <h1>تقرير مجمّع للحوادث على مستوى المجموعة (OVR)</h1>
    <div class="meta">تاريخ التوليد: {{ $generatedAt }} — عدد المؤسسات: {{ count($perOrg) }}</div>
    <table>
        <thead>
            <tr>
                <th>المؤسسة</th>
                <th>الإجمالي</th>
                @foreach ($statuses as $status)
                    <th>{{ $status->label() }}</th>
                @endforeach
                @foreach ($severities as $severity)
                    <th>{{ $severity->label() }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($perOrg as $row)
                <tr>
                    <td>{{ $row['organization_name'] ?? ('#'.$row['organization_id']) }}</td>
                    <td>{{ $row['total'] }}</td>
                    @foreach ($statuses as $status)
                        <td>{{ $row['by_status'][$status->value] ?? 0 }}</td>
                    @endforeach
                    @foreach ($severities as $severity)
                        <td>{{ $row['by_severity'][$severity->value] ?? 0 }}</td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
    <div class="meta">ملاحظة: هذا تقرير مجمّع على مستوى المؤسسات — لا يتضمن بيانات على مستوى الحوادث الفردية.</div>
</body>
</html>
