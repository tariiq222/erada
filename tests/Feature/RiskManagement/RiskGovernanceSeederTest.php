<?php

namespace Tests\Feature\RiskManagement;

use App\Modules\HR\Models\Department;
use App\Modules\RiskManagement\Models\RiskSetting;
use Database\Seeders\RiskGovernanceSeeder;
use Illuminate\Console\Command as ConsoleCommand;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * Same contract as OvrGovernanceSeederTest, applied to Risk. Three paths:
 *   - existing admin config preserved
 *   - auto-detect via org-tree signal (qa / quality / جودة / سلامة)
 *   - loud warn when nothing matches
 */
class RiskGovernanceSeederTest extends TestCase
{
    use RefreshDatabase;

    private BufferedOutput $output;

    private ConsoleCommand $command;

    protected function setUp(): void
    {
        parent::setUp();

        $this->output = new BufferedOutput;
        $this->command = new ConsoleCommand(new ArgvInput([]));
        $this->command->setOutput(new OutputStyle(new ArgvInput([]), $this->output));
    }

    public function test_seeder_does_not_overwrite_an_existing_admin_configuration(): void
    {
        $existing = Department::create([
            'code' => 'PRE-CONFIGURED-RISK',
            'name' => 'Pre-configured Risk QA',
            'level' => Department::LEVEL_DEPARTMENT,
            'parent_id' => null,
            'organization_id' => null,
            'is_active' => true,
        ]);
        RiskSetting::setGoverningDepartmentId($existing->id);

        $this->runSeeder();

        $this->assertSame($existing->id, RiskSetting::getGoverningDepartmentId());
        $this->assertStringContainsString('already configured', $this->output->fetch());
    }

    public function test_seeder_auto_picks_narrowest_quality_dept_by_code(): void
    {
        $parent = Department::create([
            'code' => 'AUTHZ-QA',
            'name' => 'Quality Directorate',
            'level' => Department::LEVEL_DEPARTMENT,
            'parent_id' => null,
            'organization_id' => null,
            'is_active' => true,
        ]);
        $child = Department::create([
            'code' => 'QA-RISK-OVERSIGHT',
            'name' => 'Risk Quality Oversight',
            'level' => Department::LEVEL_SECTION,
            'parent_id' => $parent->id,
            'organization_id' => null,
            'is_active' => true,
        ]);

        $this->runSeeder();

        $this->assertSame($child->id, RiskSetting::getGoverningDepartmentId(),
            'narrowest (SECTION) QA dept should win.');

        $this->assertStringContainsString('QA-RISK-OVERSIGHT', $this->output->fetch());
    }

    public function test_seeder_auto_picks_by_arabic_name(): void
    {
        $dept = Department::create([
            'code' => 'OPS',
            'name' => 'وحدة جودة المخاطر',
            'level' => Department::LEVEL_SECTION,
            'parent_id' => null,
            'organization_id' => null,
            'is_active' => true,
        ]);

        $this->runSeeder();

        $this->assertSame($dept->id, RiskSetting::getGoverningDepartmentId());
    }

    public function test_seeder_warns_loudly_when_no_quality_department_exists(): void
    {
        $this->runSeeder();

        $this->assertNull(RiskSetting::getGoverningDepartmentId());

        $logged = $this->output->fetch();
        $this->assertStringContainsString('NOT SET', $logged);
        $this->assertStringContainsString('/api/risk-management/settings/governing-department', $logged);
    }

    private function runSeeder(): void
    {
        $seeder = new RiskGovernanceSeeder;
        $seeder->setCommand($this->command);
        $seeder->run();
    }
}
