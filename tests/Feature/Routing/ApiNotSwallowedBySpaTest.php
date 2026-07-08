<?php

namespace Tests\Feature\Routing;

use Tests\TestCase;

/**
 * اختبارات لتأكيد أن catch-all الخاص بـ SPA لا يبتلع مسارات API.
 *
 * المشكلة: قبل الإصلاح، `Route::get('/{any}', ...)->where('any', '.*')`
 * يطابق أي GET — بما في ذلك GET على مسارات API المعرّفة كـ POST/PUT/DELETE.
 * النتيجة: 200 OK مع HTML الخاص بـ SPA بدل 405 Method Not Allowed.
 *
 * بعد الإصلاح، الـ catch-all يستثني `api/*` فتصل الطلبات إلى مطابق API
 * الذي يطلق MethodNotAllowedHttpException (405) أو NotFoundHttpException (404).
 */
class ApiNotSwallowedBySpaTest extends TestCase
{
    /**
     * علامة مميزة لصفحة الـ SPA (موجودة فقط في resources/views/app.blade.php).
     * مجمّع React يركّب داخل `<div id="app">` — صفحات الخطأ وصفحات الـ login
     * لا تستخدم هذا العنصر، فلا يحدث إيجاب كاذب عندما تُرجع الـ API 404/405
     * مع قالب الخطأ الذي يحتوي على اسم التطبيق عبر `config('app.name')`.
     */
    private const SPA_ROOT_DIV = '<div id="app"';

    public function test_get_to_api_post_route_returns_405_not_spa_html(): void
    {
        // /api/recommendations/{id}/approve is a real POST-only route
        // (Direction B, 2026-07-06: the legacy /api/strategy/decisions/* and
        // /api/decisions/* aliases were retired; approve moved under
        // /api/recommendations/{id}/approve). GET on it must hit the API
        // matcher (405), not be swallowed by the SPA catch-all.
        $response = $this->get('/api/recommendations/123/approve');

        $response->assertStatus(405);
        $this->assertStringNotContainsString(
            self::SPA_ROOT_DIV,
            $response->getContent(),
            'SPA catch-all swallowed a wrong-method API request and returned the SPA HTML.'
        );
    }

    public function test_get_to_nonexistent_api_path_returns_404_not_spa_html(): void
    {
        $response = $this->get('/api/__nonexistent__/route-that-does-not-exist');

        $response->assertStatus(404);
        $this->assertStringNotContainsString(
            self::SPA_ROOT_DIV,
            $response->getContent(),
            'SPA catch-all swallowed a non-existent API request and returned the SPA HTML.'
        );
    }

    public function test_root_spa_path_still_returns_spa_html(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
        $this->assertStringContainsString(
            self::SPA_ROOT_DIV,
            $response->getContent(),
            'SPA catch-all should still serve the React shell for the root path.'
        );
    }

    public function test_nested_spa_path_still_returns_spa_html(): void
    {
        $response = $this->get('/projects/abc-123/edit');

        $response->assertStatus(200);
        $this->assertStringContainsString(
            self::SPA_ROOT_DIV,
            $response->getContent(),
            'SPA catch-all should still serve the React shell for nested client routes.'
        );
    }
}
