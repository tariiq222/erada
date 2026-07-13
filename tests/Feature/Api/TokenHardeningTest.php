<?php

namespace Tests\Feature\Api;

use App\Modules\Core\Models\LoginAttempt;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\AuthSecurityService;
use App\Modules\Core\Services\TwoFactorService;
use App\Modules\Shared\Models\ActivityLog;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class TokenHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        Mail::fake();
        Notification::fake();
        RateLimiter::clear('token-hardening');
    }

    public function test_login_sets_cookie_without_returning_plain_text_token(): void
    {
        User::factory()->create([
            'email' => 'token-login@example.com',
            'password' => Hash::make('Password123!'),
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'token-login@example.com',
            'password' => 'Password123!',
        ]);

        $response->assertOk()
            ->assertCookie('auth_token')
            ->assertJsonMissingPath('token')
            ->assertJsonMissingPath('plainTextToken');

        $this->assertResponseHasNoBearerLikeToken($response->json());
        $this->assertTrue($this->getCookie($response, 'auth_token')->isHttpOnly());
    }

    public function test_confirmed_two_factor_login_returns_an_expiring_ip_bound_challenge_without_authentication(): void
    {
        $user = User::factory()->create([
            'email' => 'token-2fa@example.com',
            'password' => Hash::make('Password123!'),
            'is_active' => true,
        ]);

        $twoFactorService = app(TwoFactorService::class);
        $twoFactorService->enable($user);
        $twoFactorService->confirm($user, $twoFactorService->getCurrentOtp($user));
        $user->refresh();

        $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->postJson('/api/login', [
                'email' => 'token-2fa@example.com',
                'password' => 'Password123!',
            ]);

        $response->assertOk()
            ->assertCookieMissing('auth_token')
            ->assertJsonPath('two_factor_required', true)
            ->assertJsonStructure(['two_factor_required', 'user_id', 'pending_token', 'message'])
            ->assertJsonMissingPath('user')
            ->assertJsonMissingPath('token')
            ->assertJsonMissingPath('plainTextToken');

        $this->assertResponseHasNoBearerLikeToken($response->json());
        $this->assertCount(0, $user->fresh()->tokens);

        $pendingToken = $response->json('pending_token');
        $this->assertIsString($pendingToken);
        $this->assertGreaterThanOrEqual(40, strlen($pendingToken));
        $this->assertSame([
            'user_id' => $user->id,
            'ip' => '203.0.113.10',
        ], Cache::get('2fa_pending_'.$pendingToken));

        $this->travel(6)->minutes();
        $this->assertNull(Cache::get('2fa_pending_'.$pendingToken));
    }

    public function test_two_factor_challenge_verification_sets_an_http_only_cookie_without_returning_a_token(): void
    {
        $user = User::factory()->create([
            'email' => 'token-2fa-verify@example.com',
            'password' => Hash::make('Password123!'),
            'is_active' => true,
        ]);

        $twoFactorService = app(TwoFactorService::class);
        $twoFactorService->enable($user);
        $twoFactorService->confirm($user, $twoFactorService->getCurrentOtp($user));
        $user->refresh();

        $securityService = app(AuthSecurityService::class);
        $securityService->recordFailedAttempt($user->email, '203.0.113.20', 'FailedAgent/1.0');
        $securityService->recordFailedAttempt($user->email, '203.0.113.20', 'FailedAgent/1.0');

        $challenge = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.20'])
            ->postJson('/api/login', [
                'email' => 'token-2fa-verify@example.com',
                'password' => 'Password123!',
            ]);

        $response = $this->withHeader('User-Agent', 'ChallengeAgent/1.0')
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.20'])
            ->postJson('/api/2fa/verify', [
                'user_id' => $user->id,
                'code' => $twoFactorService->getCurrentOtp($user),
                'pending_token' => $challenge->json('pending_token'),
            ]);

        $response->assertOk()
            ->assertCookie('auth_token')
            ->assertJsonMissingPath('token')
            ->assertJsonMissingPath('plainTextToken');

        $this->assertResponseHasNoBearerLikeToken($response->json());
        $this->assertTrue($this->getCookie($response, 'auth_token')->isHttpOnly());
        $this->assertNull(Cache::get('2fa_pending_'.$challenge->json('pending_token')));

        $user->refresh();
        $this->assertSame(0, $user->failed_login_attempts);
        $this->assertNull($user->locked_until);
        $this->assertNull($user->last_failed_login_at);
        $this->assertNotNull($user->last_login_at);
        $this->assertSame('203.0.113.20', $user->last_login_ip);
        $this->assertSame(0, LoginAttempt::where('email', $user->email)->where('successful', false)->count());
        $this->assertDatabaseHas('login_attempts', [
            'email' => $user->email,
            'ip_address' => '203.0.113.20',
            'user_agent' => 'ChallengeAgent/1.0',
            'successful' => true,
        ]);
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $user->id,
            'action' => ActivityLog::ACTION_LOGIN,
            'loggable_type' => User::class,
            'loggable_id' => $user->id,
            'ip_address' => '203.0.113.20',
            'user_agent' => 'ChallengeAgent/1.0',
        ]);
    }

    public function test_deactivated_user_cannot_complete_an_existing_two_factor_challenge(): void
    {
        [$user, $twoFactorService, $pendingToken] = $this->createTwoFactorChallenge(
            'deactivated-2fa@example.com',
            '203.0.113.30'
        );
        $user->update(['is_active' => false]);
        Cache::put('2fa_tries_'.$pendingToken, 2, now()->addMinutes(5));

        $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.30'])
            ->postJson('/api/2fa/verify', [
                'user_id' => $user->id,
                'code' => $twoFactorService->getCurrentOtp($user),
                'pending_token' => $pendingToken,
            ]);

        $response->assertForbidden()
            ->assertCookieMissing('auth_token')
            ->assertJsonMissingPath('token');
        $this->assertCount(0, $user->fresh()->tokens);
        $this->assertNull(Cache::get('2fa_pending_'.$pendingToken));
        $this->assertNull(Cache::get('2fa_tries_'.$pendingToken));
        $this->assertDatabaseMissing('login_attempts', [
            'email' => $user->email,
            'successful' => true,
        ]);
        $this->assertDatabaseMissing('activity_logs', [
            'user_id' => $user->id,
            'action' => ActivityLog::ACTION_LOGIN,
        ]);
    }

    public function test_locked_user_cannot_complete_an_existing_two_factor_challenge(): void
    {
        [$user, $twoFactorService, $pendingToken] = $this->createTwoFactorChallenge(
            'locked-2fa@example.com',
            '203.0.113.40'
        );
        $user->forceFill([
            'failed_login_attempts' => AuthSecurityService::MAX_FAILED_ATTEMPTS,
            'locked_until' => now()->addMinutes(10),
        ])->save();
        Cache::put('2fa_tries_'.$pendingToken, 2, now()->addMinutes(5));

        $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.40'])
            ->postJson('/api/2fa/verify', [
                'user_id' => $user->id,
                'code' => $twoFactorService->getCurrentOtp($user),
                'pending_token' => $pendingToken,
            ]);

        $response->assertForbidden()
            ->assertCookieMissing('auth_token')
            ->assertJsonMissingPath('token');
        $this->assertCount(0, $user->fresh()->tokens);
        $this->assertNull(Cache::get('2fa_pending_'.$pendingToken));
        $this->assertNull(Cache::get('2fa_tries_'.$pendingToken));
        $this->assertDatabaseMissing('login_attempts', [
            'email' => $user->email,
            'successful' => true,
        ]);
        $this->assertDatabaseMissing('activity_logs', [
            'user_id' => $user->id,
            'action' => ActivityLog::ACTION_LOGIN,
        ]);
    }

    /**
     * @return array{User, TwoFactorService, string}
     */
    private function createTwoFactorChallenge(string $email, string $ip): array
    {
        $user = User::factory()->create([
            'email' => $email,
            'password' => Hash::make('Password123!'),
            'is_active' => true,
        ]);
        $twoFactorService = app(TwoFactorService::class);
        $twoFactorService->enable($user);
        $twoFactorService->confirm($user, $twoFactorService->getCurrentOtp($user));
        $user->refresh();

        $challenge = $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/api/login', [
                'email' => $email,
                'password' => 'Password123!',
            ]);

        return [$user, $twoFactorService, $challenge->json('pending_token')];
    }

    private function getCookie($response, string $name)
    {
        foreach ($response->baseResponse->headers->getCookies() as $cookie) {
            if ($cookie->getName() === $name) {
                return $cookie;
            }
        }

        $this->fail("Cookie [{$name}] was not set.");
    }

    private function assertResponseHasNoBearerLikeToken(array $payload): void
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $this->assertDoesNotMatchRegularExpression('/\d+\|[A-Za-z0-9]{40,}/', $json);
        $this->assertStringNotContainsString('plainTextToken', $json);
    }
}
