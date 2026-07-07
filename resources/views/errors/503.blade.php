@extends('errors.layout')

@section('code', '503')
@section('title', 'النظام قيد الصيانة')
@section('message', 'نقوم حالياً بإجراء تحديثات على النظام. سنعود قريباً، شكراً لصبركم.')

@section('icon')
<svg class="error-icon" viewBox="0 0 24 24" fill="none" stroke="#8b5cf6" stroke-width="1.5">
    <path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>
</svg>
@endsection

@section('actions')
<button onclick="location.reload()" class="btn btn-primary">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <polyline points="23,4 23,10 17,10"/>
        <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/>
    </svg>
    التحقق مرة أخرى
</button>
@endsection
