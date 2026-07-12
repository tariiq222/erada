<?php

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CanonicalRecordAbilitiesResourceTest extends TestCase
{
    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function resourceProvider(): array
    {
        return [
            'projects' => ['app/Modules/Projects/Http/Resources/ProjectResource.php', ['PROJECTS_VIEW', 'PROJECTS_EDIT', 'PROJECTS_DELETE', 'PROJECTS_ASSIGN_ROLES']],
            'tasks' => ['app/Modules/Tasks/Http/Resources/TaskResource.php', ['TASKS_VIEW', 'TASKS_EDIT', 'TASKS_DELETE', 'TASKS_COMPLETE', 'TASKS_ASSIGN']],
            'risks' => ['app/Modules/RiskManagement/Http/Resources/RiskResource.php', ['RISKS_VIEW', 'RISKS_EDIT', 'RISKS_DELETE', 'RISKS_REASSESS', 'RISKS_CHANGE_STATUS']],
            'ovr' => ['app/Modules/OVR/Http/Resources/IncidentReportResource.php', ['OVR_VIEW', 'OVR_EDIT', 'OVR_INVESTIGATE', 'OVR_CLOSE', 'OVR_ASSIGN']],
            'surveys' => ['app/Modules/Surveys/Http/Resources/SurveyResource.php', ['SURVEYS_VIEW', 'SURVEYS_EDIT', 'SURVEYS_DELETE']],
        ];
    }

    /**
     * @param  list<string>  $capabilities
     */
    #[DataProvider('resourceProvider')]
    public function test_record_abilities_use_the_canonical_engine_with_the_real_resource(string $path, array $capabilities): void
    {
        $source = file_get_contents(base_path($path));

        $this->assertIsString($source);
        $this->assertStringContainsString("'abilities' => ElementAbilities::resolve(", $source);
        $this->assertStringContainsString('$this->resource,', $source);

        foreach ($capabilities as $capability) {
            $this->assertStringContainsString("Capability::{$capability}", $source);
        }

        $this->assertStringNotContainsString("'view' => true", $source);
    }

    public function test_ovr_summary_list_includes_record_abilities(): void
    {
        $source = file_get_contents(base_path('app/Modules/OVR/Http/Resources/IncidentReportResource.php'));

        $this->assertIsString($source);
        $summaryEnd = strpos($source, 'if ($this->mode === self::MODE_SUMMARY)');
        $this->assertNotFalse($summaryEnd);
        $this->assertStringContainsString("'abilities' => ElementAbilities::resolve(", substr($source, 0, $summaryEnd));
    }
}
