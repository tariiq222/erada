<?php

namespace Tests\Feature\Projects;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\ScopedRole;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\ScopedDepartmentRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * UpdateProjectRequestValidationTest — يغطّي حُرّاس التحقق المضافة لمسار التحديث:
 *  - فرض ≥1 KPI للمشاريع التحسينية (شامل التحويل new→improvement).
 *  - منع تلوث حقول المنهجية المتبادل (PMBOK vs FOCUS-PDCA).
 *  - إعادة فحص نطاق القسم عند نقل المشروع لقسم آخر.
 */
class UpdateProjectRequestValidationTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $org;

    protected Department $dept;

    protected User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(ScopedDepartmentRolesSeeder::class);
        Cache::flush();

        $this->org = Organization::factory()->create();
        $this->dept = Department::factory()->create([
            'organization_id' => $this->org->id,
            'level' => Department::LEVEL_DEPARTMENT,
        ]);

        $this->superAdmin = User::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
            'is_active' => true,
        ]);
        $this->superAdmin->assignRole('super_admin');
    }

    protected function makeProject(string $type, array $extra = []): Project
    {
        return Project::factory()->create(array_merge([
            'organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
            'type' => $type,
        ], $extra));
    }

    // ===================== Fix 3: KPI ≥1 on update =====================

    /** تحويل مشروع جديد → تحسيني بدون KPIs يُرفض (422 على kpis). */
    public function test_flip_new_to_improvement_without_kpis_is_rejected(): void
    {
        $project = $this->makeProject('development');

        $this->actingAs($this->superAdmin, 'sanctum')
            ->putJson("/api/projects/{$project->id}", [
                'type' => 'improvement',
                'problem_statement' => 'مشكلة',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['kpis']);
    }

    /** تحديث مشروع تحسيني مع الإبقاء على KPI يمر (200). */
    public function test_improvement_update_keeping_kpi_succeeds(): void
    {
        $project = $this->makeProject('improvement', ['problem_statement' => 'مشكلة']);

        $this->actingAs($this->superAdmin, 'sanctum')
            ->putJson("/api/projects/{$project->id}", [
                'description' => 'تحديث الوصف',
                'kpis' => [
                    ['name' => 'مؤشر', 'target' => 100, 'baseline' => 10],
                ],
            ])
            ->assertOk();
    }

    /** تحديث جزئي لمشروع جديد (بدون type) لا يفرض KPIs. */
    public function test_partial_update_of_new_project_does_not_require_kpis(): void
    {
        $project = $this->makeProject('development');

        $this->actingAs($this->superAdmin, 'sanctum')
            ->putJson("/api/projects/{$project->id}", [
                'description' => 'وصف جديد',
            ])
            ->assertOk();
    }

    // ============ Fix 4: methodology field cross-contamination ============

    /** مشروع جديد يرفض حقول التحسين (FOCUS-PDCA). */
    public function test_new_project_rejects_improvement_fields(): void
    {
        $project = $this->makeProject('development');

        $this->actingAs($this->superAdmin, 'sanctum')
            ->putJson("/api/projects/{$project->id}", [
                'root_cause' => 'سبب جذري',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['root_cause']);
    }

    /** مشروع تحسيني يرفض حقول PMBOK (مشروع جديد). */
    public function test_improvement_project_rejects_new_fields(): void
    {
        $project = $this->makeProject('improvement', ['problem_statement' => 'مشكلة']);

        $this->actingAs($this->superAdmin, 'sanctum')
            ->putJson("/api/projects/{$project->id}", [
                'business_case' => 'مبرر',
                'kpis' => [['name' => 'مؤشر', 'target' => 100]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['business_case']);
    }

    /** التحويل new→improvement يفرّغ حقول PMBOK القديمة (cleanup في الخدمة). */
    public function test_flip_to_improvement_clears_stale_pmbok_fields(): void
    {
        $project = $this->makeProject('development', [
            'business_case' => 'مبرر قديم',
            'exit_criteria' => 'معيار إنهاء',
        ]);

        $this->actingAs($this->superAdmin, 'sanctum')
            ->putJson("/api/projects/{$project->id}", [
                'type' => 'improvement',
                'problem_statement' => 'مشكلة',
                'kpis' => [['name' => 'مؤشر', 'target' => 100, 'baseline' => 5]],
            ])
            ->assertOk();

        $fresh = $project->fresh();
        $this->assertSame('improvement', $fresh->type);
        $this->assertNull($fresh->business_case);
        $this->assertNull($fresh->exit_criteria);
    }

    // ============ Fix 5: department scope re-check on reassignment ============

    /** نقل المشروع لقسم خارج نطاق صلاحية المُعدِّل يُرفض. */
    public function test_moving_project_to_out_of_scope_department_is_rejected(): void
    {
        // منطقتان: قسم المُعدِّل (داخل نطاقه) وقسم آخر خارج نطاقه.
        $homeDept = Department::factory()->create([
            'organization_id' => $this->org->id,
            'level' => Department::LEVEL_DEPARTMENT,
        ]);
        $foreignDept = Department::factory()->create([
            'organization_id' => $this->org->id,
            'level' => Department::LEVEL_DEPARTMENT,
        ]);

        // المُعدِّل: مدير قسم محصور بقسمه (create scope = homeDept فقط).
        $editor = User::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $homeDept->id,
            'is_active' => true,
        ]);
        $editor->assignScopedRole('dept_manager', ScopedRole::SCOPE_DEPARTMENT, $homeDept->id, null, true);
        Cache::flush();

        // مشروع في قسم المُعدِّل، وهو مديره السياقي (يملك صلاحية التحديث).
        $project = Project::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $homeDept->id,
            'type' => 'development',
        ]);
        $editor->assignProjectRole($project, ScopedRole::PROJECT_MANAGER);
        Cache::flush();

        $this->actingAs($editor, 'sanctum')
            ->putJson("/api/projects/{$project->id}", [
                'department_id' => $foreignDept->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['department_id']);

        $this->assertSame($homeDept->id, (int) $project->fresh()->department_id);
    }

    /** super_admin يمكنه نقل المشروع لأي قسم في المؤسسة. */
    public function test_super_admin_can_reassign_department(): void
    {
        $target = Department::factory()->create([
            'organization_id' => $this->org->id,
            'level' => Department::LEVEL_DEPARTMENT,
        ]);
        $project = $this->makeProject('development');

        $this->actingAs($this->superAdmin, 'sanctum')
            ->putJson("/api/projects/{$project->id}", [
                'department_id' => $target->id,
            ])
            ->assertOk();

        $this->assertSame($target->id, (int) $project->fresh()->department_id);
    }
}
