<?php

namespace Tests\Feature\Api;

use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectExpense;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ProjectExpenseControllerTest extends TestCase
{
    use DatabaseTransactions;

    protected User $user;

    protected Department $department;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeaders(['X-Skip-Csrf' => '1']);

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->department = Department::factory()->create();
        $this->user = User::factory()->create([
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        $this->grantCanonicalSuperAdmin($this->user);

        $this->project = Project::factory()->create([
            'department_id' => $this->department->id,
            'budget' => 10000.00,
        ]);
    }

    // ========== index ==========

    public function test_can_list_expenses(): void
    {
        ProjectExpense::factory()->count(3)->create([
            'project_id' => $this->project->id,
            'created_by' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/projects/{$this->project->id}/expenses");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'expenses',
                'stats' => ['total_expenses', 'budget', 'spent_amount', 'remaining', 'percentage_used', 'by_category'],
                'categories',
            ])
            ->assertJsonCount(3, 'expenses');
    }

    public function test_index_filters_by_category(): void
    {
        ProjectExpense::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->user->id,
            'category' => 'materials',
        ]);
        ProjectExpense::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->user->id,
            'category' => 'services',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/projects/{$this->project->id}/expenses?category=materials");

        $response->assertStatus(200)
            ->assertJsonCount(1, 'expenses');
    }

    public function test_unauthenticated_cannot_list_expenses(): void
    {
        $this->getJson("/api/projects/{$this->project->id}/expenses")
            ->assertStatus(401);
    }

    // ========== store ==========

    public function test_can_create_expense(): void
    {
        $data = [
            'title' => 'Office Supplies',
            'amount' => 500.00,
            'category' => 'materials',
            'expense_date' => now()->format('Y-m-d'),
            'description' => 'Paper and pens',
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/projects/{$this->project->id}/expenses", $data);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'expense' => ['id', 'title', 'amount', 'category'],
                'new_spent_amount',
            ]);

        $this->assertDatabaseHas('project_expenses', [
            'project_id' => $this->project->id,
            'title' => 'Office Supplies',
            'created_by' => $this->user->id,
        ]);
    }

    public function test_create_expense_updates_project_spent_amount(): void
    {
        $data = [
            'title' => 'Test Expense',
            'amount' => 1500.00,
            'category' => 'services',
            'expense_date' => now()->format('Y-m-d'),
        ];

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/projects/{$this->project->id}/expenses", $data);

        $this->assertDatabaseHas('projects', [
            'id' => $this->project->id,
            'spent_amount' => 1500.00,
        ]);
    }

    public function test_create_expense_returns_budget_warning_at_80_percent(): void
    {
        // Project budget is 10000, spend 8500 (85%)
        $data = [
            'title' => 'Big Expense',
            'amount' => 8500.00,
            'category' => 'human_resources',
            'expense_date' => now()->format('Y-m-d'),
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/projects/{$this->project->id}/expenses", $data);

        $response->assertStatus(201);
        $warning = $response->json('warning');
        $this->assertNotNull($warning, 'Expected budget warning to be present');
        $this->assertIsString($warning, 'Expected warning to be a string');
        $this->assertNotEmpty($warning, 'Expected warning to be non-empty');
    }

    public function test_create_expense_validation_requires_title(): void
    {
        $data = [
            'amount' => 100.00,
            'category' => 'materials',
            'expense_date' => now()->format('Y-m-d'),
        ];

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/projects/{$this->project->id}/expenses", $data)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['title']);
    }

    public function test_create_expense_validation_rejects_invalid_category(): void
    {
        $data = [
            'title' => 'Test',
            'amount' => 100.00,
            'category' => 'invalid_category',
            'expense_date' => now()->format('Y-m-d'),
        ];

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/projects/{$this->project->id}/expenses", $data)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['category']);
    }

    // ========== show ==========

    public function test_can_show_expense(): void
    {
        $expense = ProjectExpense::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/projects/{$this->project->id}/expenses/{$expense->id}");

        $response->assertStatus(200)
            ->assertJsonStructure(['expense' => ['id', 'title', 'amount']]);
    }

    public function test_show_returns_404_for_wrong_project(): void
    {
        $otherProject = Project::factory()->create(['department_id' => $this->department->id]);
        $expense = ProjectExpense::factory()->create([
            'project_id' => $otherProject->id,
            'created_by' => $this->user->id,
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/projects/{$this->project->id}/expenses/{$expense->id}")
            ->assertStatus(404);
    }

    // ========== update ==========

    public function test_can_update_expense(): void
    {
        $expense = ProjectExpense::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->user->id,
            'amount' => 100.00,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson("/api/projects/{$this->project->id}/expenses/{$expense->id}", [
                'title' => 'Updated Title',
                'amount' => 200.00,
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('project_expenses', [
            'id' => $expense->id,
            'title' => 'Updated Title',
            'amount' => 200.00,
        ]);
    }

    // ========== destroy ==========

    public function test_can_delete_expense(): void
    {
        $expense = ProjectExpense::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->user->id,
            'amount' => 300.00,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/projects/{$this->project->id}/expenses/{$expense->id}");

        $response->assertStatus(200);
        $this->assertSoftDeleted('project_expenses', ['id' => $expense->id]);
    }

    public function test_delete_expense_updates_spent_amount(): void
    {
        $expense = ProjectExpense::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->user->id,
            'amount' => 500.00,
        ]);

        // Force spent_amount to match
        $this->project->update(['spent_amount' => 500.00]);

        $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/projects/{$this->project->id}/expenses/{$expense->id}");

        $this->assertDatabaseHas('projects', [
            'id' => $this->project->id,
            'spent_amount' => 0,
        ]);
    }

    // ========== summary ==========

    public function test_can_get_expense_summary(): void
    {
        ProjectExpense::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->user->id,
            'category' => 'materials',
            'amount' => 200.00,
            'expense_date' => now()->format('Y-m-d'),
        ]);
        ProjectExpense::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->user->id,
            'category' => 'services',
            'amount' => 300.00,
            'expense_date' => now()->format('Y-m-d'),
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/projects/{$this->project->id}/expenses/summary");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'budget',
                'spent_amount',
                'remaining',
                'percentage_used',
                'by_category',
                'monthly',
                'total_expenses_count',
            ]);

        $this->assertEquals(2, $response->json('total_expenses_count'));
    }
}
