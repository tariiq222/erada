<?php

namespace Tests\Feature\Auth;

use App\Modules\Core\Authorization\Models\AuthorizationRoleAssignment;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\WelcomeNotification;
use App\Modules\HR\Models\Department;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Acceptance tests for the simplified registration flow
 * (docs/superpowers/plans/2026-07-06-simplified-registration.md).
 *
 * The endpoint creates a user in a single POST without OTP verification,
 * without admin approval, and without assigning any authorization role. Privilege
 * elevation happens later through the admin RoleController (out of scope
 * here).
 */
class SimpleRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_with_valid_data(): void
    {
        $response = $this->withHeader('X-Skip-Csrf', '1')
            ->postJson('/api/register', [
                'name' => 'New User',
                'email' => 'new@example.com',
                'password' => 'Str0ng!Passw0rd9',
                'password_confirmation' => 'Str0ng!Passw0rd9',
            ]);

        $response->assertCreated()
            ->assertCookie('auth_token')
            ->assertJsonStructure(['message', 'user' => ['id', 'name', 'email']]);

        $this->assertDatabaseHas('users', [
            'name' => 'New User',
            'email' => 'new@example.com',
        ]);
    }

    public function test_registered_user_is_active_and_approved_with_no_role(): void
    {
        $this->withHeader('X-Skip-Csrf', '1')
            ->postJson('/api/register', [
                'name' => 'Active User',
                'email' => 'active@example.com',
                'password' => 'Str0ng!Passw0rd9',
                'password_confirmation' => 'Str0ng!Passw0rd9',
            ])
            ->assertCreated();

        $user = User::where('email', 'active@example.com')->sole();
        $this->assertTrue($user->is_active, 'Registered user must be active.');
        $this->assertSame('approved', $user->registration_status, 'Registered user must be auto-approved.');
        $this->assertNotNull($user->email_verified_at, 'Registered user email must be verified.');

        // Registration must never grant a canonical role. Privilege elevation
        // happens later through the administrative assignment workflow.
        $this->assertFalse(
            AuthorizationRoleAssignment::query()->where('user_id', $user->id)->exists(),
            'No authorization role assignment may be created during registration.',
        );
    }

    public function test_registration_requires_name(): void
    {
        $this->withHeader('X-Skip-Csrf', '1')
            ->postJson('/api/register', [
                'email' => 'noname@example.com',
                'password' => 'Str0ng!Passw0rd9',
                'password_confirmation' => 'Str0ng!Passw0rd9',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    public function test_registration_requires_email(): void
    {
        $this->withHeader('X-Skip-Csrf', '1')
            ->postJson('/api/register', [
                'name' => 'No Email',
                'password' => 'Str0ng!Passw0rd9',
                'password_confirmation' => 'Str0ng!Passw0rd9',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_registration_requires_unique_email(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->withHeader('X-Skip-Csrf', '1')
            ->postJson('/api/register', [
                'name' => 'Duplicate',
                'email' => 'taken@example.com',
                'password' => 'Str0ng!Passw0rd9',
                'password_confirmation' => 'Str0ng!Passw0rd9',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_registration_requires_password(): void
    {
        $this->withHeader('X-Skip-Csrf', '1')
            ->postJson('/api/register', [
                'name' => 'No Password',
                'email' => 'nopass@example.com',
                'password_confirmation' => 'Str0ng!Passw0rd9',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    public function test_registration_requires_password_confirmation(): void
    {
        $this->withHeader('X-Skip-Csrf', '1')
            ->postJson('/api/register', [
                'name' => 'Mismatch',
                'email' => 'mismatch@example.com',
                'password' => 'Str0ng!Passw0rd9',
                'password_confirmation' => 'Different!Passw0rd9',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    public function test_optional_fields_accepted(): void
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);

        $response = $this->withHeader('X-Skip-Csrf', '1')
            ->postJson('/api/register', [
                'name' => 'Optional Fields',
                'email' => 'optional@example.com',
                'password' => 'Str0ng!Passw0rd9',
                'password_confirmation' => 'Str0ng!Passw0rd9',
                'department_id' => $dept->id,
                'job_title' => 'Senior Engineer',
                'phone' => '+966500000001',
                'organization_id' => $org->id,
            ]);

        $response->assertCreated();

        $user = User::where('email', 'optional@example.com')->sole();
        $this->assertSame($dept->id, $user->department_id);
        $this->assertSame('Senior Engineer', $user->job_title);
        $this->assertSame('+966500000001', $user->phone);
        $this->assertSame($org->id, $user->organization_id);
    }

    public function test_returned_cookie_token_authenticates_subsequent_requests(): void
    {
        $response = $this->withHeader('X-Skip-Csrf', '1')
            ->postJson('/api/register', [
                'name' => 'Self Login',
                'email' => 'selflogin@example.com',
                'password' => 'Str0ng!Passw0rd9',
                'password_confirmation' => 'Str0ng!Passw0rd9',
            ]);

        $token = null;
        foreach ($response->baseResponse->headers->getCookies() as $cookie) {
            if ($cookie->getName() === 'auth_token') {
                $token = $cookie->getValue();
            }
        }
        $this->assertNotEmpty($token, 'auth_token cookie must be set on registration.');

        $me = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/user');
        $me->assertOk()
            ->assertJsonPath('user.email', 'selflogin@example.com');
    }

    /**
     * @testWith ["+966500000001"]
     *           ["0501234567"]
     *           ["+1 (555) 123-4567"]
     *           ["+44 20 7946 0958"]
     */
    public function test_phone_format_accepts_real_world_inputs(string $phone): void
    {
        $this->withHeader('X-Skip-Csrf', '1')
            ->postJson('/api/register', [
                'name' => 'Phone OK',
                'email' => 'phone-ok-'.uniqid().'@example.com',
                'password' => 'Str0ng!Passw0rd9',
                'password_confirmation' => 'Str0ng!Passw0rd9',
                'phone' => $phone,
            ])
            ->assertCreated();
    }

    /**
     * @testWith ["abc"]
     *           ["123"]
     *           ["+12 34"]
     */
    public function test_phone_format_rejects_obvious_garbage(string $phone): void
    {
        $this->withHeader('X-Skip-Csrf', '1')
            ->postJson('/api/register', [
                'name' => 'Phone Bad',
                'email' => 'phone-bad-'.uniqid().'@example.com',
                'password' => 'Str0ng!Passw0rd9',
                'password_confirmation' => 'Str0ng!Passw0rd9',
                'phone' => $phone,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');
    }

    public function test_phone_is_still_optional(): void
    {
        // No phone field at all — must still 201.
        $this->withHeader('X-Skip-Csrf', '1')
            ->postJson('/api/register', [
                'name' => 'No Phone',
                'email' => 'no-phone-'.uniqid().'@example.com',
                'password' => 'Str0ng!Passw0rd9',
                'password_confirmation' => 'Str0ng!Passw0rd9',
            ])
            ->assertCreated();
    }

    public function test_welcome_notification_is_sent_to_the_new_user(): void
    {
        $email = 'welcome-'.uniqid().'@example.com';
        Notification::fake();

        $this->withHeader('X-Skip-Csrf', '1')
            ->postJson('/api/register', [
                'name' => 'Welcome User',
                'email' => $email,
                'password' => 'Str0ng!Passw0rd9',
                'password_confirmation' => 'Str0ng!Passw0rd9',
            ])
            ->assertCreated();

        $user = User::where('email', $email)->first();
        $this->assertNotNull($user, 'Registered user must exist.');

        Notification::assertSentTo(
            $user,
            WelcomeNotification::class
        );
    }

    public function test_welcome_notification_is_persisted_in_database(): void
    {
        $email = 'welcome-db-'.uniqid().'@example.com';

        $response = $this->withHeader('X-Skip-Csrf', '1')
            ->postJson('/api/register', [
                'name' => 'Welcome DB',
                'email' => $email,
                'password' => 'Str0ng!Passw0rd9',
                'password_confirmation' => 'Str0ng!Passw0rd9',
            ])
            ->assertCreated();

        $userId = $response->json('user.id');
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $userId,
            'type' => WelcomeNotification::class,
        ]);
    }
}
