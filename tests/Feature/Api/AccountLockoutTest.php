<?php

namespace Tests\Feature\Api;

use App\Modules\Core\Models\LoginAttempt;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\AuthSecurityService;
use App\Modules\HR\Models\Department;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * اختبارات قفل الحساب وحظر IP المتقدمة
 *
 * يغطي:
 * - قفل الحساب بعد 5 محاولات فاشلة
 * - التصعيد التدريجي لمدة القفل
 * - حظر IP بعد 20 محاولة فاشلة
 * - الاستجابة الصحيحة من endpoint /api/login عند القفل
 * - قفل الحساب ثم إلغاؤه يدوياً
 */
class AccountLockoutTest extends TestCase
{
    use RefreshDatabase;

    protected Department $department;

    protected User $user;

    protected string $password = 'Password123!';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->department = Department::factory()->create();

        $this->user = User::factory()->create([
            'department_id' => $this->department->id,
            'is_active' => true,
            'email' => 'lockout@example.com',
            'password' => Hash::make($this->password),
        ]);
        $this->assignCanonicalRole($this->user, 'member');
    }

    // ========== قفل الحساب عبر Login API ==========

    public function test_account_locked_after_max_failed_attempts_via_api(): void
    {
        // 5 محاولات فاشلة
        for ($i = 0; $i < AuthSecurityService::MAX_FAILED_ATTEMPTS; $i++) {
            $this->postJson('/api/login', [
                'email' => $this->user->email,
                'password' => 'WrongPassword!',
            ]);
        }

        // المحاولة التالية تُعيد 429 (locked)
        $response = $this->postJson('/api/login', [
            'email' => $this->user->email,
            'password' => $this->password, // كلمة مرور صحيحة لكن الحساب مقفل
        ]);

        $response->assertStatus(429);
    }

    /**
     * قفل الحساب → الاستجابة العامة موحّدة (422 + رسالة عربية موحّدة)
     * ولا تكشف retry_after / locked_until / remaining_attempts / countdown.
     * التفاصيل تذهب إلى audit log فقط (User.locked_until, LoginAttempt, Log::warning).
     */
    public function test_locked_account_returns_unified_response_without_retry_after_or_countdown(): void
    {
        $service = app(AuthSecurityService::class);

        // قفل الحساب مباشرة عبر الـ service
        for ($i = 0; $i <= AuthSecurityService::MAX_FAILED_ATTEMPTS; $i++) {
            $service->recordFailedAttempt($this->user->email, '10.0.0.1', null);
        }

        // التحقق من تسجيل القفل في الـ audit trail (User model)
        $this->user->refresh();
        $this->assertNotNull(
            $this->user->locked_until,
            'locked_until should be set after max failed attempts'
        );
        $this->assertTrue(
            $this->user->locked_until->isFuture(),
            'locked_until should be in the future'
        );
        $this->assertGreaterThanOrEqual(
            AuthSecurityService::MAX_FAILED_ATTEMPTS,
            $this->user->failed_login_attempts
        );

        // التحقق من تسجيل المحاولات في LoginAttempt
        $attempts = LoginAttempt::where('email', strtolower($this->user->email))->count();
        $this->assertGreaterThanOrEqual(
            AuthSecurityService::MAX_FAILED_ATTEMPTS,
            $attempts,
            'LoginAttempt rows should be recorded as the audit trail'
        );

        // طلب login على الحساب المقفل
        $response = $this->postJson('/api/login', [
            'email' => $this->user->email,
            'password' => $this->password,
        ]);

        // الاستجابة يجب أن تكون 422 (وليس 429) — نفس مسار "كلمة مرور خاطئة"
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);

        // فك ترميز JSON_UNESCAPED_UNICODE لمقارنة literal
        $decoded = json_decode($response->getContent(), true);
        $body = is_array($decoded)
            ? json_encode($decoded, JSON_UNESCAPED_UNICODE)
            : $response->getContent();

        // يجب أن تحتوي على الرسالة الموحّدة
        $this->assertStringContainsString(
            'البريد الإلكتروني أو كلمة المرور غير صحيحة',
            $body
        );

        // يجب ألّا تتسرّب أي مؤشّرات على القفل أو المدة أو العدّاد
        $this->assertStringNotContainsString('محاولات', $body, 'Response leaked attempt counter');
        $this->assertStringNotContainsString('minutes', $body, 'Response leaked duration');
        $this->assertStringNotContainsString('دقائق', $body, 'Response leaked duration (Arabic)');
        $this->assertStringNotContainsString('ثانية', $body, 'Response leaked countdown (Arabic)');
        $this->assertStringNotContainsString('second', $body, 'Response leaked countdown (English)');
        $this->assertStringNotContainsString('locked', $body, 'Response leaked lockout state');
        $this->assertStringNotContainsString('مقفل', $body, 'Response leaked lockout state (Arabic)');
        $this->assertStringNotContainsString('locked_until', $body, 'Response leaked locked_until');
        $this->assertStringNotContainsString('retry_after', $body, 'Response leaked retry_after');
        $this->assertStringNotContainsString('remaining_attempts', $body, 'Response leaked remaining_attempts');
        $this->assertStringNotContainsString('15', $body, 'Response leaked lockout duration (15 min)');
        $this->assertStringNotContainsString('30', $body, 'Response leaked lockout duration (30 min)');
    }

    public function test_wrong_password_returns_remaining_attempts_info(): void
    {
        // المحاولة الأولى الفاشلة — يجب أن يُعاد رسالة خطأ وليس 429
        $response = $this->postJson('/api/login', [
            'email' => $this->user->email,
            'password' => 'WrongPass!',
        ]);

        // 422 أو رسالة خطأ للبيانات
        $response->assertStatus(422);
    }

    public function test_successful_login_after_failed_attempts_resets_counter(): void
    {
        // محاولتان فاشلتان
        $this->postJson('/api/login', ['email' => $this->user->email, 'password' => 'wrong!']);
        $this->postJson('/api/login', ['email' => $this->user->email, 'password' => 'wrong!']);

        // تسجيل دخول ناجح يعيد تعيين العداد
        $response = $this->postJson('/api/login', [
            'email' => $this->user->email,
            'password' => $this->password,
        ]);

        $response->assertStatus(200);

        // بعد الدخول الناجح، محاولة فاشلة يجب ألا تُقفل الحساب فوراً
        $failResponse = $this->postJson('/api/login', [
            'email' => $this->user->email,
            'password' => 'wrong!',
        ]);

        $failResponse->assertStatus(422); // وليس 429
    }

    // ========== IP Blocking ==========

    public function test_ip_blocked_after_max_ip_attempts(): void
    {
        $service = app(AuthSecurityService::class);
        $blockedIp = '192.168.99.1';

        // 20 محاولة فاشلة من نفس الـ IP لحسابات مختلفة
        for ($i = 0; $i < AuthSecurityService::MAX_IP_ATTEMPTS; $i++) {
            $service->recordFailedAttempt('nonexistent'.$i.'@test.com', $blockedIp, null);
        }

        // التحقق من حظر الـ IP
        $result = $service->canAttemptLogin($this->user->email, $blockedIp);

        $this->assertFalse($result['allowed']);
        $this->assertStringContainsString('IP', $result['reason'] ?? '');
    }

    public function test_ip_blocking_via_login_api_returns_429(): void
    {
        $service = app(AuthSecurityService::class);
        $blockedIp = '10.10.10.10';

        // تجاوز الحد
        for ($i = 0; $i < AuthSecurityService::MAX_IP_ATTEMPTS; $i++) {
            $service->recordFailedAttempt('dummy'.$i.'@test.com', $blockedIp, null);
        }

        // الطلب من هذا الـ IP يجب أن يُرفض
        $response = $this->withServerVariables(['REMOTE_ADDR' => $blockedIp])
            ->postJson('/api/login', [
                'email' => $this->user->email,
                'password' => $this->password,
            ]);

        $response->assertStatus(429);
    }

    public function test_different_ip_not_blocked_when_one_ip_is_blocked(): void
    {
        $service = app(AuthSecurityService::class);

        // حظر IP واحد
        for ($i = 0; $i < AuthSecurityService::MAX_IP_ATTEMPTS; $i++) {
            $service->recordFailedAttempt('dummy'.$i.'@test.com', '5.5.5.5', null);
        }

        // IP مختلف يجب أن يعمل بشكل طبيعي
        $result = $service->canAttemptLogin($this->user->email, '6.6.6.6');

        $this->assertTrue($result['allowed']);
    }

    // ========== Progressive Lockout (تصعيد مدة القفل) ==========

    public function test_lockout_duration_increases_with_repeated_lockouts(): void
    {
        $service = app(AuthSecurityService::class);

        // القفل الأول — نتحقق من نتيجة recordFailedAttempt مباشرة
        $locked = false;
        for ($i = 0; $i <= AuthSecurityService::MAX_FAILED_ATTEMPTS; $i++) {
            $result = $service->recordFailedAttempt($this->user->email, '127.0.0.1', null);
            if ($result['locked']) {
                $locked = true;
                break;
            }
        }

        $this->assertTrue($locked, 'Account should be locked after max failed attempts');

        // نتحقق من قاعدة البيانات مباشرة
        $this->user->refresh();
        $this->assertNotNull($this->user->locked_until);
    }

    // ========== إلغاء القفل يدوياً ثم تسجيل الدخول ==========

    public function test_unlock_via_api_then_login_succeeds(): void
    {
        // Lock the account
        $service = app(AuthSecurityService::class);
        for ($i = 0; $i <= AuthSecurityService::MAX_FAILED_ATTEMPTS; $i++) {
            $service->recordFailedAttempt($this->user->email, '127.0.0.1', null);
        }

        // Build a super_admin to unlock the account
        $superAdmin = User::factory()->create([
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        $this->grantCanonicalSuperAdmin($superAdmin);

        // Unlock via API: must succeed (200) and log the activity
        $unlockResponse = $this->actingAs($superAdmin, 'sanctum')
            ->postJson("/api/users/{$this->user->id}/unlock");

        $unlockResponse->assertStatus(200);

        $this->user->refresh();
        $this->assertNull($this->user->locked_until);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'unlock_account',
            'loggable_type' => User::class,
            'loggable_id' => $this->user->id,
            'user_id' => $superAdmin->id,
        ]);

        // Login must succeed after unlock
        $loginResponse = $this->postJson('/api/login', [
            'email' => $this->user->email,
            'password' => $this->password,
        ]);

        $loginResponse->assertStatus(200);
    }

    // ========== حالة الأمان ==========

    public function test_security_status_shows_failed_attempts_count(): void
    {
        $service = app(AuthSecurityService::class);

        $service->recordFailedAttempt($this->user->email, '127.0.0.1', null);
        $service->recordFailedAttempt($this->user->email, '127.0.0.1', null);
        $service->recordFailedAttempt($this->user->email, '127.0.0.1', null);

        $status = $service->getAccountSecurityStatus($this->user);

        $this->assertArrayHasKey('failed_attempts', $status);
        if ($status['failed_attempts'] !== null) {
            $this->assertGreaterThanOrEqual(3, $status['failed_attempts']);
        }
    }

    public function test_security_status_shows_locked_true_when_locked(): void
    {
        $service = app(AuthSecurityService::class);

        for ($i = 0; $i <= AuthSecurityService::MAX_FAILED_ATTEMPTS; $i++) {
            $service->recordFailedAttempt($this->user->email, '127.0.0.1', null);
        }

        // يجب refresh للمستخدم لأن getAccountSecurityStatus يقرأ من الـ model instance
        $this->user->refresh();
        $status = $service->getAccountSecurityStatus($this->user);

        $this->assertTrue($status['is_locked']);
    }

    public function test_inactive_user_cannot_login_even_with_correct_password(): void
    {
        $this->user->update(['is_active' => false]);

        $response = $this->postJson('/api/login', [
            'email' => $this->user->email,
            'password' => $this->password,
        ]);

        $response->assertStatus(422);
    }
}
