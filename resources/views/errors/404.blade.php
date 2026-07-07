@extends('errors.layout')

@section('code', '404')
@section('title', 'الصفحة غير موجودة')
@section('message', 'عذراً، الصفحة التي تبحث عنها غير موجودة أو تم نقلها. تأكد من صحة الرابط أو عد إلى الصفحة الرئيسية.')

@section('icon')
<svg class="error-icon" viewBox="0 0 24 24" fill="none" stroke="#667eea" stroke-width="1.5">
    <circle cx="11" cy="11" r="8"/>
    <path d="m21 21-4.35-4.35"/>
    <path d="M11 8v4M11 14h.01"/>
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
<button onclick="history.back()" class="btn btn-secondary">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <polyline points="15,18 9,12 15,6"/>
    </svg>
    العودة للخلف
</button>
@endsection
