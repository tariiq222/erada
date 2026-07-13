<?php

namespace Tests\Feature\Security;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Strategy\Models\Portfolio;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * Task 2.2 — cross-org reference fields must be rejected/stripped.
 * L-07 (program department) exercises the new ScopesDepartmentsToOrganization
 * trait; M-09 covers the UserController self-edit department strip. M-03 reuses
 * the orgScopedUserRule already proven in TaskCrossOrgInjectionTest.
 */
class CrossOrgReferenceScopingTest extends TestCase
{
    use GrantsEngineCapability;
    use RefreshDatabase;

    public function test_program_create_rejects_cross_org_department(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();

        $deptA = Department::factory()->create(['organization_id' => $orgA->id, 'is_active' => true]);
        $deptB = Department::factory()->create(['organization_id' => $orgB->id, 'is_active' => true]);

        $actor = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($actor, Capability::STRATEGY_CREATE);

        $portfolio = Portfolio::factory()->create(['organization_id' => $orgA->id]);

        $this->actingAs($actor, 'sanctum')->postJson('/api/strategy/programs', [
            'name' => 'X-Org Program',
            'portfolio_id' => $portfolio->id,
            'department_id' => $deptB->id, // foreign org
        ])->assertStatus(422);
    }

    public function test_non_admin_self_edit_cannot_change_department(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $org = Organization::factory()->create();
        $homeDept = Department::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $otherDept = Department::factory()->create(['organization_id' => $org->id, 'is_active' => true]);

        $user = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $homeDept->id,
            'is_active' => true,
        ]);
        $this->assignCanonicalRole($user, 'member');

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/users/{$user->id}", [
                'name' => $user->name,
                'department_id' => $otherDept->id,
            ])->assertOk();

        $this->assertSame($homeDept->id, $user->fresh()->department_id);
    }
}
