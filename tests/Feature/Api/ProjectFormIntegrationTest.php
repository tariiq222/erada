<?php

namespace Tests\Feature\Api;

use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectSetting;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Project Form Integration Tests
 *
 * Validates the frontend sanitized payload shape against the backend
 * StoreProjectRequest rules and project creation flow.
 *
 * Coverage:
 * - Valid payload with nested tasks/risks/milestones/stakeholders/team members (regression: no 422)
 * - Missing required supervisor_id when supervisor is required (negative)
 * - Empty strings converted to null (regression: no 422 from nullable|date/integer)
 * - User without create_projects permission receives 403 (authorization)
 */
class ProjectFormIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    protected User $user;

    protected User $supervisor;

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

        $this->supervisor = User::factory()->create([
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);

        $this->user->assignRole('super_admin');
    }

    /**
     * REGRESSION: Valid sanitized frontend payload should NOT return 422.
     *
     * This mirrors the exact shape produced by useProjectForm.ts handleSubmit.
     */
    public function test_valid_sanitized_payload_creates_project_without_422(): void
    {
        $payload = $this->buildValidPayload();

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/projects', $payload);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'project' => ['id', 'name'],
            ]);

        $this->assertDatabaseHas('projects', [
            'name' => 'مشروع نموذجي',
            'priority' => 'high',
            'status' => 'draft',
        ]);
    }

    /**
     * REGRESSION: Empty optional strings sent as null should not trigger 422.
     */
    public function test_empty_optional_fields_as_null_pass_validation(): void
    {
        $payload = $this->buildValidPayload([
            'description' => null,
            'start_date' => null,
            'end_date' => null,
            'budget' => null,
            'human_resources' => null,
            'technical_resources' => null,
            'financial_resources' => null,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/projects', $payload);

        $response->assertStatus(201);
    }

    /**
     * بعد توحيد أدوار المشاريع: حُذف حقل supervisor_id (لم يعد عموداً ولا قاعدة تحقق).
     * حتى مع تفعيل supervisor_required لم يعد إنشاء المشروع بدون مشرف يفشل — الإنشاء ينجح (201).
     */
    public function test_supervisor_required_setting_no_longer_blocks_creation(): void
    {
        ProjectSetting::setSupervisorRequired(true);

        // الـ payload لم يعد يتضمن supervisor_id (الحقل محذوف بعد توحيد الأدوار)
        $payload = $this->buildValidPayload();

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/projects', $payload);

        $response->assertStatus(201);
    }

    /**
     * REGRESSION: Empty strings are converted to null by Laravel middleware
     * and accepted by nullable|date rules. Frontend also converts to null.
     */
    public function test_empty_string_in_date_field_is_accepted(): void
    {
        $payload = $this->buildValidPayload([
            'start_date' => '',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/projects', $payload);

        // Laravel's ConvertEmptyStringsToNull middleware converts '' → null
        $response->assertStatus(201);
    }

    /**
     * AUTHORIZATION: User without create_projects permission receives 403.
     */
    public function test_user_without_permission_cannot_create_project(): void
    {
        $unauthorizedUser = User::factory()->create([
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);

        // Explicitly do NOT assign create_projects permission
        $unauthorizedUser->removeRole('super_admin');

        $payload = $this->buildValidPayload();

        $response = $this->actingAs($unauthorizedUser, 'sanctum')
            ->postJson('/api/projects', $payload);

        $response->assertStatus(403);
    }

    /**
     * RISK MAPPING VERIFICATION: After rework, frontend sends description/mitigation
     * keys matching backend StoreProjectRequest validation and RiskService expectations.
     */
    public function test_risk_mapping_creates_risk_successfully(): void
    {
        $payload = $this->buildValidPayload([
            'risks' => [
                [
                    'description' => 'تأخر في التسليم',
                    'probability' => 'high',
                    'impact' => 'high',
                    'mitigation' => 'تخصيص موارد إضافية',
                ],
            ],
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/projects', $payload);

        $response->assertStatus(201);

        $projectId = $response->json('project.id');

        // After rework: risks are created because frontend sends 'description' key.
        $this->assertDatabaseHas('project_risks', [
            'project_id' => $projectId,
            'risk' => 'تأخر في التسليم',
            'response' => 'تخصيص موارد إضافية',
        ]);
    }

    /**
     * TASK COMPATIBILITY: Frontend sends both 'name' and 'title' so that
     * TaskService::createTasks (which checks empty($taskData['name'])) does
     * not silently skip tasks, while backend createTask() stores to 'title'.
     */
    public function test_task_name_mapped_to_title(): void
    {
        $payload = $this->buildValidPayload([
            'tasks' => [
                [
                    'name' => 'مهمة نموذجية',
                    'title' => 'مهمة نموذجية',
                    'description' => null,
                    'milestone_index' => null,
                    'assigned_to' => null,
                    'priority' => 'medium',
                    'start_date' => null,
                    'due_date' => null,
                ],
            ],
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/projects', $payload);

        $response->assertStatus(201);

        $this->assertDatabaseHas('tasks', [
            'title' => 'مهمة نموذجية',
        ]);
    }

    /**
     * Build a payload matching the sanitized shape from useProjectForm.ts.
     */
    protected function buildValidPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'مشروع نموذجي',
            'type' => 'development',
            'description' => null,
            'objectives' => ['هدف 1', 'هدف 2'],
            'in_scope' => ['نطاق 1'],
            'out_of_scope' => ['خارج النطاق 1'],
            'department_id' => $this->department->id,
            'program_id' => null,
            // ملاحظة: حقول manager_id/supervisor_id/sponsor_id حُذفت بعد توحيد الأدوار
            // (لم تعد أعمدة ولا تُقبل في الـ payload).
            'status' => 'draft',
            'priority' => 'high',
            'start_date' => now()->format('Y-m-d'),
            'end_date' => now()->addMonths(3)->format('Y-m-d'),
            'budget' => 100000,
            'milestones' => [
                [
                    'name' => 'المرحلة الأولى',
                    'description' => null,
                    'start_date' => now()->format('Y-m-d'),
                    'due_date' => now()->addMonth()->format('Y-m-d'),
                    'deliverables' => [
                        ['name' => 'مخرج 1'],
                    ],
                ],
            ],
            'tasks' => [
                [
                    'name' => 'مهمة تجريبية',
                    'title' => 'مهمة تجريبية',
                    'description' => null,
                    'milestone_index' => null,
                    'assigned_to' => null,
                    'priority' => 'medium',
                    'start_date' => null,
                    'due_date' => null,
                ],
            ],
            'risks' => [
                [
                    'description' => 'خطر محتمل',
                    'probability' => 'medium',
                    'impact' => 'medium',
                    'mitigation' => 'خطة تخفيف',
                ],
            ],
            'team_members' => [
                [
                    'user_id' => $this->user->id,
                    'role' => 'manager',
                ],
            ],
            'stakeholders' => [
                [
                    'user_id' => null,
                    'name' => 'صاحب مصلحة',
                    'role' => 'مستشار',
                    'contact' => null,
                    'influence' => 'high',
                ],
            ],
            'human_resources' => null,
            'technical_resources' => null,
            'financial_resources' => null,
        ], $overrides);
    }
}
