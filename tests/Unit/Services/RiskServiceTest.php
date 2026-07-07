<?php

namespace Tests\Unit\Services;

use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectRisk;
use App\Modules\Projects\Services\Project\RiskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RiskServiceTest extends TestCase
{
    use RefreshDatabase;

    private RiskService $service;

    private Department $department;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new RiskService;
        $this->department = Department::factory()->create();
    }

    private function makeProject(): Project
    {
        return Project::factory()->create(['department_id' => $this->department->id]);
    }

    public function test_can_create_risk(): void
    {
        $project = $this->makeProject();

        $risk = $this->service->createRisk($project, [
            'description' => 'خطر تأخر التسليم',
            'probability' => 'high',
            'impact' => 'high',
            'mitigation' => 'خطة بديلة',
        ]);

        $this->assertInstanceOf(ProjectRisk::class, $risk);
        $this->assertEquals('خطر تأخر التسليم', $risk->risk);
        $this->assertEquals('high', $risk->probability);
        $this->assertEquals('high', $risk->impact);
        $this->assertEquals('خطة بديلة', $risk->response);
    }

    public function test_can_create_risk_using_risk_field_name(): void
    {
        $project = $this->makeProject();

        $risk = $this->service->createRisk($project, [
            'risk' => 'خطر تقني',
            'probability' => 'medium',
            'impact' => 'low',
        ]);

        $this->assertEquals('خطر تقني', $risk->risk);
    }

    public function test_create_risk_uses_medium_defaults(): void
    {
        $project = $this->makeProject();

        $risk = $this->service->createRisk($project, ['description' => 'خطر بدون تفاصيل']);

        $this->assertEquals('medium', $risk->probability);
        $this->assertEquals('medium', $risk->impact);
        $this->assertEquals('open', $risk->status);
    }

    public function test_risk_order_is_set_correctly(): void
    {
        $project = $this->makeProject();

        $risk = $this->service->createRisk($project, ['description' => 'خطر'], 2);

        $this->assertEquals(3, $risk->order); // order + 1
    }

    public function test_create_risks_skips_empty_descriptions(): void
    {
        $project = $this->makeProject();

        $this->service->createRisks($project, [
            ['description' => 'خطر صالح', 'probability' => 'low', 'impact' => 'low'],
            ['description' => '', 'probability' => 'high', 'impact' => 'high'],
            ['description' => 'خطر آخر', 'probability' => 'medium', 'impact' => 'medium'],
        ]);

        $this->assertEquals(2, $project->risks()->count());
    }

    public function test_can_create_multiple_risks(): void
    {
        $project = $this->makeProject();

        $this->service->createRisks($project, [
            ['description' => 'خطر 1', 'probability' => 'low', 'impact' => 'low'],
            ['description' => 'خطر 2', 'probability' => 'high', 'impact' => 'high'],
        ]);

        $this->assertEquals(2, $project->risks()->count());
    }

    public function test_can_update_risk(): void
    {
        $project = $this->makeProject();
        $risk = $project->risks()->create([
            'risk' => 'خطر أصلي',
            'probability' => 'low',
            'impact' => 'low',
            'status' => 'open',
            'order' => 1,
        ]);

        $updated = $this->service->updateRisk($risk, [
            'risk' => 'خطر محدث',
            'probability' => 'high',
            'impact' => 'high',
        ]);

        $this->assertEquals('خطر محدث', $updated->risk);
        $this->assertEquals('high', $updated->probability);
        $this->assertEquals('high', $updated->impact);
    }

    public function test_update_risk_supports_description_field(): void
    {
        $project = $this->makeProject();
        $risk = $project->risks()->create([
            'risk' => 'خطر أصلي',
            'probability' => 'low',
            'impact' => 'low',
            'status' => 'open',
            'order' => 1,
        ]);

        $updated = $this->service->updateRisk($risk, ['description' => 'وصف محدث']);

        $this->assertEquals('وصف محدث', $updated->risk);
    }

    public function test_update_risk_supports_mitigation_field(): void
    {
        $project = $this->makeProject();
        $risk = $project->risks()->create([
            'risk' => 'خطر',
            'probability' => 'low',
            'impact' => 'low',
            'status' => 'open',
            'order' => 1,
        ]);

        $updated = $this->service->updateRisk($risk, ['mitigation' => 'خطة التخفيف']);

        $this->assertEquals('خطة التخفيف', $updated->response);
    }

    public function test_can_delete_risk(): void
    {
        $project = $this->makeProject();
        $risk = $project->risks()->create([
            'risk' => 'خطر للحذف',
            'probability' => 'low',
            'impact' => 'low',
            'status' => 'open',
            'order' => 1,
        ]);

        $result = $this->service->deleteRisk($risk);

        $this->assertTrue($result);
        $this->assertDatabaseMissing('project_risks', ['id' => $risk->id]);
    }

    public function test_can_replace_risks(): void
    {
        $project = $this->makeProject();
        $project->risks()->create(['risk' => 'خطر قديم', 'probability' => 'low', 'impact' => 'low', 'status' => 'open', 'order' => 1]);

        $this->service->replaceRisks($project, [
            ['description' => 'خطر جديد 1', 'probability' => 'high', 'impact' => 'high'],
            ['description' => 'خطر جديد 2', 'probability' => 'medium', 'impact' => 'medium'],
        ]);

        $this->assertEquals(2, $project->risks()->count());
        $this->assertDatabaseMissing('project_risks', ['risk' => 'خطر قديم']);
        $this->assertDatabaseHas('project_risks', ['risk' => 'خطر جديد 1']);
    }

    public function test_can_change_risk_status(): void
    {
        $project = $this->makeProject();
        $risk = $project->risks()->create([
            'risk' => 'خطر',
            'probability' => 'low',
            'impact' => 'low',
            'status' => 'open',
            'order' => 1,
        ]);

        $updated = $this->service->changeStatus($risk, 'mitigated');

        $this->assertEquals('mitigated', $updated->status);
    }

    public function test_get_open_risks(): void
    {
        $project = $this->makeProject();
        $project->risks()->create(['risk' => 'خطر مفتوح', 'probability' => 'low', 'impact' => 'low', 'status' => 'open', 'order' => 1]);
        $project->risks()->create(['risk' => 'خطر مغلق', 'probability' => 'low', 'impact' => 'low', 'status' => 'mitigated', 'order' => 2]);

        $openRisks = $this->service->getOpenRisks($project);

        $this->assertCount(1, $openRisks);
        $this->assertEquals('open', $openRisks->first()->status);
    }

    public function test_get_high_impact_risks(): void
    {
        $project = $this->makeProject();
        $project->risks()->create(['risk' => 'خطر عالي', 'probability' => 'high', 'impact' => 'high', 'status' => 'open', 'order' => 1]);
        $project->risks()->create(['risk' => 'خطر منخفض', 'probability' => 'low', 'impact' => 'low', 'status' => 'open', 'order' => 2]);
        $project->risks()->create(['risk' => 'خطر عالي مغلق', 'probability' => 'high', 'impact' => 'high', 'status' => 'mitigated', 'order' => 3]);

        $highRisks = $this->service->getHighImpactRisks($project);

        $this->assertCount(1, $highRisks);
        $this->assertEquals('high', $highRisks->first()->impact);
    }
}
