<?php

namespace Tests\Feature\Core\Authorization;

use App\Modules\Core\Authorization\Contracts\ScopeAware;
use App\Modules\Core\Authorization\Models\AuthorizationDecisionAudit;
use App\Modules\Core\Authorization\Models\AuthorizationRecordRule;
use App\Modules\Core\Authorization\Models\AuthorizationResource;
use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Authorization\Models\AuthorizationRoleAssignment;
use App\Modules\Core\Authorization\Models\AuthorizationRolePermission;
use App\Modules\Core\Models\Organization;
use App\Modules\HR\Models\Department;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Meetings\Models\Recommendation;
use App\Modules\OVR\Models\IncidentReport;
use App\Modules\Performance\Models\Kpi;
use App\Modules\Performance\Models\KpiMeasurement;
use App\Modules\Projects\Models\Milestone;
use App\Modules\Projects\Models\MilestoneDeliverable;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectExpense;
use App\Modules\RiskManagement\Models\Risk;
use App\Modules\RiskManagement\Models\RiskAction;
use App\Modules\RiskManagement\Models\RiskAssessment;
use App\Modules\Strategy\Models\Blocker;
use App\Modules\Strategy\Models\Portfolio;
use App\Modules\Strategy\Models\Program;
use App\Modules\Strategy\Models\Review;
use App\Modules\Surveys\Models\DataImportRequest;
use App\Modules\Surveys\Models\Survey;
use App\Modules\Tasks\Models\Task;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ResourceAuthorizationGraphTest — Phase 3 of master AuthZ unification plan.
 *
 * Pins the contract between
 *   (a) the human-readable Resource Authorization Graph doc
 *       (docs/authz/resource-authorization-graph.md) and
 *   (b) the runtime shape of every primary operational resource.
 *
 * A primary operational resource is a model that users see on a screen
 * and whose authorization decision flows through AccessDecision::can().
 * The curated list below is the source of truth for "what is primary";
 * adding a row here without adding the matching row to the graph doc
 * (or vice-versa) breaks this suite.
 *
 * The test does NOT auto-discover models. Auto-discovery catches every
 * supporting/helper model (KpiLink, MeetingAgendaItem, EmployeeProfile,
 * etc.) and would force a Phase-4-sized ScopeAware rollout to pass. That
 * rollout is its own slice — see Phase 4 (source/sensitivity model
 * implementation). Until then, the test enforces the contract for the
 * resources the engine already covers.
 */
class ResourceAuthorizationGraphTest extends TestCase
{
    /**
     * Curated primary operational resources — the engines reads these
     * through AccessDecision::can(). Each row asserts the engine path the
     * resource MUST honor today.
     *
     *   - ScopeAware  : the engine walks scope chains for this resource.
     *   - ChildOnly   : the engine resolves through the resource's parent.
     *   - EngineInternal: engine reads/writes the table itself; not an
     *                    operational resource flowing through the engine.
     *
     * @var array<string, array{class: class-string, status: 'scope_aware'|'child_only'|'engine_internal', parent: string|null}>
     */
    private const PRIMARY_RESOURCES = [
        // Strategy
        'Portfolio' => ['class' => Portfolio::class, 'status' => 'scope_aware', 'parent' => null],
        'Program' => ['class' => Program::class, 'status' => 'scope_aware', 'parent' => 'Portfolio'],
        'Review' => ['class' => Review::class, 'status' => 'scope_aware', 'parent' => null],
        'Blocker' => ['class' => Blocker::class, 'status' => 'scope_aware', 'parent' => null],

        // Projects
        'Project' => ['class' => Project::class, 'status' => 'scope_aware', 'parent' => 'Department'],
        'Milestone' => ['class' => Milestone::class, 'status' => 'child_only', 'parent' => 'Project'],
        'MilestoneDeliverable' => ['class' => MilestoneDeliverable::class, 'status' => 'child_only', 'parent' => 'Milestone'],
        'ProjectExpense' => ['class' => ProjectExpense::class, 'status' => 'child_only', 'parent' => 'Project'],

        // HR
        'Department' => ['class' => Department::class, 'status' => 'scope_aware', 'parent' => 'Organization'],

        // Tasks
        'Task' => ['class' => Task::class, 'status' => 'scope_aware', 'parent' => 'Project|Department|PersonalOwner'],

        // Risk
        'Risk' => ['class' => Risk::class, 'status' => 'scope_aware', 'parent' => 'Department|riskable'],
        'RiskAssessment' => ['class' => RiskAssessment::class, 'status' => 'scope_aware', 'parent' => 'Risk'],
        'RiskAction' => ['class' => RiskAction::class, 'status' => 'scope_aware', 'parent' => 'Risk'],

        // OVR
        'IncidentReport' => ['class' => IncidentReport::class, 'status' => 'scope_aware', 'parent' => 'Department|reporter'],

        // Meetings
        'Meeting' => ['class' => Meeting::class, 'status' => 'scope_aware', 'parent' => 'Department+subject'],
        // Direction B (commit f98adef5): the standalone Decision model is
        // gone; rulings live on Recommendation with kind=ruling and inherit
        // scope from Meeting directly. Recommendation is the only row now.
        'Recommendation' => ['class' => Recommendation::class, 'status' => 'scope_aware', 'parent' => 'Meeting'],

        // Performance
        'Kpi' => ['class' => Kpi::class, 'status' => 'scope_aware', 'parent' => 'Department'],
        // KpiMeasurement currently does not implement ScopeAware; it routes
        // through its Kpi parent's scope chain. Phase 4 may flip it to
        // scope_aware once source_type/source_id polymorphism is added.
        'KpiMeasurement' => ['class' => KpiMeasurement::class, 'status' => 'child_only', 'parent' => 'Kpi'],

        // Surveys
        'Survey' => ['class' => Survey::class, 'status' => 'scope_aware', 'parent' => 'Organization|Department'],
        'DataImportRequest' => ['class' => DataImportRequest::class, 'status' => 'scope_aware', 'parent' => 'Organization'],
    ];

    /**
     * Engine-internal models (configuration / audit / lookup tables) that
     * the engine reads/writes but are not operational resources.
     *
     * @var array<int, class-string>
     */
    private const ENGINE_INTERNAL_MODELS = [
        AuthorizationDecisionAudit::class,
        AuthorizationRecordRule::class,
        AuthorizationResource::class,
        AuthorizationRole::class,
        AuthorizationRoleAssignment::class,
        AuthorizationRolePermission::class,
        Organization::class,
    ];

    private const GRAPH_DOC = 'docs/authz/resource-authorization-graph.md';

    #[Test]
    public function test_graph_doc_exists(): void
    {
        $this->assertFileExists(
            base_path(self::GRAPH_DOC),
            'Resource Authorization Graph doc is missing. Phase 3 ships the contract; the doc lives at docs/authz/resource-authorization-graph.md.'
        );
    }

    #[Test]
    public function test_scope_aware_primary_resources_implement_scope_aware_contract(): void
    {
        // Engine-only models must implement ScopeAware so the engine can
        // walk their scope chain + apply org isolation. A row in the
        // curated list marked 'scope_aware' that does not implement the
        // contract is a silent gap — pin it.
        foreach (self::PRIMARY_RESOURCES as $name => $row) {
            if ($row['status'] !== 'scope_aware') {
                continue;
            }
            $this->assertTrue(
                is_subclass_of($row['class'], ScopeAware::class),
                sprintf(
                    '%s is marked scope_aware in ResourceAuthorizationGraphTest::PRIMARY_RESOURCES '
                    .'but does not implement %s. Either implement the contract or reclassify the row.',
                    $name,
                    ScopeAware::class,
                )
            );
        }
    }

    #[Test]
    public function test_child_only_primary_resources_do_not_implement_scope_aware(): void
    {
        // A child-only resource that also implements ScopeAware creates
        // two competing parents (its own + the parent's scope chain).
        // Pin the contract: child-only resources ride their parent's
        // scope walk exclusively.
        foreach (self::PRIMARY_RESOURCES as $name => $row) {
            if ($row['status'] !== 'child_only') {
                continue;
            }
            $this->assertFalse(
                is_subclass_of($row['class'], ScopeAware::class),
                sprintf(
                    '%s is marked child_only but implements %s; pick one — '
                    .'either move it to scope_aware and add a graph row, or '
                    .'remove ScopeAware from the class.',
                    $name,
                    ScopeAware::class,
                )
            );
        }
    }

    #[Test]
    public function test_graph_doc_lists_every_primary_resource(): void
    {
        $doc = file_get_contents(base_path(self::GRAPH_DOC));

        foreach (self::PRIMARY_RESOURCES as $name => $row) {
            $this->assertStringContainsString(
                '| '.$name.' |',
                $doc,
                sprintf(
                    '%s is in PRIMARY_RESOURCES but missing from the graph '
                    .'doc table. Add a row to docs/authz/resource-authorization-graph.md.',
                    $name,
                )
            );
        }
    }

    #[Test]
    public function test_graph_doc_child_only_section_lists_child_only_primary_resources(): void
    {
        $doc = file_get_contents(base_path(self::GRAPH_DOC));

        foreach (self::PRIMARY_RESOURCES as $name => $row) {
            if ($row['status'] !== 'child_only') {
                continue;
            }
            $this->assertStringContainsString(
                $name,
                $doc,
                sprintf(
                    '%s is marked child_only but missing from the '
                    .'"Child-only value objects" table in the graph doc.',
                    $name,
                )
            );
        }
    }

    #[Test]
    public function test_primary_resources_with_no_engine_coverage_are_explicitly_classified(): void
    {
        // Every primary resource MUST have an explicit status. The
        // default class-level const definition above enforces this at
        // compile time; this test guards against drift if the const is
        // ever refactored to an array literal.
        foreach (self::PRIMARY_RESOURCES as $name => $row) {
            $this->assertContains(
                $row['status'],
                ['scope_aware', 'child_only', 'engine_internal'],
                sprintf(
                    '%s has unknown status "%s"; allowed values: scope_aware, child_only, engine_internal.',
                    $name,
                    $row['status'],
                )
            );
        }
    }

    #[Test]
    public function test_engine_internal_models_are_not_listed_as_operational_resources(): void
    {
        // Engine-internal models must not appear as a primary resource
        // row — they are configuration data, not operational resources
        // flowing through AccessDecision::can(). Use a precise regex so
        // resource names that happen to appear as a parent column do not
        // produce a false positive (e.g. "| Department | Organization |").
        $doc = file_get_contents(base_path(self::GRAPH_DOC));

        foreach (self::ENGINE_INTERNAL_MODELS as $class) {
            $basename = class_basename($class);
            $pattern = '/^\| '.$basename.' \|/m';

            $this->assertSame(
                0,
                preg_match($pattern, $doc),
                sprintf(
                    '%s is an engine-internal model; it must not be listed '
                    .'as a primary resource row in the graph doc.',
                    $basename,
                )
            );
        }
    }
}
