<?php

namespace Tests\Feature\OVR;

use App\Modules\HR\Models\Department;
use App\Modules\OVR\Models\OvrSetting;
use Database\Seeders\OvrGovernanceSeeder;
use Illuminate\Console\Command as ConsoleCommand;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * Pins OvrGovernanceSeeder's behavioral contract:
 *   - respect an admin override already in the DB
 *   - auto-pick the narrowest QA / quality / جودة / سلامة department it finds
 *   - log an error and stop when no such department exists in the org tree
 *
 * ponytail: a silent no-op is the failure mode this seeder exists to prevent.
 * Three tests, three paths, no env vars, no magic dept codes beyond the
 * QA / quality / سلامة / جودة hint used for auto-discovery.
 */
class OvrGovernanceSeederTest extends TestCase
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
        // Admin already picked this department via the UI.
        $adminChoice = Department::create([
            'code' => 'PRE-CONFIGURED',
            'name' => 'Pre-configured QA Unit',
            'level' => Department::LEVEL_DEPARTMENT,
            'parent_id' => null,
            'organization_id' => null,
            'is_active' => true,
        ]);
        OvrSetting::setGoverningDepartmentId($adminChoice->id);

        $this->runSeeder();

        $this->assertSame($adminChoice->id, OvrSetting::getGoverningDepartmentId(),
            'seeder must respect the admin override, not stomp it.');

        $logged = $this->output->fetch();
        $this->assertStringContainsString('already configured', $logged);
    }

    public function test_seeder_auto_picks_quality_dept_by_code(): void
    {
        // Two QA-flavored depts — narrowest (highest level) should win.
        $parent = Department::create([
            'code' => 'AUTHZ-QA',
            'name' => 'Quality Directorate',
            'level' => Department::LEVEL_DEPARTMENT,
            'parent_id' => null,
            'organization_id' => null,
            'is_active' => true,
        ]);
        $child = Department::create([
            'code' => 'QA-SAFETY',
            'name' => 'Patient Safety Unit',
            'level' => Department::LEVEL_SECTION,
            'parent_id' => $parent->id,
            'organization_id' => null,
            'is_active' => true,
        ]);

        $this->runSeeder();

        $this->assertSame($child->id, OvrSetting::getGoverningDepartmentId(),
            'narrowest (level=SECTION) QA dept should win, not the parent directorate.');

        $logged = $this->output->fetch();
        $this->assertStringContainsString('QA-SAFETY', $logged);
    }

    public function test_seeder_auto_picks_quality_dept_by_arabic_name(): void
    {
        // No QA in code, but the Arabic name carries the signal.
        $dept = Department::create([
            'code' => 'OPS-OVERSIGHT',
            'name' => 'وحدة الجودة وسلامة المرضى',
            'level' => Department::LEVEL_SECTION,
            'parent_id' => null,
            'organization_id' => null,
            'is_active' => true,
        ]);

        $this->runSeeder();

        $this->assertSame($dept->id, OvrSetting::getGoverningDepartmentId());
    }

    public function test_seeder_warns_loudly_when_no_quality_department_exists(): void
    {
        $this->runSeeder();

        $this->assertNull(OvrSetting::getGoverningDepartmentId(),
            'governing department must remain unset when nothing matches.');

        $logged = $this->output->fetch();
        $this->assertStringContainsString('NOT SET', $logged,
            'seeder must print a clear error rather than fail silently.');
        $this->assertStringContainsString('PUT /api/ovr/settings/governing-department', $logged,
            'error must point at the admin endpoint that fixes this.');
        $this->assertStringContainsString('governs()', $logged,
            'error should name the engine function the operator can grep for.');
    }

    private function runSeeder(): void
    {
        $seeder = new OvrGovernanceSeeder;
        $seeder->setCommand($this->command);
        $seeder->run();
    }
}
