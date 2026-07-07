<?php

namespace Tests\Feature\Api;

use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ProjectNewTypePayloadTest extends TestCase
{
    use DatabaseTransactions;

    protected User $user;

    protected Department $department;

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
        $this->user->assignRole('super_admin');
    }

    public function test_create_pmbok_project_with_charter_textareas_as_arrays(): void
    {
        $payload = [
            'name' => 'مشروع PMBOK اختباري',
            'type' => 'development',
            'description' => 'وصف',
            'status' => 'planning',
            'priority' => 'high',
            'start_date' => now()->format('Y-m-d'),
            'end_date' => now()->addMonths(3)->format('Y-m-d'),
            'department_id' => $this->department->id,
            'manager_id' => $this->user->id,
            'business_case' => 'مبرر المشروع نص',
            'success_criteria' => ['معيار 1', 'معيار 2'],
            'requirements' => ['متطلب 1', 'متطلب 2'],
            'manager_authority' => ['صلاحية 1', 'صلاحية 2'],
            'approval_criteria' => 'معايير الموافقة نص',
            'exit_criteria' => 'معايير الإنهاء نص',
            // No FOCUS-PDCA fields: a PMBOK ('development') payload must not carry
            // improvement-only fields (cross-contamination is now rejected).
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/projects', $payload);

        $response->assertStatus(201);
        $this->assertDatabaseHas('projects', [
            'name' => 'مشروع PMBOK اختباري',
            'type' => 'development',
        ]);
    }

    public function test_create_improvement_project_still_works(): void
    {
        $payload = [
            'name' => 'مشروع تحسيني',
            'type' => 'improvement',
            'status' => 'planning',
            'priority' => 'medium',
            'start_date' => now()->format('Y-m-d'),
            'end_date' => now()->addMonths(2)->format('Y-m-d'),
            'department_id' => $this->department->id,
            'problem_statement' => 'بيان المشكلة نصي',
            'target_process' => 'العملية',
            'root_cause' => 'السبب الجذري',
            'expected_benefits' => ['فائدة 1', 'فائدة 2'],
            'current_pdca_phase' => 'plan',
            // Improvement projects require at least one KPI at creation.
            'kpis' => [
                ['name' => 'معدل التحسّن', 'target' => 90, 'baseline' => 60, 'unit' => '%'],
            ],
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/projects', $payload);

        $response->assertStatus(201);
    }
}
