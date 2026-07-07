<!DOCTYPE html>
<html lang="{{ $appLocale ?? 'ar' }}" dir="{{ $textDirection ?? 'rtl' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>منصة إرادة</title>
    <link rel="icon" type="image/png" href="/favicon.png">

    {{-- Critical CSS for Loading Screen --}}
    <style nonce="@cspNonce">
        :root {
            --accent-default: #1C82C7;
            --accent-subtle: #E2F2FB;
            --surface-base: #FFFFFF;
            --surface-subtle: #F5F7FB;
            --text-primary: #0F1729;
            --text-secondary: #4A5567;
            --shadow-md: 0 2px 4px -2px rgba(16,24,40,.05), 0 6px 16px -6px rgba(16,24,40,.10);
        }
        .dark {
            --accent-default: #54B7EC;
            --accent-subtle: #0E2A3C;
            --surface-base: #102230;
            --surface-subtle: #08151F;
            --text-primary: #E9F0F7;
            --text-secondary: #A3B6CC;
            --shadow-md: 0 2px 6px rgba(0,0,0,.35), 0 12px 28px -10px rgba(0,0,0,.55);
        }
        body {
            font-family: 'IBM Plex Sans Arabic', sans-serif;
            margin: 0;
        }
        /* Force English/Western Numbers */
        * {
            font-feature-settings: "tnum" on, "lnum" on;
            font-variant-numeric: tabular-nums lining-nums;
        }
        #app-loader {
            min-height: 100vh;
            background: var(--surface-subtle);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        #app-loader .loader-content {
            text-align: center;
        }
        #app-loader .loader-icon {
            width: 64px;
            height: 64px;
            background: var(--accent-subtle);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 24px;
            color: var(--accent-default);
            box-shadow: var(--shadow-md);
        }
        #app-loader .loader-dots {
            display: flex;
            justify-content: center;
            gap: 4px;
            margin-bottom: 12px;
        }
        #app-loader .loader-dot {
            width: 8px;
            height: 8px;
            background: var(--accent-default);
            border-radius: 50%;
        }
        #app-loader .loader-dot:nth-child(1) { opacity: 0.45; }
        #app-loader .loader-dot:nth-child(2) { opacity: 0.7; }
        #app-loader .loader-text {
            color: var(--text-secondary);
            font-weight: 500;
            font-size: 14px;
        }
    </style>

    {{-- Prevent Flash of Unstyled Content (FOUC) for Dark Mode --}}
    <script nonce="@cspNonce">
        (function() {
            const theme = localStorage.getItem('iradah-theme');
            const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            if (theme === 'dark' || (theme === 'system' && prefersDark)) {
                document.documentElement.classList.add('dark');
            }
        })();
    </script>

    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.tsx'])
</head>
<body class="antialiased transition-colors duration-300" style="background-color: var(--surface-subtle);">
    {{-- Initial Loading Screen (shown before React loads) --}}
    <div id="app-loader">
        <div class="loader-content">
            <div class="loader-icon">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="m12 3-1.912 5.813a2 2 0 0 1-1.275 1.275L3 12l5.813 1.912a2 2 0 0 1 1.275 1.275L12 21l1.912-5.813a2 2 0 0 1 1.275-1.275L21 12l-5.813-1.912a2 2 0 0 1-1.275-1.275L12 3Z"/>
                    <path d="M5 3v4"/><path d="M19 17v4"/><path d="M3 5h4"/><path d="M17 19h4"/>
                </svg>
            </div>
            <div class="loader-dots">
                <div class="loader-dot"></div>
                <div class="loader-dot"></div>
                <div class="loader-dot"></div>
            </div>
            <p class="loader-text">جاري التحميل...</p>
        </div>
    </div>
    <div id="app" style="display:none;"></div>

    <script nonce="@cspNonce">
        // Hide loader when React app is ready
        window.addEventListener('DOMContentLoaded', function() {
            var checkApp = setInterval(function() {
                var app = document.getElementById('app');
                if (app && app.children.length > 0) {
                    document.getElementById('app-loader').style.display = 'none';
                    app.style.display = 'block';
                    clearInterval(checkApp);
                }
            }, 50);
        });
    </script>
</body>
</html>
