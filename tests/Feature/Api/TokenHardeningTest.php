<?php

namespace Tests\Feature\Api;

use App\Modules\Core\Models\User;
use App\Modules\Core\Services\TwoFactorService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_two_factor_verify_sets_cookie_without_returning_plain_text_token(): void
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

        // 2FA was removed from the login flow; a user with 2FA enabled now logs in
        // directly via /api/login. The auth cookie is set on the login response
        // itself — no second 2fa/verify call and no pending_token in the payload.
        $response = $this->postJson('/api/login', [
            'email' => 'token-2fa@example.com',
            'password' => 'Password123!',
        ]);

        $response->assertOk()
            ->assertCookie('auth_token')
            ->assertJsonMissingPath('token')
            ->assertJsonMissingPath('plainTextToken')
            ->assertJsonMissingPath('requires_2fa')
            ->assertJsonMissingPath('pending_token');

        $this->assertResponseHasNoBearerLikeToken($response->json());
        $this->assertTrue($this->getCookie($response, 'auth_token')->isHttpOnly());
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
