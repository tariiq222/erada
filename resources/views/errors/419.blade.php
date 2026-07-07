@extends('errors.layout')

@section('code', '419')
@section('title', 'انتهت صلاحية الجلسة')
@section('message', 'انتهت صلاحية جلستك بسبب عدم النشاط. يرجى تحديث الصفحة وتسجيل الدخول مرة أخرى للمتابعة.')

@section('icon')
<svg class="error-icon" viewBox="0 0 24 24" fill="none" stroke="#6366f1" stroke-width="1.5">
    <circle cx="12" cy="12" r="10"/>
    <polyline points="12,6 12,12 16,14"/>
</svg>
@endsection

@section('actions')
<button onclick="location.reload()" class="btn btn-primary">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <polyline points="23,4 23,10 17,10"/>
        <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/>
    </svg>
    تحديث الصفحة
</button>
<a href="{{ url('/login') }}" class="btn btn-secondary">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/>
        <polyline points="10,17 15,12 10,7"/>
        <line x1="15" y1="12" x2="3" y2="12"/>
    </svg>
    تسجيل الدخول
</a>
@endsection
