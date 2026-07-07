<?php

namespace Tests\Feature\Auth;

use App\Modules\Core\Enums\OtpPurpose;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Support\PasswordResetControllerNeutral;
use Tests\TestCase;

class PasswordResetFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_user_resets_password_and_tokens_revoked(): void
    {
        $user = User::factory()->create([
            'email' => 'active@org.test',
            'is_active' => true,
            'registration_status' => 'active',
            'password' => Hash::make('OldPass!123'),
        ]);
        $user->createToken('existing');

        $this->withHeader('X-Skip-Csrf', '1')
            ->postJson('/api/password/forgot', ['email' => 'active@org.test'])
            ->assertOk()
            ->assertJson(['message' => PasswordResetControllerNeutral::FORGOT]);

        $code = app(OtpService::class)->issue('active@org.test', OtpPurpose::PasswordReset);

        $this->withHeader('X-Skip-Csrf', '1')->postJson('/api/password/reset', [
            'email' => 'active@org.test',
            'code' => $code,
            'password' => 'New!Passw0rd9',
            'password_confirmation' => 'New!Passw0rd9',
        ])->assertOk();

        $user->refresh();
        $this->assertTrue(Hash::check('New!Passw0rd9', $user->password));
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_pending_user_gets_no_otp(): void
    {
        User::factory()->create([
            'email' => 'pending@org.test',
            'is_active' => false,
            'registration_status' => 'pending_approval',
        ]);

        $this->withHeader('X-Skip-Csrf', '1')
            ->postJson('/api/password/forgot', ['email' => 'pending@org.test'])
            ->assertOk()
            ->assertJson(['message' => PasswordResetControllerNeutral::FORGOT]);

        $this->assertDatabaseCount('email_otps', 0);
    }

    public function test_unknown_email_returns_neutral_and_no_otp(): void
    {
        $this->withHeader('X-Skip-Csrf', '1')
            ->postJson('/api/password/forgot', ['email' => 'nobody@org.test'])
            ->assertOk()
            ->assertJson(['message' => PasswordResetControllerNeutral::FORGOT]);

        $this->assertDatabaseCount('email_otps', 0);
    }

    public function test_rejected_user_gets_no_otp(): void
    {
        User::factory()->create([
            'email' => 'rej@org.test',
            'is_active' => false,
            'registration_status' => 'rejected',
        ]);

        $this->withHeader('X-Skip-Csrf', '1')
            ->postJson('/api/password/forgot', ['email' => 'rej@org.test'])
            ->assertOk();

        $this->assertDatabaseCount('email_otps', 0);
    }

    public function test_wrong_code_fails_with_neutral_message(): void
    {
        User::factory()->create([
            'email' => 'w@org.test',
            'is_active' => true,
            'registration_status' => 'active',
        ]);
        app(OtpService::class)->issue('w@org.test', OtpPurpose::PasswordReset);

        $this->withHeader('X-Skip-Csrf', '1')->postJson('/api/password/reset', [
            'email' => 'w@org.test',
            'code' => '000000',
            'password' => 'New!Passw0rd9',
            'password_confirmation' => 'New!Passw0rd9',
        ])->assertStatus(422);
    }
}
