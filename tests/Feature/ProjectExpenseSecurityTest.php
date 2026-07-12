<?php

namespace Tests\Feature;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectExpense;
use App\Modules\Tasks\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

class ProjectExpenseSecurityTest extends TestCase
{
    use GrantsEngineCapability;
    use RefreshDatabase;

    protected Organization $organization;

    protected Department $department;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->department = Department::factory()->create([
            'organization_id' => $this->organization->id,
            'level' => 4,
        ]);
        $this->project = Project::factory()->create([
            'organization_id' => $this->organization->id,
            'department_id' => $this->department->id,
            'budget' => 100000,
            'spent_amount' => 0,
        ]);
    }

    /**
     * مدير مشروع: يستطيع تعديل/حذف المصروفات غير المُقفلة، لكن لا يستطيع
     * تجاوز قفل is_finalized (لأن isAdmin()/isSuperAdmin() ترجعان false).
     *
     * بعد هجرة isAdmin() إلى AccessDecision::can(SETTINGS_MANAGE): الـ role Spatie
     * "admin" يحمل settings.manage ضمن permissions في scoped_role_definitions، فلو
     * أسندناه للمدير لصار isAdmin() = true. لذا نعطيه projects.edit عبر المحرّك
     * مباشرة، مع البقاء خارج isAdmin() (لا settings.manage ولا دور admin في Spatie).
     */
    protected function makeManager(): User
    {
        $user = User::factory()->create([
            'organization_id' => $this->organization->id,
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, Capability::PROJECTS_EDIT);

        return $user;
    }

    protected function makeAdmin(): User
    {
        $user = User::factory()->create([
            'organization_id' => $this->organization->id,
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        $this->grantCanonicalAdmin($user);
        $this->grantEngineCapability($user, Capability::SETTINGS_MANAGE);

        return $user;
    }

    protected function makeSuperAdmin(): User
    {
        $user = User::factory()->create([
            'organization_id' => $this->organization->id,
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        $this->grantCanonicalSuperAdmin($user);

        return $user;
    }

    protected function makeFinalizedExpense(): ProjectExpense
    {
        return ProjectExpense::create([
            'project_id' => $this->project->id,
            'created_by' => $this->makeSuperAdmin()->id,
            'title' => 'مصروف مُقفل',
            'amount' => 500.00,
            'category' => 'materials',
            'expense_date' => now()->format('Y-m-d'),
            'is_finalized' => true,
            'finalized_at' => now(),
        ]);
    }

    public function test_finalized_expense_cannot_be_updated_by_manager(): void
    {
        $manager = $this->makeManager();
        $expense = $this->makeFinalizedExpense();

        $response = $this->actingAs($manager, 'sanctum')
            ->putJson("/api/projects/{$this->project->id}/expenses/{$expense->id}", [
                'title' => 'مصروف معدّل',
                'amount' => 999.00,
            ]);

        $response->assertStatus(403);
        $this->assertDatabaseHas('project_expenses', [
            'id' => $expense->id,
            'title' => 'مصروف مُقفل',
            'amount' => 500.00,
        ]);
    }

    public function test_finalized_expense_cannot_be_deleted_by_manager(): void
    {
        $manager = $this->makeManager();
        $expense = $this->makeFinalizedExpense();

        $response = $this->actingAs($manager, 'sanctum')
            ->deleteJson("/api/projects/{$this->project->id}/expenses/{$expense->id}");

        $response->assertStatus(403);
        $this->assertDatabaseHas('project_expenses', [
            'id' => $expense->id,
            'deleted_at' => null,
        ]);
    }

    public function test_admin_can_update_finalized_expense(): void
    {
        $admin = $this->makeAdmin();
        $expense = $this->makeFinalizedExpense();

        $response = $this->actingAs($admin, 'sanctum')
            ->putJson("/api/projects/{$this->project->id}/expenses/{$expense->id}", [
                'title' => 'مصروف معدّل من الإدارة',
                'amount' => 750.00,
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('project_expenses', [
            'id' => $expense->id,
            'title' => 'مصروف معدّل من الإدارة',
            'amount' => 750.00,
        ]);
    }

    public function test_super_admin_can_delete_finalized_expense(): void
    {
        $superAdmin = $this->makeSuperAdmin();
        $expense = $this->makeFinalizedExpense();

        $response = $this->actingAs($superAdmin, 'sanctum')
            ->deleteJson("/api/projects/{$this->project->id}/expenses/{$expense->id}");

        $response->assertStatus(200);
        $this->assertSoftDeleted('project_expenses', [
            'id' => $expense->id,
        ]);
    }

    public function test_task_from_different_project_is_rejected(): void
    {
        $manager = $this->makeManager();
        $otherProject = Project::factory()->create([
            'organization_id' => $this->organization->id,
            'department_id' => $this->department->id,
        ]);
        $foreignTask = Task::factory()->create([
            'project_id' => $otherProject->id,
        ]);

        $response = $this->actingAs($manager, 'sanctum')
            ->postJson("/api/projects/{$this->project->id}/expenses", [
                'title' => 'مصروف بمهمة خارجية',
                'amount' => 100.00,
                'category' => 'materials',
                'expense_date' => now()->format('Y-m-d'),
                'task_id' => $foreignTask->id,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['task_id']);
    }

    public function test_task_from_same_project_accepted(): void
    {
        $manager = $this->makeManager();
        $ownTask = Task::factory()->create([
            'project_id' => $this->project->id,
        ]);

        $response = $this->actingAs($manager, 'sanctum')
            ->postJson("/api/projects/{$this->project->id}/expenses", [
                'title' => 'مصروف مرتبط بمهمة',
                'amount' => 250.00,
                'category' => 'materials',
                'expense_date' => now()->format('Y-m-d'),
                'task_id' => $ownTask->id,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('expense.task_id', $ownTask->id);
    }

    public function test_update_with_foreign_task_id_rejected(): void
    {
        $manager = $this->makeManager();
        $expense = ProjectExpense::create([
            'project_id' => $this->project->id,
            'created_by' => $manager->id,
            'title' => 'مصروف قابل للتعديل',
            'amount' => 100.00,
            'category' => 'materials',
            'expense_date' => now()->format('Y-m-d'),
        ]);
        $otherProject = Project::factory()->create([
            'organization_id' => $this->organization->id,
            'department_id' => $this->department->id,
        ]);
        $foreignTask = Task::factory()->create([
            'project_id' => $otherProject->id,
        ]);

        $response = $this->actingAs($manager, 'sanctum')
            ->putJson("/api/projects/{$this->project->id}/expenses/{$expense->id}", [
                'task_id' => $foreignTask->id,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['task_id']);
        $this->assertDatabaseHas('project_expenses', [
            'id' => $expense->id,
            'task_id' => null,
        ]);
    }

    public function test_concurrent_expense_creates_dont_lose_spent_amount(): void
    {
        $manager = $this->makeManager();
        $amounts = [100.00, 200.50, 75.25, 333.33];

        foreach ($amounts as $amount) {
            $response = $this->actingAs($manager, 'sanctum')
                ->postJson("/api/projects/{$this->project->id}/expenses", [
                    'title' => 'مصروف '.$amount,
                    'amount' => $amount,
                    'category' => 'materials',
                    'expense_date' => now()->format('Y-m-d'),
                ]);
            $response->assertStatus(201);
        }

        $expected = array_sum($amounts);
        $this->project->refresh();

        $this->assertEqualsWithDelta(
            $expected,
            (float) $this->project->spent_amount,
            0.01,
            'spent_amount must equal the sum of all expense amounts after multiple creates'
        );
    }
}
