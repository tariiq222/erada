<?php

namespace Tests\Feature\RiskManagement;

use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Http\Controllers\ProjectController;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectRisk;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Phase 5 coexistence gate: the new RiskManagement module must NOT touch
 * the legacy per-project risks feature (`project_risks` table, the
 * ProjectRisk model, and the /api/projects/{project}/risks endpoints).
 *
 * Three angles:
 *  1. End-to-end flow through the legacy API (create → re-score → close →
 *     delete) still works.
 *  2. The ProjectRisk model still exists with its key public surface
 *     (fillable columns, project() relation, risk_level accessor math).
 *  3. The legacy routes still resolve to ProjectController, side by side
 *     with the new named risk-management.* routes (route:list-style
 *     assertions — the legacy routes are unnamed, so they are matched by
 *     method + URI + action instead of Route::has()).
 */
class ProjectRiskCoexistenceTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Department $department;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $this->department = Department::factory()->create();

        $this->admin = User::factory()->create([
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        $this->grantCanonicalSuperAdmin($this->admin);

        $this->project = Project::factory()->create([
            'department_id' => $this->department->id,
        ]);
    }

    /**
     * Legacy flow end-to-end: create a project risk, update it so its
     * computed score/level changes, close it, then delete it.
     */
    public function test_legacy_project_risk_flow_still_works_end_to_end(): void
    {
        // 1. Create — POST /api/projects/{project}/risks
        $create = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/projects/{$this->project->id}/risks", [
                'risk' => 'خطر تأخير الموارد في المشروع القديم',
                'probability' => 'low',
                'impact' => 'low',
                'response' => 'استراتيجية التخفيف',
            ]);

        $create->assertStatus(201)
            ->assertJsonFragment(['message' => 'تم إضافة الخطر بنجاح']);

        $riskId = $create->json('risk.id');
        $this->assertNotNull($riskId, 'Legacy addRisk must return the created risk.');

        $this->assertDatabaseHas('project_risks', [
            'id' => $riskId,
            'project_id' => $this->project->id,
            'status' => 'open',
            'order' => 1,
        ]);

        $risk = ProjectRisk::findOrFail($riskId);
        $this->assertSame('low', $risk->risk_level, 'low×low must compute a low legacy risk level.');

        // 2. Re-score — PUT with higher probability/impact flips the level.
        $update = $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/projects/{$this->project->id}/risks/{$riskId}", [
                'probability' => 'high',
                'impact' => 'high',
            ]);

        $update->assertStatus(200)
            ->assertJsonFragment(['message' => 'تم تحديث الخطر بنجاح']);

        $this->assertSame(
            'critical',
            $risk->fresh()->risk_level,
            'high×high must escalate the computed legacy risk level to critical.'
        );

        // 3. Close — legacy status enum is open|mitigated|closed.
        $close = $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/projects/{$this->project->id}/risks/{$riskId}", [
                'status' => 'closed',
            ]);

        $close->assertStatus(200);
        $this->assertDatabaseHas('project_risks', [
            'id' => $riskId,
            'status' => 'closed',
        ]);

        // 4. Delete — DELETE /api/projects/{project}/risks/{risk}
        $delete = $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/projects/{$this->project->id}/risks/{$riskId}");

        $delete->assertStatus(200)
            ->assertJsonFragment(['message' => 'تم حذف الخطر بنجاح']);

        $this->assertSoftDeleted('project_risks', ['id' => $riskId]);
    }

    /**
     * Structural assertion: the legacy model file still exists and its key
     * public surface (fillable, relation, accessor) is unchanged.
     */
    public function test_legacy_project_risk_model_surface_is_intact(): void
    {
        $this->assertFileExists(
            app_path('Modules/Projects/Models/ProjectRisk.php'),
            'The legacy ProjectRisk model file must not be removed by the RiskManagement slice.'
        );
        $this->assertTrue(class_exists(ProjectRisk::class));

        $model = new ProjectRisk;

        $this->assertSame(
            ['project_id', 'risk', 'probability', 'impact', 'response', 'status', 'order'],
            $model->getFillable(),
            'Legacy ProjectRisk fillable columns must stay unchanged.'
        );

        $this->assertTrue(
            method_exists($model, 'project'),
            'ProjectRisk::project() relation must still exist.'
        );
        $this->assertInstanceOf(BelongsTo::class, $model->project());

        $this->assertTrue(
            method_exists($model, 'getRiskLevelAttribute'),
            'ProjectRisk::getRiskLevelAttribute() accessor must still exist.'
        );

        // Accessor math is untouched: score = probability × impact,
        // >=6 critical, >=4 high, >=2 medium, else low.
        $cases = [
            ['low', 'low', 'low'],          // 1×1 = 1
            ['low', 'medium', 'medium'],    // 1×2 = 2
            ['medium', 'medium', 'high'],   // 2×2 = 4
            ['high', 'high', 'critical'],   // 3×3 = 9
        ];

        foreach ($cases as [$probability, $impact, $expected]) {
            $instance = new ProjectRisk(['probability' => $probability, 'impact' => $impact]);
            $this->assertSame(
                $expected,
                $instance->risk_level,
                "Legacy risk_level for {$probability}×{$impact} must stay '{$expected}'."
            );
        }
    }

    /**
     * Route:list-style assertion: the legacy unnamed routes still resolve to
     * ProjectController, and the new module's named routes coexist with them.
     */
    public function test_legacy_routes_still_resolve_alongside_new_module_routes(): void
    {
        // Legacy routes carry no names, so match method + URI + action.
        $this->assertLegacyRouteResolves('POST', 'api/projects/{project}/risks', 'addRisk');
        $this->assertLegacyRouteResolves('PUT', 'api/projects/{project}/risks/{risk}', 'updateRisk');
        $this->assertLegacyRouteResolves('DELETE', 'api/projects/{project}/risks/{risk}', 'removeRisk');

        // The new module registers named routes — both registries coexist.
        foreach ([
            'risk-management.risks.index',
            'risk-management.risks.store',
            'risk-management.dashboard',
            'risk-management.export.csv',
            'risk-management.export.pdf',
        ] as $name) {
            $this->assertTrue(
                Route::has($name),
                "New RiskManagement route '{$name}' must be registered."
            );
        }
    }

    private function assertLegacyRouteResolves(string $method, string $uri, string $action): void
    {
        $expected = ProjectController::class.'@'.$action;

        $match = collect(Route::getRoutes()->getRoutes())->first(
            fn ($route) => in_array($method, $route->methods(), true)
                && $route->uri() === $uri
                && $route->getActionName() === $expected
        );

        $this->assertNotNull(
            $match,
            "Legacy route {$method} /{$uri} must still resolve to {$expected}."
        );
    }
}
