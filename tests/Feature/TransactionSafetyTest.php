<?php

namespace Tests\Feature;

use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Services\Project\RiskService;
use App\Modules\Projects\Services\ProjectCrudService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class TransactionSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Department $department;

    protected ProjectCrudService $service;

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

        $this->service = app(ProjectCrudService::class);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * اختبار أن تحديث المشروع يكون atomically (كل شيء أو لا شيء)
     */
    public function test_update_project_is_atomic_on_failure(): void
    {
        $project = Project::factory()->create([
            'department_id' => $this->department->id,
            'name' => 'اسم قبل التعديل',
        ]);

        // نحفظ الاسم الأصلي للمقارنة
        $originalName = $project->name;

        // نُmock خدمة المخاطر لتلقي استثناء
        // ProductCrudService::updateProject يستدعي syncRisks (ليس replaceRisks
        // المهجور) لمزامنة المخاطر مع الحفاظ على الهوية والحالة.
        $mockRiskService = Mockery::mock(RiskService::class);
        $mockRiskService->shouldReceive('syncRisks')
            ->once()
            ->andThrow(new \RuntimeException('فشل محاكى في قاعدة البيانات'));

        // نستبدل الـ binding في الـ container
        $this->app->instance(RiskService::class, $mockRiskService);

        // نحصل على service جديدة بعد استبدال الـ dependency
        $service = app(ProjectCrudService::class);

        try {
            $service->updateProject($project, [
                'name' => 'اسم بعد التعديل',
                'risks' => [
                    ['description' => 'مخاطر', 'probability' => 'medium', 'impact' => 'high', 'mitigation' => 'اختبار'],
                ],
            ], $this->user);

            $this->fail('كان يُتوقع رمي استثناء');
        } catch (\RuntimeException $e) {
            // متوقع
        }

        $project->refresh();

        // بما أن العملية داخل transaction، يجب أن يبقى الاسم الأصلي
        $this->assertEquals($originalName, $project->name);
        $this->assertDatabaseHas('projects', [
            'id' => $project->id,
            'name' => $originalName,
        ]);
    }
}
