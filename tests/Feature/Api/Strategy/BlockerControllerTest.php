<?php

namespace Tests\Feature\Api\Strategy;

use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use App\Modules\Strategy\Models\Blocker;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BlockerControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Department $department;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->department = Department::factory()->create();
        $this->user = User::factory()->create([
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        $this->grantCanonicalSuperAdmin($this->user);
        $this->project = Project::factory()->create(['department_id' => $this->department->id]);
    }

    private function makeBlocker(array $overrides = []): Blocker
    {
        return Blocker::create(array_merge([
            'title' => 'تعثر اختباري',
            'blockable_type' => Project::class,
            'blockable_id' => $this->project->id,
            'reported_by' => $this->user->id,
            'status' => 'open',
            'severity' => 'medium',
            'identified_date' => now()->toDateString(),
        ], $overrides));
    }

    // ========== index ==========

    public function test_can_list_blockers(): void
    {
        $this->makeBlocker();
        $this->makeBlocker(['title' => 'تعثر ثانٍ']);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/strategy/blockers');

        $response->assertStatus(200)
            ->assertJsonStructure(['data']);
    }

    public function test_can_filter_blockers_by_status(): void
    {
        $this->makeBlocker(['status' => 'open']);
        $this->makeBlocker(['status' => 'resolved']);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/strategy/blockers?status=open');

        $response->assertStatus(200);
        $data = $response->json('data');
        foreach ($data as $blocker) {
            $this->assertEquals('open', $blocker['status']);
        }
    }

    public function test_can_filter_blockers_by_severity(): void
    {
        $this->makeBlocker(['severity' => 'critical']);
        $this->makeBlocker(['severity' => 'low']);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/strategy/blockers?severity=critical');

        $response->assertStatus(200);
        $data = $response->json('data');
        foreach ($data as $blocker) {
            $this->assertEquals('critical', $blocker['severity']);
        }
    }

    public function test_unauthenticated_cannot_list_blockers(): void
    {
        $response = $this->getJson('/api/strategy/blockers');

        $response->assertStatus(401);
    }

    // ========== store ==========

    public function test_can_create_blocker(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/strategy/blockers', [
                'title' => 'تعثر جديد',
                'description' => 'وصف التعثر',
                'blockable_type' => 'project',
                'blockable_id' => $this->project->id,
                'severity' => 'high',
                'identified_date' => now()->toDateString(),
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['message', 'blocker']);

        $this->assertDatabaseHas('blockers', ['title' => 'تعثر جديد', 'status' => 'open']);
    }

    public function test_create_blocker_requires_title(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/strategy/blockers', [
                'blockable_type' => 'project',
                'blockable_id' => $this->project->id,
                'identified_date' => now()->toDateString(),
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['title']);
    }

    public function test_create_blocker_validates_blockable_type(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/strategy/blockers', [
                'title' => 'تعثر',
                'blockable_type' => 'invalid_type',
                'blockable_id' => $this->project->id,
                'identified_date' => now()->toDateString(),
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['blockable_type']);
    }

    public function test_create_blocker_validates_nonexistent_blockable(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/strategy/blockers', [
                'title' => 'تعثر',
                'blockable_type' => 'project',
                'blockable_id' => 99999,
                'identified_date' => now()->toDateString(),
            ]);

        $response->assertStatus(422);
    }

    public function test_create_blocker_validates_severity(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/strategy/blockers', [
                'title' => 'تعثر',
                'blockable_type' => 'project',
                'blockable_id' => $this->project->id,
                'identified_date' => now()->toDateString(),
                'severity' => 'invalid',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['severity']);
    }

    // ========== show ==========

    public function test_can_view_blocker(): void
    {
        $blocker = $this->makeBlocker();

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/strategy/blockers/{$blocker->id}");

        $response->assertStatus(200);
    }

    // ========== update ==========

    public function test_can_update_blocker(): void
    {
        $blocker = $this->makeBlocker();

        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson("/api/strategy/blockers/{$blocker->id}", [
                'title' => 'عنوان محدث',
                'severity' => 'critical',
                'status' => 'in_progress',
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('blockers', [
            'id' => $blocker->id,
            'title' => 'عنوان محدث',
            'severity' => 'critical',
        ]);
    }

    // ========== destroy ==========

    public function test_can_delete_blocker(): void
    {
        $blocker = $this->makeBlocker();

        $response = $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/strategy/blockers/{$blocker->id}");

        $response->assertStatus(200);
        $this->assertSoftDeleted('blockers', ['id' => $blocker->id]);
    }

    // ========== resolve ==========

    public function test_can_resolve_blocker(): void
    {
        $blocker = $this->makeBlocker(['status' => 'open']);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/strategy/blockers/{$blocker->id}/resolve", [
                'resolution' => 'تم الحل بالتنسيق مع الفريق',
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('blockers', [
            'id' => $blocker->id,
            'status' => 'resolved',
        ]);
    }

    public function test_resolve_blocker_requires_resolution(): void
    {
        $blocker = $this->makeBlocker();

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/strategy/blockers/{$blocker->id}/resolve", []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['resolution']);
    }

    // ========== escalate ==========

    public function test_can_escalate_blocker(): void
    {
        $blocker = $this->makeBlocker(['status' => 'open']);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/strategy/blockers/{$blocker->id}/escalate");

        $response->assertStatus(200);
        $this->assertDatabaseHas('blockers', [
            'id' => $blocker->id,
            'status' => 'escalated',
        ]);
    }
}
