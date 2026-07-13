<?php

namespace Tests\Feature;

use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ValidationRulesTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Department $department;

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
    }

    /**
     * اختبار أن due_date يجب أن يكون بعد أو يساوي start_date في المهام
     */
    public function test_task_due_date_must_be_after_or_equal_start_date(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/unified-tasks', [
                'title' => 'مهمة اختبار',
                'start_date' => now()->addDays(5)->format('Y-m-d'),
                'due_date' => now()->addDays(2)->format('Y-m-d'),
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['due_date']);
    }

    /**
     * اختبار أن due_date يمكن أن يساوي start_date
     */
    public function test_task_due_date_can_equal_start_date(): void
    {
        $date = now()->format('Y-m-d');

        $response = $this->actingAs($this->user)
            ->postJson('/api/unified-tasks', [
                'title' => 'مهمة بنفس التاريخ',
                'start_date' => $date,
                'due_date' => $date,
            ]);

        $response->assertStatus(201);
    }

    /**
     * اختبار أن due_date يجب أن يكون بعد أو يساوي start_date في المشروع (milestones)
     */
    public function test_project_milestone_due_date_must_be_after_or_equal_start_date(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/projects', [
                'name' => 'مشروع اختبار تواريخ',
                'priority' => 'high',
                'department_id' => $this->department->id,
                'start_date' => now()->format('Y-m-d'),
                'end_date' => now()->addMonth()->format('Y-m-d'),
                'milestones' => [
                    [
                        'name' => 'مرحلة 1',
                        'start_date' => now()->addDays(10)->format('Y-m-d'),
                        'due_date' => now()->addDays(5)->format('Y-m-d'),
                    ],
                ],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['milestones.0.due_date']);
    }

    /**
     * اختبار أن due_date يجب أن يكون بعد أو يساوي start_date في المهام داخل المشروع
     */
    public function test_project_task_due_date_must_be_after_or_equal_start_date(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/projects', [
                'name' => 'مشروع اختبار مهام',
                'priority' => 'high',
                'department_id' => $this->department->id,
                'start_date' => now()->format('Y-m-d'),
                'end_date' => now()->addMonth()->format('Y-m-d'),
                'tasks' => [
                    [
                        'title' => 'مهمة 1',
                        'start_date' => now()->addDays(10)->format('Y-m-d'),
                        'due_date' => now()->addDays(3)->format('Y-m-d'),
                    ],
                ],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['tasks.0.due_date']);
    }

    /**
     * اختبار أن end_date يجب أن يكون بعد أو يساوي start_date في المشروع
     */
    public function test_project_end_date_must_be_after_or_equal_start_date(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/projects', [
                'name' => 'مشروع تواريخ معكوسة',
                'priority' => 'high',
                'department_id' => $this->department->id,
                'start_date' => now()->addDays(10)->format('Y-m-d'),
                'end_date' => now()->addDays(2)->format('Y-m-d'),
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['end_date']);
    }
}
