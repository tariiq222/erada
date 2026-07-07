<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <title>تقرير المخاطر</title>
    <style>
        body { font-family: 'XBRiyaz', 'DejaVu Sans', sans-serif; direction: rtl; }
        table { width: 100%; border-collapse: collapse; font-size: 12px; }
        th, td { border: 1px solid #999; padding: 4px 6px; text-align: right; }
        th { background: #eee; }
        h1 { font-size: 18px; }
        .meta { color: #555; font-size: 11px; margin-bottom: 12px; }
    </style>
</head>
<body>
    <h1>تقرير المخاطر المؤسسية</h1>
    <div class="meta">تاريخ التوليد: {{ $generatedAt }} — عدد السجلات: {{ count($risks) }}</div>
    <table>
        <thead>
            <tr>
                <th>الرمز</th>
                <th>العنوان</th>
                <th>النوع</th>
                <th>الحالة</th>
                <th>المستوى</th>
                <th>الدرجة</th>
                <th>القسم</th>
                <th>المالك</th>
                <th>تاريخ الاكتشاف</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($risks as $risk)
                <tr>
                    <td>{{ $risk->code }}</td>
                    <td>{{ $risk->title }}</td>
                    <td>{{ $risk->type?->label() }}</td>
                    <td>{{ $risk->status?->label() }}</td>
                    <td>{{ ucfirst($risk->current_level ?? '') }}</td>
                    <td>{{ $risk->current_score }}</td>
                    <td>{{ $risk->department?->name }}</td>
                    <td>{{ $risk->owner?->name }}</td>
                    <td>{{ $risk->discovery_date?->format('Y-m-d') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
