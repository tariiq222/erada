<?php

namespace Tests\Feature\Projects;

use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\ProjectSetting;
use Database\Seeders\ProjectGovernanceSeeder;
use Illuminate\Console\Command as ConsoleCommand;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * Pins the per-type mapping rule. Each project type (improvement / development)
 * resolves independently and respects an existing admin override:
 *
 *   - improvement → QA / quality / جودة / سلامة
 *   - development → PMO / planning / transformation / تخطيط / تحول
 *
 * ponytail: the previous seeder called setGoverningDepartments() with a
 * full map on every run — which silently overwrote anything an admin had
 * configured via the UI. The fix is the per-type guard here.
 */
class ProjectGovernanceSeederTest extends TestCase
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

    public function test_seeder_does_not_overwrite_existing_per_type_config(): void
    {
        // Admin has already paired improvement with one dept, development with another.
        $existingImprovement = Department::create([
            'code' => 'ADM-IMPROVE',
            'name' => 'Admin-picked improvement oversight',
            'level' => Department::LEVEL_DEPARTMENT,
            'parent_id' => null,
            'organization_id' => null,
            'is_active' => true,
        ]);
        $existingDevelopment = Department::create([
            'code' => 'ADM-DEVELOP',
            'name' => 'Admin-picked development oversight',
            'level' => Department::LEVEL_DEPARTMENT,
            'parent_id' => null,
            'organization_id' => null,
            'is_active' => true,
        ]);
        ProjectSetting::setGoverningDepartments([
            'improvement' => $existingImprovement->id,
            'development' => $existingDevelopment->id,
        ]);

        // Add a fresh QA dept the seeder might otherwise auto-pick.
        Department::create([
            'code' => 'QA-SAFETY',
            'name' => 'Quality Unit',
            'level' => Department::LEVEL_SECTION,
            'parent_id' => null,
            'organization_id' => null,
            'is_active' => true,
        ]);

        $this->runSeeder();

        $map = ProjectSetting::getGoverningDepartments();
        $this->assertSame($existingImprovement->id, $map['improvement'] ?? null,
            'admin-picked improvement oversight must be preserved');
        $this->assertSame($existingDevelopment->id, $map['development'] ?? null,
            'admin-picked development oversight must be preserved');

        $logged = $this->output->fetch();
        $this->assertStringContainsString('improvement', $logged);
        $this->assertStringContainsString('development', $logged);
        $this->assertStringContainsString('already configured', $logged);
    }

    public function test_seeder_auto_picks_per_type_when_unset(): void
    {
        $qa = Department::create([
            'code' => 'QA-OVERSIGHT',
            'name' => 'Quality Oversight',
            'level' => Department::LEVEL_SECTION,
            'parent_id' => null,
            'organization_id' => null,
            'is_active' => true,
        ]);
        $pmo = Department::create([
            'code' => 'AUTHZ-PMO',
            'name' => 'PMO Oversight',
            'level' => Department::LEVEL_SECTION,
            'parent_id' => null,
            'organization_id' => null,
            'is_active' => true,
        ]);

        $this->runSeeder();

        $map = ProjectSetting::getGoverningDepartments();
        $this->assertSame($qa->id, $map['improvement'] ?? null);
        $this->assertSame($pmo->id, $map['development'] ?? null);
    }

    public function test_seeder_warns_only_for_unmatched_type(): void
    {
        // Only QA exists; no PMO/planning dept.
        Department::create([
            'code' => 'QA-SAFETY',
            'name' => 'Quality Unit',
            'level' => Department::LEVEL_SECTION,
            'parent_id' => null,
            'organization_id' => null,
            'is_active' => true,
        ]);

        $this->runSeeder();

        $map = ProjectSetting::getGoverningDepartments();
        $this->assertArrayHasKey('improvement', $map);
        $this->assertArrayNotHasKey('development', $map,
            'development mapping must be left out when no matching dept exists.');

        $logged = $this->output->fetch();
        $this->assertStringContainsString('development', $logged);
        $this->assertStringContainsString('NOT SET', $logged);
        $this->assertStringContainsString('PUT /api/projects/governing-departments', $logged);
    }

    private function runSeeder(): void
    {
        $seeder = new ProjectGovernanceSeeder;
        $seeder->setCommand($this->command);
        $seeder->run();
    }
}
