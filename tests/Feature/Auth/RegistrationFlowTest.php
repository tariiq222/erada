<?php

namespace Tests\Feature\Auth;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationFlowTest extends TestCase
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

        // Auth cookie must be present and HttpOnly.
        $cookieFound = false;
        foreach ($response->baseResponse->headers->getCookies() as $cookie) {
            if ($cookie->getName() === 'auth_token') {
                $cookieFound = true;
                $this->assertTrue($cookie->isHttpOnly());
                $this->assertNotEmpty($cookie->getValue());
            }
        }
        $this->assertTrue($cookieFound, 'auth_token cookie should be set on successful registration.');
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

        $this->assertDatabaseMissing('users', ['email' => 'noname@example.com']);
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
        User::factory()->create([
            'email' => 'taken@example.com',
        ]);

        $this->withHeader('X-Skip-Csrf', '1')
            ->postJson('/api/register', [
                'name' => 'Duplicate',
                'email' => 'taken@example.com',
                'password' => 'Str0ng!Passw0rd9',
                'password_confirmation' => 'Str0ng!Passw0rd9',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        // Exactly one row for that email (the pre-existing user).
        $this->assertSame(1, User::where('email', 'taken@example.com')->count());
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

        $this->assertDatabaseMissing('users', ['email' => 'nopass@example.com']);
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

        $this->assertDatabaseMissing('users', ['email' => 'mismatch@example.com']);
    }

    public function test_registered_user_is_active_and_approved(): void
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

    public function test_optional_fields_can_be_null(): void
    {
        $response = $this->withHeader('X-Skip-Csrf', '1')
            ->postJson('/api/register', [
                'name' => 'No Optional',
                'email' => 'nooptional@example.com',
                'password' => 'Str0ng!Passw0rd9',
                'password_confirmation' => 'Str0ng!Passw0rd9',
                'department_id' => null,
                'job_title' => null,
                'phone' => null,
                'organization_id' => null,
            ]);

        $response->assertCreated();

        $user = User::where('email', 'nooptional@example.com')->sole();
        $this->assertNull($user->department_id);
        $this->assertNull($user->job_title);
        $this->assertNull($user->phone);
        $this->assertNull($user->organization_id);
    }
}
