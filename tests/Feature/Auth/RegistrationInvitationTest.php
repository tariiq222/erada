<?php

namespace Tests\Feature\Auth;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\OrganizationRegistrationInvitation;
use App\Modules\HR\Models\Department;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationInvitationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_rejects_client_selected_organization_without_an_invitation(): void
    {
        $organization = Organization::factory()->create();
        $department = Department::factory()->create(['organization_id' => $organization->id]);

        $this->withHeader('X-Skip-Csrf', '1')
            ->postJson('/api/register', [
                'name' => 'Intruder',
                'email' => 'intruder@example.test',
                'password' => 'Str0ng!Passw0rd9',
                'password_confirmation' => 'Str0ng!Passw0rd9',
                'organization_id' => $organization->id,
                'department_id' => $department->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['invite_token']);

        $this->assertDatabaseMissing('users', ['email' => 'intruder@example.test']);
    }

    public function test_registration_uses_the_organization_and_department_bound_to_a_valid_invitation(): void
    {
        $organization = Organization::factory()->create();
        $department = Department::factory()->create(['organization_id' => $organization->id]);
        [$invitation, $token] = OrganizationRegistrationInvitation::issue(
            organizationId: $organization->id,
            departmentId: $department->id,
            email: 'member@example.test',
        );

        $this->withHeader('X-Skip-Csrf', '1')
            ->postJson('/api/register', [
                'name' => 'Member',
                'email' => 'member@example.test',
                'password' => 'Str0ng!Passw0rd9',
                'password_confirmation' => 'Str0ng!Passw0rd9',
                'invite_token' => $token,
            ])
            ->assertCreated()
            ->assertCookie('auth_token');

        $this->assertDatabaseHas('users', [
            'email' => 'member@example.test',
            'organization_id' => $organization->id,
            'department_id' => $department->id,
        ]);
        $this->assertNotNull($invitation->fresh()->consumed_at);
    }
}
