@extends('errors.layout')

@section('code', '500')
@section('title', 'خطأ في الخادم')
@section('message', 'حدث خطأ غير متوقع في النظام. فريقنا التقني يعمل على إصلاح المشكلة. يرجى المحاولة لاحقاً.')

@section('icon')
<svg class="error-icon" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="1.5">
    <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
    <line x1="12" y1="9" x2="12" y2="13"/>
    <line x1="12" y1="17" x2="12.01" y2="17"/>
</svg>
@endsection

@section('actions')
<button onclick="location.reload()" class="btn btn-primary">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <polyline points="23,4 23,10 17,10"/>
        <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/>
    </svg>
    إعادة المحاولة
</button>
<a href="{{ url('/') }}" class="btn btn-secondary">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
        <polyline points="9,22 9,12 15,12 15,22"/>
    </svg>
    الصفحة الرئيسية
</a>
@endsection
