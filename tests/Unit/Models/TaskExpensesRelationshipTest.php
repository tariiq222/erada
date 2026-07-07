<?php

namespace Tests\Unit\Models;

use App\Modules\Projects\Models\ProjectExpense;
use App\Modules\Tasks\Models\Task;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Tests\TestCase;

class TaskExpensesRelationshipTest extends TestCase
{
    public function test_task_has_expenses_relationship(): void
    {
        $task = new Task;
        $this->assertInstanceOf(HasMany::class, $task->expenses());
    }

    public function test_task_expenses_uses_correct_foreign_key(): void
    {
        $task = new Task;
        $relation = $task->expenses();
        $this->assertEquals('task_id', $relation->getForeignKeyName());
    }

    public function test_task_expenses_returns_project_expense_model(): void
    {
        $task = new Task;
        $relation = $task->expenses();
        $this->assertInstanceOf(ProjectExpense::class, $relation->getRelated());
    }
}
