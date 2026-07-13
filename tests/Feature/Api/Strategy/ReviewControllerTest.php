<?php

namespace Tests\Feature\Api\Strategy;

use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use App\Modules\Strategy\Models\Review;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReviewControllerTest extends TestCase
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

    private function makeReview(array $overrides = []): Review
    {
        return Review::create(array_merge([
            'title' => 'مراجعة اختبارية',
            'reviewable_type' => Project::class,
            'reviewable_id' => $this->project->id,
            'type' => 'monthly',
            'pdca_phase' => 'check',
            'review_date' => now()->toDateString(),
            'period_start' => now()->subMonth()->toDateString(),
            'period_end' => now()->toDateString(),
            'overall_status' => 'on_track',
            'conducted_by' => $this->user->id,
        ], $overrides));
    }

    // ========== index ==========

    public function test_can_list_reviews(): void
    {
        $this->makeReview();
        $this->makeReview(['title' => 'مراجعة ثانية']);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/strategy/reviews');

        $response->assertStatus(200)
            ->assertJsonStructure(['data']);
    }

    public function test_can_filter_reviews_by_type(): void
    {
        $this->makeReview(['type' => 'monthly']);
        $this->makeReview(['type' => 'quarterly']);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/strategy/reviews?review_type=monthly');

        $response->assertStatus(200);
        $data = $response->json('data');
        foreach ($data as $review) {
            $this->assertEquals('monthly', $review['type']);
        }
    }

    public function test_can_filter_reviews_by_pdca_phase(): void
    {
        $this->makeReview(['pdca_phase' => 'plan']);
        $this->makeReview(['pdca_phase' => 'do']);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/strategy/reviews?pdca_phase=plan');

        $response->assertStatus(200);
        $data = $response->json('data');
        foreach ($data as $review) {
            $this->assertEquals('plan', $review['pdca_phase']);
        }
    }

    public function test_unauthenticated_cannot_list_reviews(): void
    {
        $response = $this->getJson('/api/strategy/reviews');

        $response->assertStatus(401);
    }

    // ========== store ==========

    public function test_can_create_review(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/strategy/reviews', [
                'title' => 'مراجعة جديدة',
                'reviewable_type' => 'project',
                'reviewable_id' => $this->project->id,
                'type' => 'monthly',
                'pdca_phase' => 'check',
                'review_date' => now()->toDateString(),
                'period_start' => now()->subMonth()->toDateString(),
                'period_end' => now()->toDateString(),
                'overall_status' => 'on_track',
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['message', 'review']);

        $this->assertDatabaseHas('reviews', ['title' => 'مراجعة جديدة']);
    }

    public function test_create_review_requires_title(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/strategy/reviews', [
                'reviewable_type' => 'project',
                'reviewable_id' => $this->project->id,
                'type' => 'monthly',
                'pdca_phase' => 'check',
                'review_date' => now()->toDateString(),
                'period_start' => now()->subMonth()->toDateString(),
                'period_end' => now()->toDateString(),
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['title']);
    }

    public function test_create_review_validates_type(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/strategy/reviews', [
                'title' => 'مراجعة',
                'reviewable_type' => 'project',
                'reviewable_id' => $this->project->id,
                'type' => 'invalid_type',
                'pdca_phase' => 'check',
                'review_date' => now()->toDateString(),
                'period_start' => now()->subMonth()->toDateString(),
                'period_end' => now()->toDateString(),
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['type']);
    }

    public function test_create_review_validates_pdca_phase(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/strategy/reviews', [
                'title' => 'مراجعة',
                'reviewable_type' => 'project',
                'reviewable_id' => $this->project->id,
                'type' => 'monthly',
                'pdca_phase' => 'invalid_phase',
                'review_date' => now()->toDateString(),
                'period_start' => now()->subMonth()->toDateString(),
                'period_end' => now()->toDateString(),
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['pdca_phase']);
    }

    public function test_create_review_validates_period_end_after_period_start(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/strategy/reviews', [
                'title' => 'مراجعة',
                'reviewable_type' => 'project',
                'reviewable_id' => $this->project->id,
                'type' => 'monthly',
                'pdca_phase' => 'check',
                'review_date' => now()->toDateString(),
                'period_start' => now()->toDateString(),
                'period_end' => now()->subDay()->toDateString(), // قبل البداية
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['period_end']);
    }

    // ========== show ==========

    public function test_can_view_review(): void
    {
        $review = $this->makeReview();

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/strategy/reviews/{$review->id}");

        $response->assertStatus(200);
    }

    // ========== update ==========

    public function test_can_update_review(): void
    {
        $review = $this->makeReview();

        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson("/api/strategy/reviews/{$review->id}", [
                'title' => 'مراجعة محدثة',
                'type' => 'quarterly',
                'pdca_phase' => 'act',
                'review_date' => now()->toDateString(),
                'period_start' => now()->subMonth()->toDateString(),
                'period_end' => now()->toDateString(),
                'overall_status' => 'at_risk',
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('reviews', [
            'id' => $review->id,
            'title' => 'مراجعة محدثة',
            'overall_status' => 'at_risk',
        ]);
    }

    // ========== destroy ==========

    public function test_can_delete_review(): void
    {
        $review = $this->makeReview();

        $response = $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/strategy/reviews/{$review->id}");

        $response->assertStatus(200);
        $this->assertSoftDeleted('reviews', ['id' => $review->id]);
    }

    // ========== all review types ==========

    public function test_all_review_types_are_valid(): void
    {
        $types = ['monthly', 'quarterly', 'annual', 'adhoc'];

        foreach ($types as $type) {
            $response = $this->actingAs($this->user, 'sanctum')
                ->postJson('/api/strategy/reviews', [
                    'title' => "مراجعة {$type}",
                    'reviewable_type' => 'project',
                    'reviewable_id' => $this->project->id,
                    'type' => $type,
                    'pdca_phase' => 'check',
                    'review_date' => now()->toDateString(),
                    'period_start' => now()->subMonth()->toDateString(),
                    'period_end' => now()->toDateString(),
                ]);

            $response->assertStatus(201, "Failed for review type: {$type}");
        }
    }
}
