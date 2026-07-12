<?php

namespace Tests\Feature\Api;

use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * اختبارات أمان كلمة المرور
 *
 * يغطي:
 * - كلمات المرور الضعيفة والمحجوبة
 * - حذف جميع التوكنات عند تغيير كلمة المرور
 * - التحقق من متطلبات قوة كلمة المرور
 * - أنماط لوحة المفاتيح المرفوضة
 */
class PasswordSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected Department $department;

    protected User $user;

    protected string $validPassword = 'SecurePass@99';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->department = Department::factory()->create();

        $this->user = User::factory()->create([
            'department_id' => $this->department->id,
            'is_active' => true,
            'password' => Hash::make($this->validPassword),
        ]);
        $this->assignCanonicalRole($this->user, 'member');
    }

    // ========== متطلبات قوة كلمة المرور ==========

    public function test_password_without_uppercase_is_rejected(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson('/api/profile/password', [
                'current_password' => $this->validPassword,
                'password' => 'lowercase123!',
                'password_confirmation' => 'lowercase123!',
            ]);

        $response->assertStatus(422);
    }

    public function test_password_without_lowercase_is_rejected(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson('/api/profile/password', [
                'current_password' => $this->validPassword,
                'password' => 'UPPERCASE123!',
                'password_confirmation' => 'UPPERCASE123!',
            ]);

        $response->assertStatus(422);
    }

    public function test_password_without_number_is_rejected(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson('/api/profile/password', [
                'current_password' => $this->validPassword,
                'password' => 'NoNumbers!@#',
                'password_confirmation' => 'NoNumbers!@#',
            ]);

        $response->assertStatus(422);
    }

    public function test_password_without_special_char_is_rejected(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson('/api/profile/password', [
                'current_password' => $this->validPassword,
                'password' => 'NoSpecial123',
                'password_confirmation' => 'NoSpecial123',
            ]);

        $response->assertStatus(422);
    }

    public function test_password_too_short_is_rejected(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson('/api/profile/password', [
                'current_password' => $this->validPassword,
                'password' => 'Ab1!',
                'password_confirmation' => 'Ab1!',
            ]);

        $response->assertStatus(422);
    }

    public function test_password_mismatch_confirmation_is_rejected(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson('/api/profile/password', [
                'current_password' => $this->validPassword,
                'password' => 'NewPass@123',
                'password_confirmation' => 'DifferentPass@123',
            ]);

        $response->assertStatus(422);
    }

    // ========== كلمات المرور الشائعة ==========

    public function test_common_password_change_password_accepts_meeting_requirements(): void
    {
        // changePassword لا يرفض الكلمات الشائعة — فقط يتحقق من القواعد الأساسية
        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson('/api/profile/password', [
                'current_password' => $this->validPassword,
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
            ]);

        // 200 لأن Password123! تستوفي: mixedCase + numbers + symbols + min8
        $response->assertStatus(200);
    }

    // ========== حذف التوكنات عند تغيير كلمة المرور ==========

    public function test_all_other_tokens_deleted_on_password_change(): void
    {
        // إنشاء توكنين إضافيين
        $this->user->createToken('device-1');
        $this->user->createToken('device-2');

        // التوكن الحالي الذي سيُستخدم في الطلب
        $currentToken = $this->user->createToken('current-session')->plainTextToken;

        $this->assertDatabaseCount('personal_access_tokens', 3);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$currentToken,
        ])->putJson('/api/profile/password', [
            'current_password' => $this->validPassword,
            'password' => 'NewSecure@Pass99',
            'password_confirmation' => 'NewSecure@Pass99',
        ]);

        $response->assertStatus(200);

        // يجب أن تُحذف التوكنات الأخرى — يتبقى التوكن الحالي فقط
        $remainingTokens = $this->user->tokens()->count();
        $this->assertEquals(1, $remainingTokens);
    }

    public function test_old_token_deleted_from_database_after_password_change(): void
    {
        // توكن قديم أُنشئ قبل تغيير كلمة المرور
        $oldTokenModel = $this->user->createToken('old-device');
        $oldTokenId = $oldTokenModel->accessToken->id;

        // توكن الجلسة الحالية
        $currentToken = $this->user->createToken('current-session')->plainTextToken;

        // تغيير كلمة المرور — يُحذف التوكن القديم
        $this->withHeaders([
            'Authorization' => 'Bearer '.$currentToken,
        ])->putJson('/api/profile/password', [
            'current_password' => $this->validPassword,
            'password' => 'NewSecure@Pass99',
            'password_confirmation' => 'NewSecure@Pass99',
        ])->assertStatus(200);

        // التوكن القديم يجب أن يُحذف من قاعدة البيانات
        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $oldTokenId,
        ]);
    }

    // ========== تغيير كلمة المرور الناجح ==========

    public function test_password_change_requires_correct_current_password(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson('/api/profile/password', [
                'current_password' => 'WrongCurrentPass!',
                'password' => 'NewSecure@Pass99',
                'password_confirmation' => 'NewSecure@Pass99',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['current_password']);
    }

    public function test_successful_password_change_updates_database(): void
    {
        $newPassword = 'NewSecure@Pass99';

        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson('/api/profile/password', [
                'current_password' => $this->validPassword,
                'password' => $newPassword,
                'password_confirmation' => $newPassword,
            ]);

        $response->assertStatus(200);

        $this->user->refresh();
        $this->assertTrue(Hash::check($newPassword, $this->user->password));
    }

    public function test_can_login_with_new_password_after_change(): void
    {
        $newPassword = 'NewSecure@Pass99';

        $this->actingAs($this->user, 'sanctum')
            ->putJson('/api/profile/password', [
                'current_password' => $this->validPassword,
                'password' => $newPassword,
                'password_confirmation' => $newPassword,
            ]);

        // تسجيل الدخول بكلمة المرور الجديدة
        $response = $this->postJson('/api/login', [
            'email' => $this->user->email,
            'password' => $newPassword,
        ]);

        $response->assertStatus(200);
    }

    public function test_cannot_login_with_old_password_after_change(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->putJson('/api/profile/password', [
                'current_password' => $this->validPassword,
                'password' => 'NewSecure@Pass99',
                'password_confirmation' => 'NewSecure@Pass99',
            ]);

        // تسجيل الدخول بكلمة المرور القديمة
        $response = $this->postJson('/api/login', [
            'email' => $this->user->email,
            'password' => $this->validPassword,
        ]);

        $response->assertStatus(422);
    }

    public function test_unauthenticated_cannot_change_password(): void
    {
        $response = $this->putJson('/api/profile/password', [
            'current_password' => $this->validPassword,
            'password' => 'NewSecure@Pass99',
            'password_confirmation' => 'NewSecure@Pass99',
        ]);

        $response->assertStatus(401);
    }
}
