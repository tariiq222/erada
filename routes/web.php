<?php

use Illuminate\Support\Facades\Route;

// Login route for API authentication redirects
Route::get('/login', function () {
    return view('app');
})->name('login');

// Language Switcher
Route::get('/language/{locale}', function (string $locale) {
    if (in_array($locale, ['ar', 'en'])) {
        session(['locale' => $locale]);
        app()->setLocale($locale);
    }

    return redirect()->back();
})->name('language.switch');

// رابط الاستبيان العام المختصر - يجب أن يكون قبل SPA catch-all
Route::get('/s/{code}', function () {
    return view('app');
})->name('survey.public.short');

// SPA Catch-all Route - React Router handles all frontend routes
// Excludes `api/*` so wrong-method API requests return their proper status
// (404/405) instead of being swallowed and returning the SPA HTML with 200.
Route::get('/{any}', function () {
    return view('app');
})->where('any', '^(?!api).*$')->name('spa');
