<?php

namespace Tests\Feature\Api;

use App\Http\Middleware\EnsureCsrfForStateChangingApi;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Meetings\Models\Recommendation;
use App\Modules\Projects\Models\Project;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * اختبارات حماية CSRF على مسارات API
 *
 * السلوك المتوقع:
 *  - GET / HEAD / OPTIONS: تمر دون token
 *  - POST / PUT / PATCH / DELETE: يجب أن يحتوي الطلب على X-XSRF-TOKEN صالح
 *    (مطابق للـ session token) وإلا يُرجَع 419
 *  - إذا لم تكن هناك session نشطة (عميل بـ Bearer token فقط) → يتجاوز التحقق
 *  - في بيئة testing: header X-Skip-Csrf: 1 يتجاوز التحقق (لدعم E2E)
 */
class CsrfProtectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'employee', 'guard_name' => 'web']);
    }

    public function test_middleware_allows_get_requests_without_token(): void
    {
        $middleware = new EnsureCsrfForStateChangingApi;
        $request = Request::create('/api/users', 'GET');
        $request->headers->set('Accept', 'application/json');

        $response = $middleware->handle($request, fn ($r) => response()->json(['ok' => true]));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_middleware_allows_head_and_options_without_token(): void
    {
        $middleware = new EnsureCsrfForStateChangingApi;

        foreach (['HEAD', 'OPTIONS'] as $method) {
            $request = Request::create("/api/test-{$method}", $method);
            $response = $middleware->handle($request, fn ($r) => response()->json(['ok' => true]));
            $this->assertSame(200, $response->getStatusCode(), "{$method} should pass");
        }
    }

    public function test_middleware_returns_419_when_no_token_provided(): void
    {
        $middleware = new EnsureCsrfForStateChangingApi;
        $request = Request::create('/api/users', 'POST');
        $request->headers->set('Accept', 'application/json');
        $request->headers->set('Content-Type', 'application/json');

        $response = $middleware->handle($request, fn ($r) => response()->json(['ok' => true]));

        $this->assertSame(419, $response->getStatusCode(), 'POST without any CSRF token should return 419');
    }

    public function test_middleware_returns_419_when_session_token_mismatches(): void
    {
        $middleware = new EnsureCsrfForStateChangingApi;
        $request = Request::create('/api/users', 'POST');
        $request->headers->set('Accept', 'application/json');
        $request->headers->set('Content-Type', 'application/json');
        $request->headers->set('X-XSRF-TOKEN', 'wrong-token-value');

        $session = $this->app['session.store'];
        $session->start();
        $request->setLaravelSession($session);

        $response = $middleware->handle($request, fn ($r) => response()->json(['ok' => true]));

        $this->assertSame(419, $response->getStatusCode());
        $body = json_decode($response->getContent(), true);
        $this->assertIsString($body['message'] ?? null);
    }

    public function test_middleware_passes_when_session_token_matches(): void
    {
        $middleware = new EnsureCsrfForStateChangingApi;
        $session = $this->app['session.store'];
        $session->start();
        $csrfToken = $session->token();

        $request = Request::create('/api/users', 'POST');
        $request->headers->set('Accept', 'application/json');
        $request->headers->set('Content-Type', 'application/json');
        $request->headers->set('X-XSRF-TOKEN', $csrfToken);
        $request->setLaravelSession($session);

        $response = $middleware->handle($request, fn ($r) => response()->json(['ok' => true]));

        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * Real browser case: the SPA always sends BOTH headers — an ENCRYPTED
     * X-XSRF-TOKEN (read from the Laravel XSRF-TOKEN cookie) and a RAW
     * X-CSRF-TOKEN (the meta tag = session token). The middleware must accept
     * the request because the raw X-CSRF-TOKEN matches the session token, even
     * though X-XSRF-TOKEN is encrypted and never matches a raw comparison.
     */
    public function test_middleware_passes_with_encrypted_xsrf_and_raw_csrf_header(): void
    {
        $middleware = new EnsureCsrfForStateChangingApi;
        $session = $this->app['session.store'];
        $session->start();
        $csrfToken = $session->token();

        $encryptedXsrf = Crypt::encrypt(
            CookieValuePrefix::create('XSRF-TOKEN', Crypt::getKey()).$csrfToken,
            false
        );

        $request = Request::create('/api/users', 'POST');
        $request->headers->set('Accept', 'application/json');
        $request->headers->set('Content-Type', 'application/json');
        // Mirrors the SPA: encrypted cookie value in X-XSRF-TOKEN ...
        $request->headers->set('X-XSRF-TOKEN', $encryptedXsrf);
        // ... and the raw session token in X-CSRF-TOKEN (from the meta tag).
        $request->headers->set('X-CSRF-TOKEN', $csrfToken);
        $request->setLaravelSession($session);

        $response = $middleware->handle($request, fn ($r) => response()->json(['ok' => true]));

        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * The middleware must also accept an ENCRYPTED X-XSRF-TOKEN on its own by
     * decrypting it (Laravel's standard SPA contract), since clients may send
     * only the XSRF-TOKEN cookie header.
     */
    public function test_middleware_passes_with_encrypted_xsrf_token_only(): void
    {
        $middleware = new EnsureCsrfForStateChangingApi;
        $session = $this->app['session.store'];
        $session->start();
        $csrfToken = $session->token();

        $encryptedXsrf = Crypt::encrypt(
            CookieValuePrefix::create('XSRF-TOKEN', Crypt::getKey()).$csrfToken,
            false
        );

        $request = Request::create('/api/users', 'POST');
        $request->headers->set('Accept', 'application/json');
        $request->headers->set('Content-Type', 'application/json');
        $request->headers->set('X-XSRF-TOKEN', $encryptedXsrf);
        $request->setLaravelSession($session);

        $response = $middleware->handle($request, fn ($r) => response()->json(['ok' => true]));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_middleware_accepts_x_csrf_token_header(): void
    {
        $middleware = new EnsureCsrfForStateChangingApi;
        $session = $this->app['session.store'];
        $session->start();
        $csrfToken = $session->token();

        $request = Request::create('/api/users', 'PUT');
        $request->headers->set('Accept', 'application/json');
        $request->headers->set('Content-Type', 'application/json');
        $request->headers->set('X-CSRF-TOKEN', $csrfToken);
        $request->setLaravelSession($session);

        $response = $middleware->handle($request, fn ($r) => response()->json(['ok' => true]));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_middleware_accepts_token_from_body(): void
    {
        $middleware = new EnsureCsrfForStateChangingApi;
        $session = $this->app['session.store'];
        $session->start();
        $csrfToken = $session->token();

        $request = Request::create('/api/users', 'POST', ['_token' => $csrfToken]);
        $request->headers->set('Accept', 'application/json');
        $request->setLaravelSession($session);

        $response = $middleware->handle($request, fn ($r) => response()->json(['ok' => true]));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_middleware_returns_419_with_arabic_message(): void
    {
        $middleware = new EnsureCsrfForStateChangingApi;
        $session = $this->app['session.store'];
        $session->start();

        $request = Request::create('/api/users', 'POST');
        $request->headers->set('Accept', 'application/json');
        $request->headers->set('X-XSRF-TOKEN', 'invalid');
        $request->setLaravelSession($session);

        $response = $middleware->handle($request, fn ($r) => response()->json(['ok' => true]));

        $body = json_decode($response->getContent(), true);
        $this->assertStringContainsString('الجلسة', $body['message']);
    }

    public function test_x_skip_csrf_header_bypasses_in_testing_environment(): void
    {
        $middleware = new EnsureCsrfForStateChangingApi;

        $request = Request::create('/api/users', 'POST');
        $request->headers->set('Accept', 'application/json');
        $request->headers->set('X-Skip-Csrf', '1');

        $response = $middleware->handle($request, fn ($r) => response()->json(['ok' => true]));

        if (app()->environment('testing')) {
            $this->assertSame(200, $response->getStatusCode(), 'في testing يجب ألّا يحجب الـ bypass');
        } else {
            $this->assertSame(419, $response->getStatusCode(), 'خارج testing يجب ألّا يعمل الـ bypass');
        }
    }

    public function test_real_api_get_endpoint_does_not_block(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson('/api/user');

        $response->assertStatus(200);
    }

    // ========== integration: middleware wired on api group ==========

    /**
     * تمكين Middleware على هذه المجموعة من الاختبارات فقط.
     * TestCase::setUp() يستخدم withoutMiddleware() افتراضياً،
     * فنعكسه هنا بـ withMiddleware() لإثبات تسجيله على الـ api group.
     */
    private function enableCsrfMiddleware(): void
    {
        // Pin the stateful domain so the localhost Referer/Origin below is always
        // treated as stateful, regardless of the ambient .env (CI's .env.example
        // sets a production stateful domain that excludes localhost).
        config()->set('sanctum.stateful', ['localhost']);

        $this->withMiddleware(EnsureCsrfForStateChangingApi::class);
    }

    /**
     * تفعيل تدفق Sanctum الـ stateful (Referer من localhost) ليُضاف
     * StartSession إلى pipeline الـ api، وبالتالي يرى EnsureCsrfForStateChangingApi
     * جلسة نشطة ويفحص X-XSRF-TOKEN مقابل session token.
     */
    private function statefulHeaders(?string $xsrfToken = null): array
    {
        $headers = [
            'Referer' => 'http://localhost',
            'Origin' => 'http://localhost',
            'Accept' => 'application/json',
        ];

        if ($xsrfToken !== null) {
            $headers['X-XSRF-TOKEN'] = $xsrfToken;
        }

        return $headers;
    }

    public function test_api_get_request_is_not_blocked_by_csrf_middleware(): void
    {
        $this->enableCsrfMiddleware();
        $this->seed(RolesAndPermissionsSeeder::class);

        $department = Department::factory()->create();
        $user = User::factory()->create([
            'department_id' => $department->id,
            'is_active' => true,
        ]);
        $user->assignRole('super_admin');

        $response = $this->actingAs($user, 'sanctum')
            ->withHeaders($this->statefulHeaders())
            ->getJson('/api/recommendations');

        $response->assertStatus(200);
    }

    public function test_api_post_without_csrf_token_returns_419(): void
    {
        $this->enableCsrfMiddleware();
        $this->seed(RolesAndPermissionsSeeder::class);

        $department = Department::factory()->create();
        $project = Project::factory()->create(['department_id' => $department->id]);
        $user = User::factory()->create([
            'department_id' => $department->id,
            'is_active' => true,
        ]);
        $user->assignRole('super_admin');

        // X-XSRF-TOKEN خاطئ عمداً لتجاوز حقن X-Skip-Csrf التلقائي في TestCase::call()
        // ولإجبار Middleware على رؤية token موجود لكنه لا يطابق session token
        // → mismatch → 419
        $response = $this->actingAs($user, 'sanctum')
            ->withHeaders($this->statefulHeaders(xsrfToken: 'invalid-token-for-test'))
            ->postJson('/api/recommendations', [
                'kind' => Recommendation::KIND_RULING,
                'meeting_id' => $this->makeMeetingFor($project)->id,
                'title' => 'قرار اختباري',
                'decidable_type' => Project::class,
                'decidable_id' => $project->id,
                'type' => 'approval',
                'priority' => Recommendation::PRIORITY_MEDIUM,
            ]);

        $response->assertStatus(419);
    }

    private function makeMeetingFor(Project $project): Meeting
    {
        return Meeting::factory()->create([
            'organization_id' => $project->organization_id,
            'department_id' => $project->department_id,
        ]);
    }

    public function test_api_post_with_valid_csrf_token_passes_csrf_check(): void
    {
        $this->enableCsrfMiddleware();
        $this->seed(RolesAndPermissionsSeeder::class);

        $department = Department::factory()->create();
        $project = Project::factory()->create(['department_id' => $department->id]);
        $user = User::factory()->create([
            'department_id' => $department->id,
            'is_active' => true,
        ]);
        $user->assignRole('super_admin');

        // تشغيل Session محلياً وقراءة الـ token الناتج، ثم إرساله كـ X-XSRF-TOKEN
        $session = $this->app['session.store'];
        $session->start();
        $csrfToken = $session->token();

        $response = $this->actingAs($user, 'sanctum')
            ->withHeaders($this->statefulHeaders(xsrfToken: $csrfToken))
            ->postJson('/api/recommendations', [
                'kind' => Recommendation::KIND_RULING,
                'meeting_id' => $this->makeMeetingFor($project)->id,
                'title' => 'قرار اختباري',
                'decidable_type' => Project::class,
                'decidable_id' => $project->id,
                'type' => 'approval',
                'priority' => Recommendation::PRIORITY_MEDIUM,
            ]);

        // 201 نجاح، أو 422 خطأ تحقق (أقل من 500) — الأهم أنه اجتاز فحص CSRF
        $this->assertContains(
            $response->getStatusCode(),
            [201, 422],
            'POST مع X-XSRF-TOKEN صحيح يجب أن يجتاز فحص CSRF (status: '.$response->getStatusCode().')'
        );
    }

    public function test_api_put_without_csrf_token_returns_419(): void
    {
        $this->enableCsrfMiddleware();
        $this->seed(RolesAndPermissionsSeeder::class);

        $department = Department::factory()->create();
        $project = Project::factory()->create(['department_id' => $department->id]);
        $user = User::factory()->create([
            'department_id' => $department->id,
            'is_active' => true,
        ]);
        $user->assignRole('super_admin');

        $meeting = Meeting::factory()->create([
            'organization_id' => $project->organization_id,
            'department_id' => $project->department_id,
        ]);

        $recommendation = Recommendation::create([
            'kind' => Recommendation::KIND_RULING,
            'meeting_id' => $meeting->id,
            'title' => 'قرار للتحديث',
            'decidable_type' => Project::class,
            'decidable_id' => $project->id,
            'type' => 'approval',
            'requested_by' => $user->id,
            'status' => Recommendation::STATUS_PENDING,
            'priority' => Recommendation::PRIORITY_MEDIUM,
            'organization_id' => $project->organization_id,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->withHeaders($this->statefulHeaders(xsrfToken: 'invalid-token-for-test'))
            ->putJson("/api/recommendations/{$recommendation->id}", [
                'title' => 'قرار محدث',
                'type' => 'change_request',
                'priority' => Recommendation::PRIORITY_MEDIUM,
            ]);

        $response->assertStatus(419);
    }
}
