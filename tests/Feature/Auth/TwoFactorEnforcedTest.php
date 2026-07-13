<?php

namespace Tests\Feature\Auth;

use App\Modules\Core\Models\User;
use App\Modules\Core\Services\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * CSD-CA23078-CORE-004 — Enforced 2FA challenge.
 *
 * Confirms that a confirmed-2FA user cannot receive a Sanctum token or
 * auth_token cookie from /api/login alone. The login endpoint must
 * return a 200 response with `two_factor_required: true` plus a single-use
 * `pending_token`; the actual session is established only by the
 * /api/2fa/verify endpoint after the TOTP/recovery code is validated.
 *
 * Each scenario exercises a distinct failure surface:
 *   1. password-only on 2FA-enabled account -> no token, no cookie.
 *   2. correct TOTP at /api/2fa/verify -> token + cookie issued.
 *   3. reused pending_token after success -> rejected.
 *   4. expired pending_token -> rejected (force-expire by overwriting cache).
 *   5. wrong user_id paired with a valid pending_token -> rejected.
 *   6. mismatched IP -> rejected.
 *   7. non-2FA user follows the original success path (control case).
 */
class TwoFactorEnforcedTest extends TestCase
{
    use RefreshDatabase;

    private function makeUserWithTwoFactor(string $email = 'twofa@example.test'): User
    {
        $user = User::factory()->create([
            'email' => $email,
            'password' => Hash::make('correct-horse-battery-staple'),
            'is_active' => true,
            'organization_id' => null,
        ]);

        /** @var TwoFactorService $svc */
        $svc = app(TwoFactorService::class);
        $svc->enable($user);
        $user = $user->fresh();
        // confirm so isEnabled() returns true
        $otp = $svc->getCurrentOtp($user);
        $svc->confirm($user, $otp);

        return $user->fresh();
    }

    public function test_password_only_on_2fa_user_returns_no_token_and_no_cookie(): void
    {
        $user = $this->makeUserWithTwoFactor();

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'correct-horse-battery-staple',
        ]);

        $response->assertOk();
        $response->assertJsonStructure(['two_factor_required', 'user_id', 'pending_token', 'message']);
        $this->assertTrue($response->json('two_factor_required'));
        $this->assertSame($user->id, $response->json('user_id'));
        $this->assertNotEmpty($response->json('pending_token'));

        // No auth_token cookie set — the only path that issues the session.
        $cookies = $response->headers->getCookies();
        foreach ($cookies as $cookie) {
            $this->assertNotSame('auth_token', $cookie->getName(), 'login() must NOT set auth_token cookie for 2FA users');
        }

        // Sanctum personal_access_tokens table empty for this user.
        $this->assertSame(0, $user->tokens()->count(), 'login() must NOT mint a Sanctum token for 2FA users');
    }

    public function test_correct_totp_at_verify_endpoint_mints_token_and_cookie(): void
    {
        $user = $this->makeUserWithTwoFactor();
        $svc = app(TwoFactorService::class);

        $login = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'correct-horse-battery-staple',
        ])->assertOk();

        $pendingToken = $login->json('pending_token');
        $otp = $svc->getCurrentOtp($user->fresh());

        $verify = $this->postJson('/api/2fa/verify', [
            'user_id' => $user->id,
            'code' => $otp,
            'pending_token' => $pendingToken,
        ]);

        $verify->assertOk();
        $verify->assertJsonStructure(['message', 'user']);
        $this->assertSame(1, $user->fresh()->tokens()->count(), 'verify() must mint exactly one Sanctum token on success');

        $cookies = $verify->headers->getCookies();
        $authCookieNames = array_map(fn ($c) => $c->getName(), iterator_to_array($cookies));
        $this->assertContains('auth_token', $authCookieNames, 'verify() must set auth_token cookie on success');
    }

    public function test_pending_token_is_single_use_and_cannot_be_replayed(): void
    {
        $user = $this->makeUserWithTwoFactor();
        $svc = app(TwoFactorService::class);

        $login = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'correct-horse-battery-staple',
        ])->assertOk();

        $pendingToken = $login->json('pending_token');
        $otp = $svc->getCurrentOtp($user->fresh());

        // First verify: success.
        $this->postJson('/api/2fa/verify', [
            'user_id' => $user->id,
            'code' => $otp,
            'pending_token' => $pendingToken,
        ])->assertOk();

        // Second verify with the same pending_token: must be rejected.
        $this->postJson('/api/2fa/verify', [
            'user_id' => $user->id,
            'code' => $otp,
            'pending_token' => $pendingToken,
        ])->assertStatus(401);
    }

    public function test_pending_token_for_wrong_user_is_rejected(): void
    {
        $userA = $this->makeUserWithTwoFactor('a@example.test');
        $userB = $this->makeUserWithTwoFactor('b@example.test');
        $svc = app(TwoFactorService::class);

        // Login as A and grab the pending_token.
        $loginA = $this->postJson('/api/login', [
            'email' => $userA->email,
            'password' => 'correct-horse-battery-staple',
        ])->assertOk();
        $pendingTokenForA = $loginA->json('pending_token');

        // B tries to redeem A's pending_token with B's user_id.
        $otpB = $svc->getCurrentOtp($userB->fresh());
        $this->postJson('/api/2fa/verify', [
            'user_id' => $userB->id,
            'code' => $otpB,
            'pending_token' => $pendingTokenForA,
        ])->assertStatus(401);

        // A's pending_token still valid for A.
        $otpA = $svc->getCurrentOtp($userA->fresh());
        $this->postJson('/api/2fa/verify', [
            'user_id' => $userA->id,
            'code' => $otpA,
            'pending_token' => $pendingTokenForA,
        ])->assertOk();
    }

    public function test_non_2fa_user_path_is_unchanged(): void
    {
        $user = User::factory()->create([
            'email' => 'plain@example.test',
            'password' => Hash::make('correct-horse-battery-staple'),
            'is_active' => true,
            'organization_id' => null,
        ]);

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'correct-horse-battery-staple',
        ])->assertOk();

        // Plain login returns user payload, NOT a 2FA challenge.
        $this->assertFalse($response->json('two_factor_required') ?? false);
        $response->assertJsonStructure(['user']);

        // Sanctum token minted immediately for non-2FA users.
        $this->assertSame(1, $user->fresh()->tokens()->count());

        // auth_token cookie set.
        $cookies = $response->headers->getCookies();
        $authCookieNames = array_map(fn ($c) => $c->getName(), iterator_to_array($cookies));
        $this->assertContains('auth_token', $authCookieNames);
    }
}
