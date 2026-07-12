<?php

namespace Tests\Feature\Core;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * Per-user "why does this user have access" summary (ADR-UNIFIED-ROLE-ACCESS, Phase 6).
 */
class AccessSummaryTest extends TestCase
{
    use GrantsEngineCapability;
    use RefreshDatabase;

    public function test_summary_lists_functional_and_scoped_roles_with_source(): void
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $superAdmin = User::factory()->create(['organization_id' => $org->id]);
        $this->grantCanonicalSuperAdmin($superAdmin);

        $target = User::factory()->create(['organization_id' => $org->id, 'department_id' => $dept->id]);
        $this->grantCanonicalViewer($target);
        // A manual org-scope scoped role.
        $this->grantEngineCapability($target, ['projects.view'], 'organization', $org->id);

        $data = $this->actingAs($superAdmin, 'sanctum')
            ->getJson("/api/authorization-role-assignments/user/{$target->id}/access-summary")
            ->assertStatus(200)
            ->json('data');

        $this->assertContains('viewer', $data['functional_roles']);
        $this->assertNotEmpty($data['scoped']);
        $this->assertSame('manual', $data['scoped'][0]['source']);
        $this->assertSame('المؤسسة', $data['scoped'][0]['scope_name']);
    }

    public function test_summary_forbidden_across_organizations(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $viewerA = User::factory()->create(['organization_id' => $orgA->id]);
        $this->grantCanonicalViewer($viewerA);
        $target = User::factory()->create(['organization_id' => $orgB->id]);

        $this->actingAs($viewerA, 'sanctum')
            ->getJson("/api/authorization-role-assignments/user/{$target->id}/access-summary")
            ->assertStatus(403);
    }
}
