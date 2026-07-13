<?php

namespace Tests\Feature\HR\Isolation;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * EmployeeIndexIsolationTest - Phase 2: org-A user cannot see org-B employees in the index.
 *
 * GET /api/hr/employees is gated by engine_capability:HR_VIEW. The
 * UserEmployeeScope applyToUsers filter limits the result set to the
 * actor's organization. These tests pin the filter behavior at the
 * HTTP boundary (not just the scope unit test).
 */
class EmployeeIndexIsolationTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    public function test_org_a_user_only_sees_org_a_employees(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);

        $actor = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
        ]);
        $this->grantEngineCapability($actor, Capability::HR_VIEW);

        // org A: 2 employees (plus the actor himself = 3 in org A total)
        User::factory()->count(2)->create(['organization_id' => $orgA->id]);
        // org B: 3 employees (must not appear)
        User::factory()->count(3)->create(['organization_id' => $orgB->id]);

        $response = $this->actingAs($actor, 'sanctum')
            ->getJson('/api/hr/employees');

        $response->assertStatus(200);
        $this->assertSame(3, $response->json('total')); // actor + 2
        foreach ($response->json('data') as $row) {
            $this->assertSame($orgA->id, $row['organization_id']);
        }
    }

    public function test_super_admin_sees_all_organizations(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);

        $superAdmin = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
        ]);
        $this->grantCanonicalSuperAdmin($superAdmin);

        User::factory()->count(2)->create(['organization_id' => $orgA->id]);
        User::factory()->count(3)->create(['organization_id' => $orgB->id]);

        $response = $this->actingAs($superAdmin, 'sanctum')
            ->getJson('/api/hr/employees');

        $response->assertStatus(200);
        $this->assertSame(6, $response->json('total'));
    }

    public function test_null_org_user_is_denied_at_form_request(): void
    {
        // ListEmployeesRequest::authorize() returns false for null-org actor
        // (fail-closed). The route never reaches the controller, so 403 is
        // emitted — this is the safer and earlier of the two possible 4xx
        // responses (alternative: 200 with empty data set). 403 wins.
        $dept = Department::factory()->create();
        $actor = User::factory()->create([
            'organization_id' => null,
            'department_id' => $dept->id,
        ]);
        $this->grantEngineCapability($actor, Capability::HR_VIEW);

        User::factory()->count(3)->create();

        $response = $this->actingAs($actor, 'sanctum')
            ->getJson('/api/hr/employees');

        $response->assertStatus(403);
    }
}
