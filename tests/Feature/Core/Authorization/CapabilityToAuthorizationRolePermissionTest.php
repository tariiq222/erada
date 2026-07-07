<?php

namespace Tests\Feature\Core\Authorization;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Authorization\CapabilityAlias;
use App\Modules\Core\Authorization\Support\CapabilityToAuthorizationRolePermission;
use App\Modules\HR\Models\Department;
use App\Modules\Shared\Models\Attachment;
use App\Modules\Shared\Models\Comment;
use App\Modules\Strategy\Models\Portfolio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * CapabilityToAuthorizationRolePermissionTest -- Phase 1 Task 1.2.1.
 *
 * Pure mapping test: every `Capability::all()` value must resolve to a real
 * FQCN + a deterministic action suffix, exactly once. No database writes.
 *
 * User-approved defaults:
 *   - strategy.*  -> App\Modules\Strategy\Models\Portfolio
 *   - hr.*        -> App\Modules\HR\Models\Department
 *   - attachments.* -> App\Modules\Shared\Models\Attachment
 *   - comments.*  -> App\Modules\Shared\Models\Comment
 *
 * Other prefixes are resolved by CapabilityToAuthorizationRolePermission's
 * nearest-existing-model table. The test only asserts the four approved
 * defaults explicitly and that every mapped FQCN resolves to an existing
 * class.
 */
class CapabilityToAuthorizationRolePermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Authorization mapping test is PostgreSQL-only.');
        }
    }

    public function test_every_capability_constant_maps_exactly_once(): void
    {
        $capabilities = Capability::all();
        $this->assertNotEmpty($capabilities, 'Capability::all() returned no constants.');

        // The mapping is a pure function: each input capability resolves to
        // exactly ONE (resource, action) pair. Two different capabilities MAY
        // resolve to the same pair by design (e.g. user-approved `hr.view` and
        // `departments.view` both target Department::view); the pivot seeder
        // dedupes that downstream via the composite primary key. We only
        // assert here that any single capability never maps twice.
        $perCapability = [];
        foreach ($capabilities as $capability) {
            $this->assertIsString($capability);
            $this->assertNotSame('', $capability, 'Empty capability string encountered.');

            $row = CapabilityToAuthorizationRolePermission::map($capability);

            $this->assertNotNull($row, "Capability [{$capability}] did not map to any resource.");
            $this->assertArrayHasKey('resource', $row, "Mapped row for [{$capability}] missing 'resource'.");
            $this->assertArrayHasKey('action', $row, "Mapped row for [{$capability}] missing 'action'.");
            $this->assertNotEmpty($row['resource'], "Mapped resource for [{$capability}] is empty.");
            $this->assertNotEmpty($row['action'], "Mapped action for [{$capability}] is empty.");

            $this->assertArrayNotHasKey(
                $capability,
                $perCapability,
                "Capability [{$capability}] produced more than one mapping result."
            );

            $perCapability[$capability] = $row;
        }

        $this->assertSame(count($capabilities), count($perCapability), 'One or more capabilities produced multiple mapping results.');
    }

    public function test_every_mapped_fqcn_resolves_to_an_existing_class(): void
    {
        foreach (Capability::all() as $capability) {
            $row = CapabilityToAuthorizationRolePermission::map($capability);
            $this->assertNotNull($row, "Capability [{$capability}] did not map.");

            $this->assertTrue(
                class_exists($row['resource']),
                "Mapped resource [{$row['resource']}] for capability [{$capability}] does not exist."
            );
        }
    }

    public function test_approved_defaults_for_strategy_hr_attachments_and_comments(): void
    {
        $cases = [
            'strategy.view' => Portfolio::class,
            'strategy.create' => Portfolio::class,
            'strategy.manage_priority' => Portfolio::class,
            'hr.view' => Department::class,
            'hr.manage' => Department::class,
            'hr.manage_profiles' => Department::class,
            'attachments.view' => Attachment::class,
            'attachments.upload' => Attachment::class,
            'attachments.delete' => Attachment::class,
            'comments.view' => Comment::class,
            'comments.create' => Comment::class,
            'comments.delete' => Comment::class,
        ];

        foreach ($cases as $capability => $expectedResource) {
            $row = CapabilityToAuthorizationRolePermission::map($capability);
            $this->assertNotNull($row, "Approved default mapping missing for [{$capability}].");
            $this->assertSame(
                $expectedResource,
                $row['resource'],
                "Approved default for [{$capability}] should map to [{$expectedResource}] but mapped to [{$row['resource']}]."
            );
        }
    }

    public function test_action_suffix_matches_segment_after_last_dot(): void
    {
        $cases = [
            'projects.create' => 'create',
            'projects.manage_members' => 'manage_members',
            'tasks.complete' => 'complete',
            'ovr.view_confidential' => 'view_confidential',
            'risks.view_reports' => 'view_reports',
        ];

        foreach ($cases as $capability => $expectedAction) {
            $row = CapabilityToAuthorizationRolePermission::map($capability);
            $this->assertNotNull($row, "Mapping missing for [{$capability}].");
            $this->assertSame(
                $expectedAction,
                $row['action'],
                "Action suffix for [{$capability}] should be [{$expectedAction}] but got [{$row['action']}]."
            );
        }
    }

    public function test_known_prefixes_map_to_expected_module_resources(): void
    {
        $cases = [
            'projects.view' => 'App\\Modules\\Projects\\Models\\Project',
            'tasks.create' => 'App\\Modules\\Tasks\\Models\\Task',
            'departments.edit' => 'App\\Modules\\HR\\Models\\Department',
            'users.view' => 'App\\Modules\\Core\\Models\\User',
            'meetings.create' => 'App\\Modules\\Meetings\\Models\\Meeting',
            'surveys.view' => 'App\\Modules\\Surveys\\Models\\Survey',
            'ovr.create' => 'App\\Modules\\OVR\\Models\\IncidentReport',
            'risks.create' => 'App\\Modules\\RiskManagement\\Models\\Risk',
            'kpis.view' => 'App\\Modules\\Performance\\Models\\Kpi',
            'recommendations.create' => 'App\\Modules\\Meetings\\Models\\Recommendation',
            'settings.edit' => 'App\\Modules\\Core\\Models\\SystemSettings',
            'audit.view' => 'App\\Modules\\Shared\\Models\\ActivityLog',
        ];

        foreach ($cases as $capability => $expectedResource) {
            $row = CapabilityToAuthorizationRolePermission::map($capability);
            $this->assertNotNull($row, "Mapping missing for [{$capability}].");
            $this->assertSame(
                $expectedResource,
                $row['resource'],
                "Expected [{$capability}] to map to [{$expectedResource}], got [{$row['resource']}]."
            );
        }
    }

    public function test_user_approved_default_collisions_are_explicit_design(): void
    {
        // `hr.view` and `departments.view` both map to Department::view by
        // user-approved design. Asserting the collision documents the
        // contract so a future change can't quietly drop one of the two
        // mappings without noticing.
        $hrRow = CapabilityToAuthorizationRolePermission::map('hr.view');
        $departmentsRow = CapabilityToAuthorizationRolePermission::map('departments.view');

        $this->assertNotNull($hrRow);
        $this->assertNotNull($departmentsRow);
        $this->assertSame($hrRow['resource'], $departmentsRow['resource']);
        $this->assertSame($hrRow['action'], $departmentsRow['action']);
    }

    public function test_capability_alias_legacy_strings_resolve_to_a_known_capability(): void
    {
        $map = CapabilityAlias::map();

        foreach ($map as $legacy => $capability) {
            if ($capability === null) {
                // Transition alias -- not part of Capability::all(). Skip silently.
                continue;
            }

            $this->assertContains(
                $capability,
                Capability::all(),
                "CapabilityAlias [{$legacy}] points to [{$capability}] which is not in Capability::all()."
            );

            $row = CapabilityToAuthorizationRolePermission::map($capability);
            $this->assertNotNull(
                $row,
                "CapabilityAlias [{$legacy}] -> [{$capability}] did not resolve to a resource row."
            );
        }
    }

    public function test_map_returns_null_for_unknown_capability(): void
    {
        $this->assertNull(CapabilityToAuthorizationRolePermission::map('totally.unknown.capability'));
        $this->assertNull(CapabilityToAuthorizationRolePermission::map(''));
    }

    public function test_mapped_capability_count_equals_capability_all_count(): void
    {
        $all = Capability::all();

        $resolved = 0;
        foreach ($all as $capability) {
            if (CapabilityToAuthorizationRolePermission::map($capability) !== null) {
                $resolved++;
            }
        }

        $this->assertSame(
            count($all),
            $resolved,
            'Some Capability constants did not resolve via CapabilityToAuthorizationRolePermission::map().'
        );
    }
}
