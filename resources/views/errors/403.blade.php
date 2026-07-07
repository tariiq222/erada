@extends('errors.layout')

@section('code', '403')
@section('title', 'غير مصرح بالوصول')
@section('message', 'ليس لديك الصلاحيات الكافية للوصول إلى هذه الصفحة. إذا كنت تعتقد أن هذا خطأ، تواصل مع مدير النظام.')

@section('icon')
<svg class="error-icon" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="1.5">
    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
    <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
    <circle cx="12" cy="16" r="1"/>
</svg>
@endsection

@section('actions')
<a href="{{ url('/') }}" class="btn btn-primary">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
        <polyline points="9,22 9,12 15,12 15,22"/>
    </svg>
    الصفحة الرئيسية
</a>
<a href="{{ url('/login') }}" class="btn btn-secondary">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/>
        <polyline points="10,17 15,12 10,7"/>
        <line x1="15" y1="12" x2="3" y2="12"/>
    </svg>
    تسجيل الدخول
</a>
@endsection
