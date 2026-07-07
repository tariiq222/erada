<?php

namespace Tests\Unit\Services;

use App\Modules\Core\Models\ScopedRole;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\Stakeholder;
use App\Modules\Projects\Services\Project\StakeholderService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StakeholderServiceTest extends TestCase
{
    use RefreshDatabase;

    private StakeholderService $service;

    private Department $department;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->service = new StakeholderService;
        $this->department = Department::factory()->create();
    }

    private function makeProject(array $overrides = []): Project
    {
        return Project::factory()->create(array_merge(['department_id' => $this->department->id], $overrides));
    }

    private function makeUser(): User
    {
        return User::factory()->create([
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
    }

    public function test_can_create_stakeholder(): void
    {
        $project = $this->makeProject();

        $stakeholder = $this->service->createStakeholder($project, [
            'name' => 'محمد علي',
            'role' => 'implementer',
            'influence' => 'high',
        ]);

        $this->assertInstanceOf(Stakeholder::class, $stakeholder);
        $this->assertEquals('محمد علي', $stakeholder->name);
        $this->assertEquals('implementer', $stakeholder->role);
        $this->assertEquals('high', $stakeholder->influence);
    }

    public function test_invalid_role_defaults_to_other(): void
    {
        $project = $this->makeProject();

        $stakeholder = $this->service->createStakeholder($project, [
            'name' => 'أحمد',
            'role' => 'invalid_role',
        ]);

        $this->assertEquals('other', $stakeholder->role);
    }

    public function test_empty_role_defaults_to_other(): void
    {
        $project = $this->makeProject();

        $stakeholder = $this->service->createStakeholder($project, [
            'name' => 'خالد',
            'role' => '',
        ]);

        $this->assertEquals('other', $stakeholder->role);
    }

    public function test_contact_field_maps_to_email(): void
    {
        $project = $this->makeProject();

        $stakeholder = $this->service->createStakeholder($project, [
            'name' => 'فاطمة',
            'role' => 'end_user',
            'contact' => 'fatima@example.com',
        ]);

        $this->assertEquals('fatima@example.com', $stakeholder->email);
    }

    public function test_can_create_multiple_stakeholders(): void
    {
        $project = $this->makeProject();

        $this->service->createStakeholders($project, [
            ['name' => 'عمر', 'role' => 'end_user'],
            ['name' => 'سارة', 'role' => 'consultant'],
        ]);

        $this->assertEquals(2, $project->stakeholders()->count());
    }

    public function test_create_stakeholders_skips_empty_names(): void
    {
        $project = $this->makeProject();

        $this->service->createStakeholders($project, [
            ['name' => 'عمر', 'role' => 'end_user'],
            ['name' => '', 'role' => 'consultant'],
            ['name' => '  ', 'role' => 'governance'],
        ]);

        $this->assertEquals(1, $project->stakeholders()->count());
    }

    public function test_can_update_stakeholder(): void
    {
        $project = $this->makeProject();
        $stakeholder = $project->stakeholders()->create([
            'name' => 'الاسم القديم',
            'role' => 'end_user',
            'influence' => 'low',
        ]);

        $updated = $this->service->updateStakeholder($stakeholder, [
            'name' => 'الاسم الجديد',
            'role' => 'governance',
            'influence' => 'high',
        ]);

        $this->assertEquals('الاسم الجديد', $updated->name);
        $this->assertEquals('governance', $updated->role);
        $this->assertEquals('high', $updated->influence);
    }

    public function test_update_stakeholder_ignores_invalid_role(): void
    {
        $project = $this->makeProject();
        $stakeholder = $project->stakeholders()->create([
            'name' => 'اسم',
            'role' => 'end_user',
            'influence' => 'medium',
        ]);

        $updated = $this->service->updateStakeholder($stakeholder, [
            'role' => 'invalid_role',
        ]);

        // يجب أن يبقى الدور القديم لأن القيمة الجديدة غير صالحة
        $this->assertEquals('end_user', $updated->role);
    }

    public function test_can_delete_stakeholder(): void
    {
        $project = $this->makeProject();
        $stakeholder = $project->stakeholders()->create([
            'name' => 'صاحب مصلحة للحذف',
            'role' => 'other',
            'influence' => 'low',
        ]);

        $result = $this->service->deleteStakeholder($stakeholder);

        $this->assertTrue($result);
        $this->assertDatabaseMissing('stakeholders', ['id' => $stakeholder->id]);
    }

    public function test_can_replace_stakeholders(): void
    {
        $project = $this->makeProject();
        $project->stakeholders()->create(['name' => 'قديم', 'role' => 'other', 'influence' => 'low']);

        $this->service->replaceStakeholders($project, [
            ['name' => 'جديد 1', 'role' => 'end_user'],
            ['name' => 'جديد 2', 'role' => 'implementer'],
        ]);

        $this->assertEquals(2, $project->stakeholders()->count());
        $this->assertDatabaseMissing('stakeholders', ['name' => 'قديم']);
    }

    public function test_add_project_leaders_as_stakeholders(): void
    {
        // بعد التوحيد: addProjectLeadersAsStakeholders يقرأ مديري المشروع من الأدوار
        // السياقية (scoped PROJECT_MANAGER) لا من أعمدة sponsor_id/manager_id (المحذوفة).
        $manager = $this->makeUser();
        $project = $this->makeProject();
        $manager->assignProjectRole($project, ScopedRole::PROJECT_MANAGER);

        $this->service->addProjectLeadersAsStakeholders($project);

        // مدير المشروع يُضاف كصاحب مصلحة
        $this->assertTrue($project->stakeholders()->where('user_id', $manager->id)->exists());
    }

    public function test_add_project_leaders_does_not_duplicate_existing_stakeholders(): void
    {
        // المدير (scoped manager) موجود مسبقاً كصاحب مصلحة → لا يُضاف مرتين.
        $manager = $this->makeUser();
        $project = $this->makeProject();
        $manager->assignProjectRole($project, ScopedRole::PROJECT_MANAGER);
        $project->stakeholders()->create([
            'user_id' => $manager->id,
            'name' => $manager->name,
            'role' => 'other',
            'influence' => 'medium',
        ]);

        $this->service->addProjectLeadersAsStakeholders($project);

        // يجب ألا يضاف مرتين
        $this->assertEquals(1, $project->stakeholders()->where('user_id', $manager->id)->count());
    }

    public function test_get_high_influence_stakeholders(): void
    {
        $project = $this->makeProject();
        $project->stakeholders()->create(['name' => 'عالي', 'role' => 'governance', 'influence' => 'high']);
        $project->stakeholders()->create(['name' => 'منخفض', 'role' => 'end_user', 'influence' => 'low']);
        $project->stakeholders()->create(['name' => 'متوسط', 'role' => 'other', 'influence' => 'medium']);

        $highInfluence = $this->service->getHighInfluenceStakeholders($project);

        $this->assertCount(1, $highInfluence);
        $this->assertEquals('high', $highInfluence->first()->influence);
    }

    public function test_get_stakeholders_by_role(): void
    {
        $project = $this->makeProject();
        $project->stakeholders()->create(['name' => 'مستخدم 1', 'role' => 'end_user', 'influence' => 'low']);
        $project->stakeholders()->create(['name' => 'مستخدم 2', 'role' => 'end_user', 'influence' => 'medium']);
        $project->stakeholders()->create(['name' => 'استشاري', 'role' => 'consultant', 'influence' => 'high']);

        $endUsers = $this->service->getStakeholdersByRole($project, 'end_user');
        $consultants = $this->service->getStakeholdersByRole($project, 'consultant');

        $this->assertCount(2, $endUsers);
        $this->assertCount(1, $consultants);
    }
}
