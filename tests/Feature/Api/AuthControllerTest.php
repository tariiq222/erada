<?php

namespace Tests\Feature\Api;

use App\Modules\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AuthControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * اختبار تسجيل دخول ناجح
     */
    public function test_user_can_login_with_valid_credentials(): void
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('password123'),
            'is_active' => true,
        ]);

        $response = $this->csrfSafeJson('post', '/api/login', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'user' => [
                    'id',
                    'name',
                    'email',
                    'capabilities',
                    'access',
                    'role_assignments',
                ],
            ])
            ->assertJsonMissingPath('user.roles')
            ->assertJsonMissingPath('user.scoped_roles')
            ->assertJsonMissingPath('user.permissions')
            ->assertJsonMissingPath('token')
            ->assertCookie('auth_token');
    }

    /**
     * اختبار رفض تسجيل دخول ببيانات خاطئة
     */
    public function test_user_cannot_login_with_invalid_credentials(): void
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('password123'),
            'is_active' => true,
        ]);

        $response = $this->csrfSafeJson('post', '/api/login', [
            'email' => 'test@example.com',
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    /**
     * اختبار رفض تسجيل دخول لحساب غير مفعل
     */
    public function test_inactive_user_cannot_login(): void
    {
        $user = User::factory()->create([
            'email' => 'inactive@example.com',
            'password' => Hash::make('password123'),
            'is_active' => false,
        ]);

        $response = $this->csrfSafeJson('post', '/api/login', [
            'email' => 'inactive@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    /**
     * اختبار التحقق من صحة البريد الإلكتروني
     */
    public function test_login_requires_valid_email(): void
    {
        $response = $this->csrfSafeJson('post', '/api/login', [
            'email' => 'invalid-email',
            'password' => 'password123',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    /**
     * اختبار الحقول المطلوبة
     */
    public function test_login_requires_email_and_password(): void
    {
        $response = $this->csrfSafeJson('post', '/api/login', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'password']);
    }

    /**
     * اختبار تسجيل الخروج
     */
    public function test_user_can_logout(): void
    {
        $user = User::factory()->create([
            'is_active' => true,
        ]);

        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->csrfSafeJson('post', '/api/logout');

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'تم تسجيل الخروج بنجاح',
            ]);

        // التحقق من حذف الـ token
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $user->id,
        ]);
    }

    /**
     * اختبار جلب بيانات المستخدم الحالي
     */
    public function test_can_get_current_user(): void
    {
        $user = User::factory()->create([
            'is_active' => true,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/user');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'user' => [
                    'id',
                    'name',
                    'email',
                ],
            ]);
    }

    /**
     * اختبار رفض الوصول بدون مصادقة
     */
    public function test_unauthenticated_user_cannot_access_protected_routes(): void
    {
        $response = $this->getJson('/api/user');

        $response->assertStatus(401);
    }

    /**
     * اختبار تحديث الملف الشخصي
     */
    public function test_user_can_update_profile(): void
    {
        $user = User::factory()->create([
            'is_active' => true,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->csrfSafeJson('put', '/api/profile', [
                'name' => 'اسم جديد',
                'email' => $user->email,
                'phone' => '0501234567',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'تم تحديث الملف الشخصي بنجاح',
            ]);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'اسم جديد',
        ]);
    }

    /**
     * اختبار رفض البريد المكرر
     */
    public function test_cannot_update_profile_with_existing_email(): void
    {
        $existingUser = User::factory()->create([
            'email' => 'existing@example.com',
        ]);

        $user = User::factory()->create([
            'is_active' => true,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->csrfSafeJson('put', '/api/profile', [
                'name' => 'اسم جديد',
                'email' => 'existing@example.com',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    /**
     * اختبار تغيير كلمة المرور
     */
    public function test_user_can_change_password(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('OldPassword123!'),
            'is_active' => true,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->csrfSafeJson('put', '/api/profile/password', [
                'current_password' => 'OldPassword123!',
                'password' => 'NewPassword123!',
                'password_confirmation' => 'NewPassword123!',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'تم تغيير كلمة المرور بنجاح',
            ]);

        // التحقق من أن كلمة المرور الجديدة تعمل
        $this->assertTrue(Hash::check('NewPassword123!', $user->fresh()->password));
    }

    /**
     * اختبار رفض تغيير كلمة المرور بكلمة خاطئة
     */
    public function test_user_cannot_change_password_with_wrong_current_password(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('CorrectPassword123!'),
            'is_active' => true,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->csrfSafeJson('put', '/api/profile/password', [
                'current_password' => 'WrongPassword123!',
                'password' => 'NewPassword123!',
                'password_confirmation' => 'NewPassword123!',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['current_password']);
    }

    /**
     * اختبار Rate Limiting على تسجيل الدخول
     */
    public function test_login_is_rate_limited(): void
    {
        // إجراء 6 محاولات (الحد هو 5)
        for ($i = 0; $i < 6; $i++) {
            $response = $this->csrfSafeJson('post', '/api/login', [
                'email' => 'test@example.com',
                'password' => 'wrongpassword',
            ]);
        }

        // المحاولة السادسة يجب أن تُرفض بـ 429
        $response->assertStatus(429);
    }

    /**
     * إرسال طلب مع تخطّي CSRF (متاح فقط في بيئة testing)
     * E2E/Postman يستخدم نفس الـ header
     */
    protected function csrfSafeJson(string $method, string $uri, array $data = [], array $headers = []): TestResponse
    {
        $headers['X-Skip-Csrf'] = '1';
        $headers['Accept'] = 'application/json';

        return $this->json($method, $uri, $data, $headers);
    }
}
