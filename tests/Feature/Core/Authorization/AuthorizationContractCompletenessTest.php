<?php

namespace Tests\Feature\Core\Authorization;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Authorization\Support\CapabilityToAuthorizationRolePermission;
use App\Modules\HR\Models\Department;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Meetings\Models\Recommendation;
use App\Modules\OVR\Models\IncidentReport;
use App\Modules\Performance\Models\Kpi;
use App\Modules\Projects\Models\Project;
use App\Modules\RiskManagement\Models\Risk;
use App\Modules\Strategy\Models\Portfolio;
use App\Modules\Surveys\Models\Survey;
use App\Modules\Tasks\Models\Task;
use Tests\TestCase;

class AuthorizationContractCompletenessTest extends TestCase
{
    /**
     * Operational models intentionally collapse onto canonical authorization
     * families. This is not a one-model-per-resource registry.
     *
     * @var array<string, class-string>
     */
    private const GRAPH_FAMILIES = [
        'Portfolio' => Portfolio::class,
        'Program' => Portfolio::class,
        'Review' => Portfolio::class,
        'Blocker' => Portfolio::class,
        'Project' => Project::class,
        'Milestone' => Project::class,
        'MilestoneDeliverable' => Project::class,
        'ProjectExpense' => Project::class,
        'Department' => Department::class,
        'Task' => Task::class,
        'Risk' => Risk::class,
        'RiskAssessment' => Risk::class,
        'RiskAction' => Risk::class,
        'IncidentReport' => IncidentReport::class,
        'Meeting' => Meeting::class,
        'Recommendation' => Recommendation::class,
        'Kpi' => Kpi::class,
        'KpiMeasurement' => Kpi::class,
        'Survey' => Survey::class,
        'DataImportRequest' => Survey::class,
    ];

    public function test_every_capability_has_a_canonical_descriptor(): void
    {
        foreach (Capability::all() as $capability) {
            $row = CapabilityToAuthorizationRolePermission::map($capability);

            $this->assertIsArray($row, "Capability [{$capability}] is unmapped.");
            $this->assertSame(
                ['resource', 'action'],
                array_keys($row),
                "Capability [{$capability}] has an incomplete canonical descriptor."
            );
            $this->assertTrue(class_exists($row['resource']));
            $this->assertNotSame('', $row['action']);
        }
    }

    public function test_every_graph_row_declares_a_canonical_family_in_the_doc(): void
    {
        $doc = file_get_contents(base_path('docs/authz/resource-authorization-graph.md'));

        foreach (self::GRAPH_FAMILIES as $model => $family) {
            $this->assertMatchesRegularExpression(
                '/^\| '.preg_quote($model, '/').' \|[^\n]*\| '.preg_quote(class_basename($family), '/').' \|$/m',
                $doc,
                "Graph row [{$model}] must declare canonical family [".class_basename($family).'].',
            );
        }
    }

    public function test_each_declared_graph_family_has_a_mapped_capability_action(): void
    {
        $mappedFamilies = [];
        foreach (Capability::all() as $capability) {
            $row = CapabilityToAuthorizationRolePermission::map($capability);
            if ($row !== null) {
                $mappedFamilies[$row['resource']] = true;
            }
        }

        foreach (array_unique(self::GRAPH_FAMILIES) as $family) {
            $this->assertArrayHasKey(
                $family,
                $mappedFamilies,
                'Canonical family ['.$family.'] has no mapped capability action.'
            );
        }
    }
}
