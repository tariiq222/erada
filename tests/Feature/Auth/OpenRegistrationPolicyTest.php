<?php

namespace Tests\Feature\Auth;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OpenRegistrationPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_open_registration_joins_the_selected_organization_and_department_as_an_employee(): void
    {
        $organization = Organization::factory()->create();
        $department = Department::factory()->create(['organization_id' => $organization->id]);

        $this->withHeader('X-Skip-Csrf', '1')
            ->postJson('/api/register', [
                'name' => 'Department Employee',
                'email' => 'employee@example.test',
                'password' => 'Str0ng!Passw0rd9',
                'password_confirmation' => 'Str0ng!Passw0rd9',
                'organization_id' => $organization->id,
                'department_id' => $department->id,
            ])
            ->assertCreated()
            ->assertCookie('auth_token');

        $employee = User::where('email', 'employee@example.test')->sole();
        $this->assertSame($organization->id, $employee->organization_id);
        $this->assertSame($department->id, $employee->department_id);
        $this->assertFalse($employee->isAdmin());
    }
}
