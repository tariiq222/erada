<?php

namespace Tests\Feature\Security;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Shared\Models\ActivityLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * H-01: an org admin must not read another organization's activity-log rows.
 */
class ActivityLogIsolationTest extends TestCase
{
    use GrantsEngineCapability;
    use RefreshDatabase;

    private function userIn(Organization $org): User
    {
        $dept = Department::factory()->create(['organization_id' => $org->id, 'is_active' => true]);

        return User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
            'is_active' => true,
        ]);
    }

    public function test_org_admin_cannot_read_another_orgs_activity(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();

        $adminA = $this->userIn($orgA);
        $this->grantEngineCapability($adminA, Capability::AUDIT_VIEW);

        $userB = $this->userIn($orgB);
        $bRow = ActivityLog::create([
            'user_id' => $userB->id,
            'action' => 'password_changed',
            'description' => 'B org private action',
            'loggable_type' => User::class,
            'loggable_id' => $userB->id,
        ]);

        $this->actingAs($adminA, 'sanctum')->getJson('/api/activity-logs')
            ->assertOk()
            ->assertJsonMissing(['action' => 'password_changed']);

        $this->actingAs($adminA, 'sanctum')->getJson("/api/activity-logs/{$bRow->id}")
            ->assertNotFound();
    }
}
