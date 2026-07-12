<?php

namespace Tests\Feature;

use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectExpense;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ProjectExpenseSnapshotTest extends TestCase
{
    use DatabaseTransactions;

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

        $this->project = Project::factory()->create([
            'department_id' => $this->department->id,
        ]);
    }

    /**
     * اختبار أن original_amount يُسجل وقت الإنشاء
     */
    public function test_original_amount_set_on_create(): void
    {
        $expense = ProjectExpense::create([
            'project_id' => $this->project->id,
            'created_by' => $this->user->id,
            'title' => 'مصروف اختبار',
            'amount' => 1500.00,
            'category' => 'materials',
            'expense_date' => now()->format('Y-m-d'),
        ]);

        $this->assertDatabaseHas('project_expenses', [
            'id' => $expense->id,
            'amount' => 1500.00,
            'original_amount' => 1500.00,
        ]);
    }

    /**
     * اختبار أن original_amount لا يتغير عند تعديل amount
     */
    public function test_original_amount_does_not_change_on_update(): void
    {
        $expense = ProjectExpense::create([
            'project_id' => $this->project->id,
            'created_by' => $this->user->id,
            'title' => 'مصروف قابل للتعديل',
            'amount' => 2000.00,
            'category' => 'services',
            'expense_date' => now()->format('Y-m-d'),
        ]);

        $this->assertEquals(2000.00, $expense->original_amount);

        $expense->update(['amount' => 3000.00]);
        $expense->refresh();

        $this->assertEquals(3000.00, $expense->amount);
        $this->assertEquals(2000.00, $expense->original_amount);

        $this->assertDatabaseHas('project_expenses', [
            'id' => $expense->id,
            'amount' => 3000.00,
            'original_amount' => 2000.00,
        ]);
    }
}
