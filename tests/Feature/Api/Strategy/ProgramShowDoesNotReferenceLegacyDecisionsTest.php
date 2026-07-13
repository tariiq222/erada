<?php

namespace Tests\Feature\Api\Strategy;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Meetings\Models\Recommendation;
use App\Modules\Strategy\Models\Portfolio;
use App\Modules\Strategy\Models\Program;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * Direction B (commit f98adef5): rulings previously loaded onto the
 * standalone `decisions` table through `Program->decisions()` now live on
 * the unified `recommendations` table with `kind=KIND_RULING`. The
 * `decisions` table was dropped in 2026_07_06_300003_drop_decisions_table.
 *
 * `ProgramController::show()` must eager-load `recommendations` (filtered
 * to rulings), NOT the dropped `decisions` relation. The old code returned
 * 500 with `Call to undefined relation [decisions] on Program`.
 */
class ProgramShowDoesNotReferenceLegacyDecisionsTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    protected User $user;

    protected Department $department;

    protected Organization $org;

    protected Portfolio $portfolio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->org = Organization::factory()->create();
        $this->department = Department::factory()->create([
            'organization_id' => $this->org->id,
        ]);

        $this->user = User::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        $this->grantCanonicalSuperAdmin($this->user);

        $this->portfolio = Portfolio::factory()->active()->create([
            'organization_id' => $this->org->id,
        ]);
    }

    /**
     * Regression: the eager-load no longer calls the dropped `decisions`
     * relation, so GET /api/strategy/programs/{id} returns 200 instead of
     * throwing BadMethodCallException.
     */
    public function test_show_returns_200_without_bad_method_call_on_legacy_decisions_relation(): void
    {
        $program = Program::factory()->create([
            'portfolio_id' => $this->portfolio->id,
            'organization_id' => $this->org->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/strategy/programs/{$program->id}");

        $response->assertStatus(200)
            ->assertJsonFragment(['id' => $program->id]);
    }

    /**
     * Eager-loaded `recommendations` returns ONLY rulings (KIND_RULING).
     * Action-item recommendations share the table but are filtered out by
     * the controller closure so the payload stays semantically equivalent
     * to the legacy "decisions" payload.
     */
    public function test_show_returns_only_ruling_recommendations_in_recommendations_key(): void
    {
        $program = Program::factory()->create([
            'portfolio_id' => $this->portfolio->id,
            'organization_id' => $this->org->id,
        ]);

        $meeting = Meeting::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $this->department->id,
        ]);

        $ruling = Recommendation::factory()->ruling()->create([
            'meeting_id' => $meeting->id,
            'decidable_type' => Program::class,
            'decidable_id' => $program->id,
            'organization_id' => $this->org->id,
            'title' => 'اعتماد خطة البرنامج',
            'type' => 'approval',
        ]);

        $actionItem = Recommendation::factory()->actionItem()->create([
            'meeting_id' => $meeting->id,
            'decidable_type' => Program::class,
            'decidable_id' => $program->id,
            'organization_id' => $this->org->id,
            'title' => 'متابعة تنفيذ',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/strategy/programs/{$program->id}");

        $response->assertStatus(200);

        $ids = collect($response->json('recommendations'))->pluck('id')->all();

        $this->assertSame([$ruling->id], $ids);
        $this->assertNotContains($actionItem->id, $ids);
    }

    /**
     * The other computed fields the controller attaches after the eager-load
     * (status_label, priority_label, budget_utilization,
     * progress_method_label) must still be present in the response so a
     * careless edit doesn't regress them.
     */
    public function test_show_preserves_computed_fields_and_other_relations(): void
    {
        $program = Program::factory()->create([
            'portfolio_id' => $this->portfolio->id,
            'organization_id' => $this->org->id,
            'progress_calculation_method' => Program::PROGRESS_WEIGHTED,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/strategy/programs/{$program->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'id',
                'portfolio',
                'projects',
                'blockers',
                'recommendations',
                'kpis',
                'reviews',
                'status_label',
                'priority_label',
                'budget_utilization',
                'progress_method_label',
            ]);
    }
}
