<?php

namespace Tests\Feature\Projects;

use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Performance\Models\Kpi;
use App\Modules\Performance\Models\KpiLink;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Services\ProjectCrudService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('projects')]
#[Group('projects-deletion')]
class ProjectDeletionOrphanLinksTest extends TestCase
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
            'organization_id' => $this->department->organization_id,
            'is_active' => true,
        ]);
        $this->grantCanonicalSuperAdmin($this->user);

        $this->service = app(ProjectCrudService::class);
    }

    /**
     * إنشاء KPI وربطه بالمشروع عبر kpi_links (يحاكي ما يفعله createPerformanceKpis).
     */
    private function attachKpiToProject(Project $project): Kpi
    {
        $kpi = Kpi::factory()->create([
            'organization_id' => $project->organization_id,
            'owner_id' => $this->user->id,
            'created_by' => $this->user->id,
        ]);

        // KpiLink::$fillable لا يحتوي organization_id — نستخدم forceFill مثل createPerformanceKpis في الخدمة.
        (new KpiLink)->forceFill([
            'organization_id' => $project->organization_id,
            'kpi_id' => $kpi->id,
            'linkable_type' => Project::class,
            'linkable_id' => $project->id,
            'relationship_type' => 'primary',
            'weight' => 1,
            'created_by' => $this->user->id,
        ])->save();

        return $kpi;
    }

    /**
     * Case A — حذف المشروع يزيل روابط kpi_links لكنه يبقي سجلات Kpi نفسها
     * (المؤشرات ملك موديول Performance وقد تُستخدم من مشاريع/برامج/استراتيجيات أخرى).
     */
    public function test_delete_project_removes_orphan_kpi_links_but_keeps_kpis(): void
    {
        $project = Project::factory()->create([
            'department_id' => $this->department->id,
        ]);

        $kpi1 = $this->attachKpiToProject($project);
        $kpi2 = $this->attachKpiToProject($project);

        // sanity check قبل الحذف
        $this->assertSame(2, KpiLink::query()->where('linkable_id', $project->id)->count());
        $this->assertSame(2, Kpi::query()->count());

        $result = $this->service->deleteProject($project);

        $this->assertTrue($result);

        // المشروع محذوف soft
        $this->assertSoftDeleted('projects', ['id' => $project->id]);

        // لا روابط KPI لهذا المشروع
        $this->assertSame(
            0,
            KpiLink::query()->where('linkable_id', $project->id)->count(),
            'kpi_links rows for this project must be deleted'
        );

        // المؤشرات نفسها باقية
        $this->assertDatabaseHas('kpis', ['id' => $kpi1->id]);
        $this->assertDatabaseHas('kpis', ['id' => $kpi2->id]);
        $this->assertSame(2, Kpi::query()->count(), 'Kpi records must NOT be deleted');
    }

    /**
     * Case C — حذف مشروع بلا روابط KPI يعمل دون خطأ.
     */
    public function test_delete_project_succeeds_when_no_kpi_links_present(): void
    {
        $project = Project::factory()->create([
            'department_id' => $this->department->id,
        ]);

        $this->assertSame(0, KpiLink::query()->where('linkable_id', $project->id)->count());

        $result = $this->service->deleteProject($project);

        $this->assertTrue($result);
        $this->assertSoftDeleted('projects', ['id' => $project->id]);
        $this->assertSame(0, KpiLink::query()->count());
    }

    /**
     * Case D — حذف مشروع A لا يمسّ روابط kpi_links الخاصة بمشروع آخر B.
     */
    public function test_delete_project_does_not_touch_kpi_links_for_other_projects(): void
    {
        $projectA = Project::factory()->create(['department_id' => $this->department->id]);
        $projectB = Project::factory()->create(['department_id' => $this->department->id]);

        $kpiA = $this->attachKpiToProject($projectA);
        $kpiB = $this->attachKpiToProject($projectB);

        $this->service->deleteProject($projectA);

        // A: محذوف + روابطه محذوفة
        $this->assertSoftDeleted('projects', ['id' => $projectA->id]);
        $this->assertSame(0, KpiLink::query()->where('linkable_id', $projectA->id)->count());

        // B: لم يُمَس
        $this->assertDatabaseHas('projects', ['id' => $projectB->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('kpi_links', [
            'linkable_id' => $projectB->id,
            'kpi_id' => $kpiB->id,
        ]);

        // كلا المؤشرين ما زالا موجودين
        $this->assertDatabaseHas('kpis', ['id' => $kpiA->id]);
        $this->assertDatabaseHas('kpis', ['id' => $kpiB->id]);
    }

    /**
     * Case B (اختياري) — عند فشل خطوة داخل المعاملة (نحاكيها بإلقاء استثناء من
     * مستمع Eloquent deleting) يجب أن تُلفى كل عمليات الحذف السابقة داخل
     * المعاملة (kpi_links, tasks, scoped_roles, …) ولا يُحذف المشروع نفسه.
     */
    public function test_delete_project_rolls_back_when_final_delete_throws(): void
    {
        $project = Project::factory()->create([
            'department_id' => $this->department->id,
        ]);
        $kpi = $this->attachKpiToProject($project);

        $this->assertDatabaseHas('kpi_links', [
            'linkable_id' => $project->id,
            'kpi_id' => $kpi->id,
        ]);

        // مستمع على event:deleting الخاص بـ Project — يُطلق قبل SQL UPDATE فيُجبر
        // DB::transaction على rollback كل الخطوات السابقة في نفس المعاملة.
        Event::listen(
            'eloquent.deleting: '.Project::class,
            function (Project $model): void {
                throw new \RuntimeException('simulated mid-transaction failure');
            }
        );

        try {
            $this->service->deleteProject($project);
            $this->fail('Expected exception was not thrown');
        } catch (\RuntimeException $e) {
            // متوقّع
            $this->assertSame('simulated mid-transaction failure', $e->getMessage());
        } finally {
            Event::forget('eloquent.deleting: '.Project::class);
        }

        // rollback: المشروع حي، لم يُحذف
        $this->assertDatabaseHas('projects', [
            'id' => $project->id,
            'deleted_at' => null,
        ]);

        // rollback: روابط KPI لم تُحذف
        $this->assertDatabaseHas('kpi_links', [
            'linkable_id' => $project->id,
            'kpi_id' => $kpi->id,
        ]);

        // المؤشر نفسه بقي
        $this->assertDatabaseHas('kpis', ['id' => $kpi->id]);
    }
}
