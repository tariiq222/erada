<?php

namespace Tests\Feature\Api;

use App\Modules\Core\Models\User;
use App\Modules\Core\Services\TwoFactorService;
use App\Modules\HR\Models\Department;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TwoFactorControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Department $department;

    protected string $userPassword = 'Password123!';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->department = Department::factory()->create();

        $this->user = User::factory()->create([
            'department_id' => $this->department->id,
            'is_active' => true,
            'password' => bcrypt($this->userPassword),
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
        ]);
        $this->user->assignRole('super_admin');
    }

    // ========================================
    // اختبارات الحصول على حالة 2FA
    // ========================================

    public function test_can_get_2fa_status_when_disabled(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/2fa/status');

        $response->assertStatus(200)
            ->assertJson([
                'enabled' => false,
                'confirmed' => false,
            ]);
    }

    public function test_can_get_2fa_status_when_enabled(): void
    {
        $this->user->update([
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
            'two_factor_confirmed_at' => now(),
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/2fa/status');

        $response->assertStatus(200)
            ->assertJson([
                'enabled' => true,
                'confirmed' => true,
            ]);
    }

    // ========================================
    // اختبارات تفعيل 2FA
    // ========================================

    public function test_can_enable_2fa(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/2fa/enable', [
                'password' => $this->userPassword,
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'secret',
                'qr_code_url',
                'recovery_codes',
            ]);

        $this->user->refresh();
        $this->assertNotNull($this->user->two_factor_secret);
        $this->assertNull($this->user->two_factor_confirmed_at);
    }

    public function test_cannot_enable_2fa_with_wrong_password(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/2fa/enable', [
                'password' => 'wrong-password',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_cannot_enable_2fa_without_password(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/2fa/enable');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_cannot_enable_2fa_when_already_enabled(): void
    {
        $this->user->update([
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
            'two_factor_confirmed_at' => now(),
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/2fa/enable', [
                'password' => $this->userPassword,
            ]);

        $response->assertStatus(400);
    }

    // ========================================
    // اختبارات تأكيد 2FA
    // ========================================

    public function test_can_confirm_2fa_with_valid_code(): void
    {
        $twoFactorService = app(TwoFactorService::class);

        // تفعيل 2FA أولاً
        $result = $twoFactorService->enable($this->user);
        $secret = $result['secret'];

        // الحصول على الكود الصحيح
        $validCode = $twoFactorService->getCurrentOtp($this->user);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/2fa/confirm', [
                'code' => $validCode,
            ]);

        $response->assertStatus(200);

        $this->user->refresh();
        $this->assertNotNull($this->user->two_factor_confirmed_at);
    }

    public function test_cannot_confirm_2fa_with_invalid_code(): void
    {
        $this->user->update([
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/2fa/confirm', [
                'code' => '000000',
            ]);

        $response->assertStatus(422);
    }

    public function test_cannot_confirm_2fa_without_enabling_first(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/2fa/confirm', [
                'code' => '123456',
            ]);

        $response->assertStatus(400);
    }

    // ========================================
    // اختبارات تعطيل 2FA
    // ========================================

    public function test_can_disable_2fa(): void
    {
        $twoFactorService = app(TwoFactorService::class);

        // تفعيل وتأكيد 2FA
        $twoFactorService->enable($this->user);
        $validCode = $twoFactorService->getCurrentOtp($this->user);
        $twoFactorService->confirm($this->user, $validCode);

        // الحصول على كود جديد للتعطيل
        $disableCode = $twoFactorService->getCurrentOtp($this->user);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/2fa/disable', [
                'password' => $this->userPassword,
                'code' => $disableCode,
            ]);

        $response->assertStatus(200);

        $this->user->refresh();
        $this->assertNull($this->user->two_factor_secret);
        $this->assertNull($this->user->two_factor_confirmed_at);
    }

    public function test_cannot_disable_2fa_with_invalid_code(): void
    {
        $this->user->update([
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
            'two_factor_confirmed_at' => now(),
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/2fa/disable', [
                'password' => $this->userPassword,
                'code' => '000000',
            ]);

        $response->assertStatus(422);
    }

    public function test_cannot_disable_2fa_with_wrong_password(): void
    {
        $this->user->update([
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
            'two_factor_confirmed_at' => now(),
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/2fa/disable', [
                'password' => 'wrong-password',
                'code' => '123456',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    // ========================================
    // اختبارات أكواد الاسترداد
    // ========================================

    public function test_can_regenerate_recovery_codes(): void
    {
        $twoFactorService = app(TwoFactorService::class);

        // تفعيل وتأكيد 2FA
        $twoFactorService->enable($this->user);
        $validCode = $twoFactorService->getCurrentOtp($this->user);
        $twoFactorService->confirm($this->user, $validCode);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/2fa/recovery-codes', [
                'password' => $this->userPassword,
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['recovery_codes']);
    }

    public function test_cannot_regenerate_recovery_codes_without_2fa_enabled(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/2fa/recovery-codes', [
                'password' => $this->userPassword,
            ]);

        $response->assertStatus(400);
    }

    // ========================================
    // اختبارات الأمان
    // ========================================

    public function test_unauthenticated_cannot_access_2fa_endpoints(): void
    {
        $this->getJson('/api/2fa/status')->assertStatus(401);
        $this->postJson('/api/2fa/enable', ['password' => 'test'])->assertStatus(401);
        $this->postJson('/api/2fa/confirm', ['code' => '123456'])->assertStatus(401);
        $this->postJson('/api/2fa/disable', ['password' => 'test', 'code' => '123456'])->assertStatus(401);
    }
}
