<?php

namespace Tests\Feature\Auth;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\OrganizationRegistrationInvitation;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tenancy isolation tests for the simplified registration flow.
 *
 * A public registrant must not be able to attach themselves to a department
 * that belongs to a different organization than the one they declared.
 * Without DepartmentBelongsToOrganization, a request like
 *   { organization_id: 1, department_id: <id-of-dept-in-org-2> }
 * would succeed and store a row whose `user.organization_id` and
 * `user.department.organization_id` are out of sync — silently breaking
 * the tenancy invariant.
 */
class SimpleRegistrationIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_selected_department_cannot_replace_the_invitation_department(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $deptInA = Department::factory()->create(['organization_id' => $orgA->id]);
        $deptInB = Department::factory()->create(['organization_id' => $orgB->id]);
        [, $token] = OrganizationRegistrationInvitation::issue(
            organizationId: $orgA->id,
            departmentId: $deptInA->id,
            email: 'cross@example.com',
        );

        $response = $this->withHeader('X-Skip-Csrf', '1')
            ->postJson('/api/register', [
                'name' => 'Cross Org',
                'email' => 'cross@example.com',
                'password' => 'Str0ng!Passw0rd9',
                'password_confirmation' => 'Str0ng!Passw0rd9',
                'invite_token' => $token,
                'organization_id' => $orgA->id,
                'department_id' => $deptInB->id, // ← belongs to orgB
            ]);

        $response->assertCreated();
        $user = User::where('email', 'cross@example.com')->sole();
        $this->assertSame($orgA->id, $user->organization_id);
        $this->assertSame($deptInA->id, $user->department_id);
    }

    public function test_department_in_same_organization_is_bound_by_an_invitation(): void
    {
        $orgA = Organization::factory()->create();
        $deptInA = Department::factory()->create(['organization_id' => $orgA->id]);

        [, $token] = OrganizationRegistrationInvitation::issue(
            organizationId: $orgA->id,
            departmentId: $deptInA->id,
            email: 'same@example.com',
        );

        $this->withHeader('X-Skip-Csrf', '1')
            ->postJson('/api/register', [
                'name' => 'Same Org',
                'email' => 'same@example.com',
                'password' => 'Str0ng!Passw0rd9',
                'password_confirmation' => 'Str0ng!Passw0rd9',
                'invite_token' => $token,
            ])
            ->assertCreated();

        $u = User::where('email', 'same@example.com')->sole();
        $this->assertSame($orgA->id, $u->organization_id);
        $this->assertSame($deptInA->id, $u->department_id);
    }

    public function test_registration_without_an_invitation_is_rejected(): void
    {
        $this->withHeader('X-Skip-Csrf', '1')
            ->postJson('/api/register', [
                'name' => 'No Org',
                'email' => 'noorg@example.com',
                'password' => 'Str0ng!Passw0rd9',
                'password_confirmation' => 'Str0ng!Passw0rd9',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['invite_token']);
    }
}
