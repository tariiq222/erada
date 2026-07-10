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
                <th>جديد</th>
                <th>قيد المعالجة</th>
                <th>محلول</th>
                <th>مغلق</th>
                <th>منخفض</th>
                <th>متوسط</th>
                <th>عالٍ</th>
                <th>حرج</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($perOrg as $row)
                <tr>
                    <td>{{ $row['organization_name'] ?? ('#'.$row['organization_id']) }}</td>
                    <td>{{ $row['total'] }}</td>
                    <td>{{ $row['by_status']['new'] ?? 0 }}</td>
                    <td>{{ $row['by_status']['in_progress'] ?? 0 }}</td>
                    <td>{{ $row['by_status']['resolved'] ?? 0 }}</td>
                    <td>{{ $row['by_status']['closed'] ?? 0 }}</td>
                    <td>{{ $row['by_severity']['low'] ?? 0 }}</td>
                    <td>{{ $row['by_severity']['medium'] ?? 0 }}</td>
                    <td>{{ $row['by_severity']['high'] ?? 0 }}</td>
                    <td>{{ $row['by_severity']['critical'] ?? 0 }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <div class="meta">ملاحظة: هذا تقرير مجمّع على مستوى المؤسسات — لا يتضمن بيانات على مستوى الحوادث الفردية.</div>
</body>
</html>