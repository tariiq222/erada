<?php

namespace Tests\Feature\Authorization;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * Phase 6 (ADR-UNIFIED-ROLE-ACCESS): per-capability reach cap on a role definition.
 * Reach only ever RESTRICTS an org-functional role — never expands it.
 */
class ReachCapTest extends TestCase
{
    use GrantsEngineCapability;
    use RefreshDatabase;

    private Organization $org;

    private Department $deptA;

    private Department $deptB;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $this->deptA = Department::factory()->create(['organization_id' => $this->org->id]);
        $this->deptB = Department::factory()->create(['organization_id' => $this->org->id]);
        $this->user = User::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $this->deptA->id,
            'is_active' => true,
        ]);
    }

    private function projectIn(Department $dept, ?int $createdBy = null): Project
    {
        $owner = $createdBy ?? User::factory()->create(['organization_id' => $this->org->id])->id;

        return Project::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $dept->id,
            'created_by' => $owner,
        ]);
    }

    public function test_reach_all_sees_projects_in_any_department(): void
    {
        $this->grantEngineCapability(
            $this->user, [Capability::PROJECTS_VIEW], 'organization', $this->org->id,
            reach: ['projects' => 'all']
        );

        $foreign = $this->projectIn($this->deptB);

        $this->assertTrue(AccessDecision::can($this->user, Capability::PROJECTS_VIEW, $foreign));
        $this->assertContains($this->org->id, AccessDecision::grantingScopes($this->user, Capability::PROJECTS_VIEW)['organization'] ?? []);
    }

    public function test_reach_department_restricts_to_users_own_department(): void
    {
        $this->grantEngineCapability(
            $this->user, [Capability::PROJECTS_VIEW], 'organization', $this->org->id,
            reach: ['projects' => 'department']
        );

        $inOwnDept = $this->projectIn($this->deptA);   // not owned by the user
        $inOtherDept = $this->projectIn($this->deptB);

        $this->assertTrue(AccessDecision::can($this->user, Capability::PROJECTS_VIEW, $inOwnDept));
        $this->assertFalse(AccessDecision::can($this->user, Capability::PROJECTS_VIEW, $inOtherDept));

        // List narrowing mirrors the per-target check: department bucket = user's dept,
        // NOT organization-wide.
        $scopes = AccessDecision::grantingScopes($this->user, Capability::PROJECTS_VIEW);
        $this->assertSame([$this->deptA->id], $scopes['department'] ?? []);
        $this->assertArrayNotHasKey('organization', $scopes);
    }

    public function test_reach_own_restricts_to_owned_records(): void
    {
        // DELETE is not covered by the owner floor, so reach=own is the deciding factor.
        $this->grantEngineCapability(
            $this->user, [Capability::PROJECTS_DELETE], 'organization', $this->org->id,
            reach: ['projects' => 'own']
        );

        $owned = $this->projectIn($this->deptA, createdBy: $this->user->id);
        $notOwned = $this->projectIn($this->deptA);

        $this->assertTrue(AccessDecision::can($this->user, Capability::PROJECTS_DELETE, $owned));
        $this->assertFalse(AccessDecision::can($this->user, Capability::PROJECTS_DELETE, $notOwned));

        // reach=own contributes no positional scope (owner branch handles the list).
        $this->assertArrayNotHasKey('organization', AccessDecision::grantingScopes($this->user, Capability::PROJECTS_DELETE));
    }

    public function test_no_reach_defaults_to_all_backward_compatible(): void
    {
        // A definition with no reach map keeps org-wide behavior.
        $this->grantEngineCapability(
            $this->user, [Capability::PROJECTS_VIEW], 'organization', $this->org->id
        );

        $foreign = $this->projectIn($this->deptB);
        $this->assertTrue(AccessDecision::can($this->user, Capability::PROJECTS_VIEW, $foreign));
    }
}
