<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') - {{ config('app.name', 'إرادة') }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'IBM Plex Sans Arabic', sans-serif;
            background: #f0f4f8;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 40px;
        }

        .logo-icon {
            width: 44px;
            height: 44px;
            background: #0ea5e9;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .logo-text {
            font-size: 22px;
            font-weight: 700;
            color: #102a43;
            letter-spacing: -0.5px;
        }

        .logo-sub {
            font-size: 11px;
            color: #627d98;
            font-weight: 400;
            display: block;
            margin-top: -2px;
        }

        .error-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 4px 24px rgba(16, 42, 67, 0.08);
            border: 1px solid #e2eaf2;
            max-width: 480px;
            width: 100%;
            padding: 48px 40px;
            text-align: center;
        }

        .error-icon {
            width: 64px;
            height: 64px;
            margin: 0 auto 24px;
        }

        .error-code {
            font-size: 96px;
            font-weight: 800;
            color: #0ea5e9;
            line-height: 1;
            margin-bottom: 12px;
            letter-spacing: -4px;
        }

        .error-title {
            font-size: 22px;
            font-weight: 700;
            color: #102a43;
            margin-bottom: 10px;
        }

        .error-message {
            font-size: 15px;
            color: #627d98;
            margin-bottom: 32px;
            line-height: 1.7;
        }

        .error-actions {
            display: flex;
            gap: 10px;
            justify-content: center;
            flex-wrap: wrap;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 11px 22px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.15s ease;
            cursor: pointer;
            border: none;
        }

        .btn-primary {
            background: #0ea5e9;
            color: white;
        }

        .btn-primary:hover {
            background: #0284c7;
            transform: translateY(-1px);
            box-shadow: 0 6px 16px -4px rgba(14, 165, 233, 0.4);
        }

        .btn-secondary {
            background: #f0f4f8;
            color: #334e68;
            border: 1px solid #d9e6f0;
        }

        .btn-secondary:hover {
            background: #e2eaf2;
        }

        .support-info {
            margin-top: 28px;
            padding-top: 20px;
            border-top: 1px solid #e2eaf2;
            font-size: 13px;
            color: #9fb3c8;
        }

        .support-info a {
            color: #0ea5e9;
            text-decoration: none;
        }

        .support-info a:hover { text-decoration: underline; }

        @media (max-width: 480px) {
            .error-code { font-size: 72px; }
            .error-container { padding: 36px 24px; }
        }
    </style>
</head>
<body>
    <div class="logo">
        <div class="logo-icon">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none">
                <path d="M12 2L3 7v10l9 5 9-5V7L12 2z" stroke="white" stroke-width="1.8" stroke-linejoin="round"/>
                <path d="M12 2v20M3 7l9 5 9-5" stroke="white" stroke-width="1.8" stroke-linejoin="round"/>
            </svg>
        </div>
        <div>
            <span class="logo-text">نظام إرادة</span>
            <span class="logo-sub">الإدارة التشغيلية والتخطيط المؤسسي</span>
        </div>
    </div>

    <div class="error-container">
        @yield('icon')
        <div class="error-code">@yield('code')</div>
        <h1 class="error-title">@yield('title')</h1>
        <p class="error-message">@yield('message')</p>
        <div class="error-actions">
            @yield('actions')
        </div>
        <div class="support-info">
            إذا استمرت المشكلة، تواصل مع <a href="mailto:support@iradah.sa">الدعم الفني</a>
        </div>
    </div>
</body>
</html>
