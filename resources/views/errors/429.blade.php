@extends('errors.layout')

@section('code', '429')
@section('title', 'طلبات كثيرة جداً')
@section('message', 'لقد تجاوزت الحد المسموح من الطلبات. يرجى الانتظار قليلاً قبل المحاولة مرة أخرى.')

@section('icon')
<svg class="error-icon" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="1.5">
    <path d="M18 10h-1.26A8 8 0 1 0 9 20h9a5 5 0 0 0 0-10z"/>
    <line x1="12" y1="8" x2="12" y2="12"/>
    <line x1="12" y1="16" x2="12.01" y2="16"/>
</svg>
@endsection

@section('actions')
<button onclick="setTimeout(() => location.reload(), 30000)" class="btn btn-primary" id="waitBtn">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <circle cx="12" cy="12" r="10"/>
        <polyline points="12,6 12,12 16,14"/>
    </svg>
    <span id="countdown">انتظر 30 ثانية</span>
</button>
<a href="{{ url('/') }}" class="btn btn-secondary">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
        <polyline points="9,22 9,12 15,12 15,22"/>
    </svg>
    الصفحة الرئيسية
</a>

<script nonce="@cspNonce">
    let seconds = 30;
    const countdown = document.getElementById('countdown');
    const btn = document.getElementById('waitBtn');

    const timer = setInterval(() => {
        seconds--;
        countdown.textContent = `انتظر ${seconds} ثانية`;

        if (seconds <= 0) {
            clearInterval(timer);
            countdown.textContent = 'إعادة المحاولة';
            btn.onclick = () => location.reload();
        }
    }, 1000);
</script>
@endsection
