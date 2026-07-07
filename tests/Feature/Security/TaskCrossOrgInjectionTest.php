<?php

namespace Tests\Feature\Security;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Tasks\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H-03: task owner_id / assigned_to must be scoped to the actor's organization
 * on every write path (create + update/reassign).
 */
class TaskCrossOrgInjectionTest extends TestCase
{
    use RefreshDatabase;

    private function viewerIn(Organization $org): User
    {
        $dept = Department::factory()->create(['organization_id' => $org->id, 'is_active' => true]);

        return User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
            'is_active' => true,
        ]);
    }

    public function test_cannot_create_personal_task_owned_by_foreign_org_user(): void
    {
        $attacker = $this->viewerIn(Organization::factory()->create());
        $victim = $this->viewerIn(Organization::factory()->create());

        $this->actingAs($attacker, 'sanctum')->postJson('/api/unified-tasks', [
            'type' => 'personal',
            'title' => 'INJECTED',
            'owner_id' => $victim->id,
        ])->assertStatus(422);
    }

    public function test_cannot_reassign_personal_task_to_foreign_org_user(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->viewerIn($org);
        $foreign = $this->viewerIn(Organization::factory()->create());

        $task = Task::factory()->create([
            'type' => 'personal',
            'project_id' => null,
            'owner_id' => $owner->id,
            'created_by' => $owner->id,
            'assigned_to' => null,
        ]);

        $this->actingAs($owner, 'sanctum')
            ->putJson("/api/unified-tasks/{$task->id}", ['assigned_to' => $foreign->id])
            ->assertStatus(422);
    }
}
