<?php

namespace Tests\Feature\Api;

use App\Modules\Core\Models\LoginAttempt;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\AuthSecurityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * اختبارات منع تعداد المستخدمين (User Enumeration) في تسجيل الدخول.
 *
 * الهدف: التأكد من أن:
 *  1. الاستجابة موحّدة (نفس الحالة + نفس الجسم) سواء كان البريد موجودًا أم لا.
 *  2. لا تتسرّب عدادات "المحاولات المتبقية" أو عدّاد القفل (countdown) في الاستجابة العامة.
 *  3. معادلة التوقيت (timing) تمنع استنتاج وجود/عدم وجود المستخدم من الزمن.
 *  4. التفاصيل الحساسة تُسجَّل في الـ audit log (User.locked_until, failed_login_attempts,
 *     LoginAttempt، Log::warning) ولا تظهر للعميل.
 *
 * ملاحظة: الإصلاحات المرتبطة بهذه الاختبارات موثّقة في تقرير
 * `qa-reports/AUTHORIZATION-AUDIT-AND-PLAN.md` (P1-C).
 */
class LoginEnumerationLockoutTest extends TestCase
{
    use RefreshDatabase;

    /**
     * رسالة الخطأ الموحّدة التي يستخدمها المتحكم لكل حالات الفشل
     * (مستخدم غير موجود / كلمة مرور خاطئة / حساب مقفل).
     */
    private const UNIFIED_MESSAGE = 'البريد الإلكتروني أو كلمة المرور غير صحيحة';

    private string $password = 'CorrectPassword!1';

    protected function setUp(): void
    {
        parent::setUp();

        // التست كيس الأصلي يبذر الأدوار تلقائيًا عبر Tests\TestCase::setUp.
    }

    /**
     * يفك ترميز JSON_UNESCAPED_UNICODE إن أمكن ويُعيد نصًّا قابلاً للبحث.
     * نستخدمه لأن assertStringContainsString يقارن literal بينما getContent()
     * قد يُعيد escape sequences مثل \u0627 للبريد.
     */
    private function bodyToString(TestResponse $response): string
    {
        $decoded = json_decode($response->getContent(), true);
        if (is_array($decoded)) {
            return json_encode($decoded, JSON_UNESCAPED_UNICODE);
        }

        return $response->getContent();
    }

    /**
     * (1) مستخدم موجود + كلمة مرور خاطئة → استجابة موحّدة بدون أي تسرّب.
     */
    public function test_wrong_password_for_existing_user_returns_unified_response(): void
    {
        $user = User::factory()->create([
            'email' => 'victim@example.com',
            'password' => Hash::make($this->password),
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'wrong-password-9999',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);

        $body = $this->bodyToString($response);

        $this->assertStringContainsString(self::UNIFIED_MESSAGE, $body);
        $this->assertStringNotContainsString('محاولات', $body, 'Response leaked attempt counter');
        $this->assertStringNotContainsString('minutes', $body, 'Response leaked duration info');
        $this->assertStringNotContainsString('دقائق', $body, 'Response leaked duration info (Arabic)');
        $this->assertStringNotContainsString('ثانية', $body, 'Response leaked countdown (Arabic)');
        $this->assertStringNotContainsString('second', $body, 'Response leaked countdown (English)');
        $this->assertStringNotContainsString('locked_until', $body, 'Response leaked locked_until');
        $this->assertStringNotContainsString('retry_after', $body, 'Response leaked retry_after');
        $this->assertStringNotContainsString('remaining_attempts', $body, 'Response leaked remaining_attempts');
        $this->assertStringNotContainsString($user->email, $body, 'Response echoed the attempted email');
    }

    /**
     * (2) مستخدم غير موجود → استجابة مطابقة byte-equal لاستجابة "مستخدم موجود + كلمة مرور خاطئة".
     */
    public function test_login_for_nonexistent_user_returns_identical_response(): void
    {
        $existingUser = User::factory()->create([
            'email' => 'real@example.com',
            'password' => Hash::make($this->password),
            'is_active' => true,
        ]);

        // الاستجابة لمستخدم موجود + كلمة مرور خاطئة
        $existingResponse = $this->postJson('/api/login', [
            'email' => $existingUser->email,
            'password' => 'wrong-password-9999',
        ]);

        $nonexistentEmail = 'ghost-'.Str::uuid()->toString().'@example.com';

        $nonexistentResponse = $this->postJson('/api/login', [
            'email' => $nonexistentEmail,
            'password' => 'any-password-12345',
        ]);

        // التحقق من أن الحالتين تُرجعان نفس الحالة
        $existingResponse->assertStatus(422);
        $nonexistentResponse->assertStatus(422);

        // التحقق من تطابق الجسم byte-equal
        $existingBody = $existingResponse->getContent();
        $nonexistentBody = $nonexistentResponse->getContent();

        $this->assertSame(
            $existingBody,
            $nonexistentBody,
            "Bodies must be identical for existing vs nonexistent user.\n".
            "Existing: {$existingBody}\nNonexistent: {$nonexistentBody}"
        );

        // تأكيدات إضافية على الـ nonexistent (نفس الرسالة الموحّدة)
        $nonexistentDecoded = $this->bodyToString($nonexistentResponse);
        $this->assertStringContainsString(self::UNIFIED_MESSAGE, $nonexistentDecoded);
        $this->assertStringNotContainsString($nonexistentEmail, $nonexistentDecoded);
    }

    /**
     * (3) قفل الحساب بعد محاولات فاشلة → الاستجابة العامة لا تكشف duration/countdown.
     * التفاصيل تذهب إلى audit log (User.locked_until، LoginAttempt، Log::warning).
     */
    public function test_lockout_response_does_not_leak_countdown(): void
    {
        $user = User::factory()->create([
            'email' => 'willbelocked@example.com',
            'password' => Hash::make($this->password),
            'is_active' => true,
        ]);

        // تشغيل القفل مباشرة عبر الخدمة (بدون المرور بـ throttle:login middleware)
        $service = app(AuthSecurityService::class);
        for ($i = 0; $i < AuthSecurityService::MAX_FAILED_ATTEMPTS; $i++) {
            $service->recordFailedAttempt($user->email, '10.0.0.99', 'TestAgent/1.0');
        }

        // التحقق من أن القفل تم تسجيله في الـ audit trail (User model)
        $user->refresh();
        $this->assertNotNull($user->locked_until, 'locked_until should be set after max failed attempts');
        $this->assertTrue($user->locked_until->isFuture(), 'locked_until should be in the future');
        $this->assertGreaterThanOrEqual(AuthSecurityService::MAX_FAILED_ATTEMPTS, $user->failed_login_attempts);

        // التحقق من تسجيل المحاولات في LoginAttempt
        $attempts = LoginAttempt::where('email', strtolower($user->email))->count();
        $this->assertGreaterThanOrEqual(
            AuthSecurityService::MAX_FAILED_ATTEMPTS,
            $attempts,
            'LoginAttempt rows should be recorded as the audit trail'
        );

        // الآن: طلب login على الحساب المقفل
        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'even-the-correct-password-does-not-unblock-immediately',
        ]);

        // الاستجابة يجب أن تكون 422 (وليس 429) — نفس مسار "كلمة مرور خاطئة"
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email']);

        $body = $this->bodyToString($response);

        // لا تسرّب لأي مؤشّر على القفل أو المدة
        $this->assertStringContainsString(self::UNIFIED_MESSAGE, $body);
        $this->assertStringNotContainsString('محاولات', $body, 'Response leaked attempt counter');
        $this->assertStringNotContainsString('minutes', $body, 'Response leaked duration');
        $this->assertStringNotContainsString('دقائق', $body, 'Response leaked duration (Arabic)');
        $this->assertStringNotContainsString('ثانية', $body, 'Response leaked countdown (Arabic)');
        $this->assertStringNotContainsString('second', $body, 'Response leaked countdown (English)');
        $this->assertStringNotContainsString('locked', $body, 'Response leaked lockout state');
        $this->assertStringNotContainsString('مقفل', $body, 'Response leaked lockout state (Arabic)');
        $this->assertStringNotContainsString('locked_until', $body);
        $this->assertStringNotContainsString('retry_after', $body);
        $this->assertStringNotContainsString('15', $body, 'Response leaked lockout duration (15 min)');
        $this->assertStringNotContainsString('30', $body, 'Response leaked lockout duration (30 min)');

        // لا يجب أن تكشف الرسالة أي عدد صحيح يشبه مدة
        $this->assertDoesNotMatchRegularExpression('/\b\d+\s*(min|minute|دقيقة|ثانية|second)s?\b/u', $body);
    }

    /**
     * (4) تسجيل دخول شرعي لا يزال يعمل بنفس الـ happy path.
     */
    public function test_legitimate_login_still_works(): void
    {
        $user = User::factory()->create([
            'email' => 'legit@example.com',
            'password' => Hash::make($this->password),
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => $this->password,
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'user' => [
                    'id',
                    'name',
                    'email',
                    'roles',
                    'capabilities',
                    'access',
                    'scoped_roles',
                ],
            ])
            ->assertJsonMissingPath('user.permissions');
    }

    /**
     * (5) معادلة التوقيت: الفرق بين استجابة "مستخدم موجود + كلمة مرور خاطئة"
     * و"مستخدم غير موجود" يجب أن يكون صغيرًا (≤ 300ms في هذا البيئة).
     *
     * ملاحظة: هذا الاختبار حساس للأداء وقد يكون متقلّبًا في CI البطيء.
     * إذا فشل بسبب عدم استقرار البيئة، يُمكن تخطّيه مؤقتًا — لا يجب حذفه
     * لأنه يضمن أن الإصلاح لم يُضع معادلة التوقيت.
     */
    public function test_timing_difference_for_existing_vs_nonexistent_user_is_bounded(): void
    {
        $user = User::factory()->create([
            'email' => 'timing-victim@example.com',
            'password' => Hash::make($this->password),
            'is_active' => true,
        ]);

        // تسخين: تنفيذ طلبين لا يُحسبان — لتجنّب فرق cold-start
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'warmup'])->assertStatus(422);
        $this->postJson('/api/login', ['email' => 'warmup@example.com', 'password' => 'warmup'])->assertStatus(422);

        // قياس: مستخدم موجود + كلمة مرور خاطئة
        $t0 = microtime(true);
        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'wrong-password-timing',
        ])->assertStatus(422);
        $tExisting = microtime(true) - $t0;

        // قياس: مستخدم غير موجود
        $ghostEmail = 'ghost-'.Str::uuid()->toString().'@example.com';
        $t0 = microtime(true);
        $this->postJson('/api/login', [
            'email' => $ghostEmail,
            'password' => 'wrong-password-timing',
        ])->assertStatus(422);
        $tNonexistent = microtime(true) - $t0;

        $diff = abs($tExisting - $tNonexistent);

        // نتوقع فرق ≤ 300ms (bcrypt round واحد). في CI البطيء قد يصل إلى 500ms.
        $bound = (float) (getenv('LOGIN_TIMING_BOUND_MS') ?: 300) / 1000.0;

        $this->assertLessThanOrEqual(
            $bound,
            $diff,
            sprintf(
                'Timing difference (%.3fs) exceeds bound (%.3fs). existing=%.3fs, nonexistent=%.3fs',
                $diff, $bound, $tExisting, $tNonexistent
            )
        );
    }
}
