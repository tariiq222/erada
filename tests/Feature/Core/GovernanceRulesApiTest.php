<?php

namespace Tests\Feature\Core;

use App\Modules\Core\Models\GovernanceRule;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * API for the unified "governing departments" screen (ADR-UNIFIED-ROLE-ACCESS, Phase 5).
 */
class GovernanceRulesApiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $superAdmin;

    private Department $dept;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $this->dept = Department::factory()->create(['organization_id' => $this->org->id]);
        $this->superAdmin = User::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
            'is_active' => true,
        ]);
        $this->superAdmin->assignRole('super_admin');
    }

    public function test_index_lists_the_three_resource_types_unset_by_default(): void
    {
        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson('/api/governance-rules');

        $response->assertStatus(200);
        $types = collect($response->json('data'))->pluck('governing_unit_id', 'resource_type');

        $this->assertEqualsCanonicalizing(['project', 'risk', 'ovr'], $types->keys()->all());
        $this->assertNull($types['project']);
        $this->assertNull($types['risk']);
        $this->assertNull($types['ovr']);
    }

    public function test_update_sets_and_index_reflects_governing_department(): void
    {
        $this->actingAs($this->superAdmin, 'sanctum')
            ->putJson('/api/governance-rules', [
                'resource_type' => 'risk',
                'governing_unit_id' => $this->dept->id,
            ])->assertStatus(200);

        // Persisted to the single source...
        $this->assertSame(
            $this->dept->id,
            GovernanceRule::governingUnitId($this->org->id, 'risk')
        );

        // ...and surfaced with the department name.
        $risk = collect($this->actingAs($this->superAdmin, 'sanctum')
            ->getJson('/api/governance-rules')->json('data'))
            ->firstWhere('resource_type', 'risk');

        $this->assertSame($this->dept->id, $risk['governing_unit_id']);
        $this->assertSame($this->dept->name, $risk['governing_unit_name']);
    }

    public function test_update_clears_governor_when_null(): void
    {
        GovernanceRule::setGoverningUnit($this->org->id, 'ovr', null, $this->dept->id, ['ovr.view_all']);

        $this->actingAs($this->superAdmin, 'sanctum')
            ->putJson('/api/governance-rules', [
                'resource_type' => 'ovr',
                'governing_unit_id' => null,
            ])->assertStatus(200);

        $this->assertNull(GovernanceRule::governingUnitId($this->org->id, 'ovr'));
    }

    public function test_cannot_set_a_department_from_another_org(): void
    {
        $otherOrg = Organization::factory()->create();
        $foreignDept = Department::factory()->create(['organization_id' => $otherOrg->id]);

        $this->actingAs($this->superAdmin, 'sanctum')
            ->putJson('/api/governance-rules', [
                'resource_type' => 'project',
                'governing_unit_id' => $foreignDept->id,
            ])->assertStatus(422);

        $this->assertNull(GovernanceRule::governingUnitId($this->org->id, 'project'));
    }

    public function test_non_super_admin_is_forbidden(): void
    {
        $user = User::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
            'is_active' => true,
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/governance-rules')
            ->assertStatus(403);
    }
}
