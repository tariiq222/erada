<?php

namespace Tests\Feature;

use App\Http\Middleware\SessionTimeout;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * اختبارات تكامل لـ SessionTimeout middleware بعد تسجيله في المجموعة api
 *
 * يغطي:
 * - الطلب على مسار محمي بـ auth:sanctum يستجيب 200 ويُضيف رؤوس X-Session-Timeout / X-Session-Expires-At
 * - بعد تجاوز مهلة الخمول، الطلب يستجيب 401 برسالة session_timeout ويُحذف توكن المستخدم
 * - الطلب غير المصادق يمر دون تدخل من SessionTimeout (401 بسبب auth:sanctum فقط)
 */
class SessionTimeoutMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $plainToken;

    protected function setUp(): void
    {
        parent::setUp();

        $department = Department::factory()->create();
        $this->user = User::factory()->create([
            'department_id' => $department->id,
            'is_active' => true,
        ]);
        $this->user->assignRole('member');

        $this->plainToken = $this->user->createToken('session-timeout-test')->plainTextToken;

        Cache::forget('user_last_activity_'.$this->user->id);
    }

    public function test_middleware_is_registered_in_api_group(): void
    {
        $middleware = app(Kernel::class)
            ->getMiddlewareGroups()['api'] ?? [];

        $this->assertContains(SessionTimeout::class, $middleware);
    }

    public function test_authenticated_request_passes_and_sets_session_headers(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->plainToken,
        ])->getJson('/api/user');

        $response->assertStatus(200);
        $response->assertHeader('X-Session-Timeout');
        $response->assertHeader('X-Session-Expires-At');

        $this->assertNotNull(Cache::get('user_last_activity_'.$this->user->id));
    }

    public function test_idle_timeout_returns_401_session_timeout_and_revokes_token(): void
    {
        $cacheKey = 'user_last_activity_'.$this->user->id;
        $idleMinutes = SessionTimeout::getIdleTimeoutMinutes() + 5;
        Cache::put($cacheKey, now()->subMinutes($idleMinutes), now()->addHour());

        $tokenId = PersonalAccessToken::findToken($this->plainToken)->id;
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $tokenId]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->plainToken,
        ])->getJson('/api/user');

        $response->assertStatus(401);
        $response->assertJsonFragment(['reason' => 'session_timeout']);

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenId]);
        $this->assertNull(Cache::get($cacheKey));
    }

    public function test_unauthenticated_request_is_not_blocked_by_session_timeout(): void
    {
        $response = $this->getJson('/api/user');

        $response->assertStatus(401);
        $response->assertJsonMissing(['reason' => 'session_timeout']);
    }
}
