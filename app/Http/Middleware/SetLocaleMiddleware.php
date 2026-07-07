<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocaleMiddleware
{
    /**
     * اللغات التي تكتب من اليمين لليسار
     */
    protected array $rtlLocales = ['ar', 'he', 'fa', 'ur'];

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // أولوية تحديد اللغة: تفضيل المستخدم → الجلسة → الافتراضي
        $locale = session('locale', config('app.locale', 'ar'));

        $user = $request->user();
        if ($user && $user->preferred_locale) {
            $locale = $user->preferred_locale;
            session(['locale' => $locale]);
        }

        app()->setLocale($locale);

        // تحديد اتجاه اللغة
        $direction = in_array($locale, $this->rtlLocales) ? 'rtl' : 'ltr';

        // مشاركة المتغيرات مع جميع الـ Views
        view()->share('appLocale', $locale);
        view()->share('textDirection', $direction);

        return $next($request);
    }
}
