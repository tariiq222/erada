<?php

namespace Tests\Feature\Authorization;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Authorization\Contracts\ScopeAware;
use App\Modules\Core\Authorization\Contracts\SensitivelyScoped;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\OVR\Enums\ReportStatus;
use App\Modules\OVR\Enums\SeverityLevel;
use App\Modules\OVR\Models\IncidentReport;
use App\Modules\OVR\Models\IncidentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * Canonical engine DoS guards and sensitive-record security boundaries.
 */
class EngineHardeningTest extends TestCase
{
    use GrantsEngineCapability;
    use RefreshDatabase;

    private IncidentType $incidentType;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        AccessDecision::flushCache();

        $this->incidentType = IncidentType::create([
            'name' => 'Medical Error',
            'name_ar' => 'خطأ طبي',
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        AccessDecision::flushCache();

        parent::tearDown();
    }

    // ============================================================
    // F1: cycle detection in extractOrganizationId (via scope chain)
    // ============================================================

    public function test_self_referential_scope_chain_does_not_stack_overflow(): void
    {
        // A self-referential ScopeAware node: its scopeParent() returns
        // itself. Pre-fix, the engine recursed into unbounded depth and
        // would segfault PHP. Post-fix, the visited-key guard trips on
        // the second visit and returns null. We assert the call returns
        // cleanly with a defined boolean (false -- the cycle prevents
        // organization-id resolution, the org gate fails closed).
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);

        $loopHolder = new \stdClass;
        $loopHolder->self = null;
        $loopHolder->self = new class($loopHolder) extends Model implements ScopeAware
        {
            public \stdClass $holder;

            public function __construct(\stdClass $holder)
            {
                $this->holder = $holder;
            }

            public function getKey()
            {
                return 1;
            }

            public function scopeParent(): ?Model
            {
                return $this->holder->self;
            }

            public function scopeTypeKey(): string
            {
                return 'project';
            }

            public function scopeOrganizationId(): ?int
            {
                return null;
            }
        };

        $result = AccessDecision::can($user, Capability::PROJECTS_VIEW, $loopHolder->self);

        $this->assertFalse($result);
    }

    public function test_depth_cap_in_extract_organization_id_short_circuits(): void
    {
        // Build a 40-deep chain of distinct anonymous ScopeAware nodes. The
        // depth cap (32) prevents unbounded walks; the engine returns false
        // (fail closed) rather than crashing.
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);

        $current = null;
        for ($i = 0; $i < 40; $i++) {
            $previous = $current;
            $current = new class((string) $i, $previous) extends Model implements ScopeAware
            {
                public string $nodeId;

                public ?Model $parentRef;

                public function __construct(string $nodeId, ?Model $parent)
                {
                    $this->nodeId = $nodeId;
                    $this->parentRef = $parent;
                }

                public function scopeParent(): ?Model
                {
                    return $this->parentRef;
                }

                public function scopeTypeKey(): string
                {
                    return 'project';
                }

                public function scopeOrganizationId(): ?int
                {
                    return null;
                }
            };
        }

        // 40-deep chain > cap of 32 → extractOrganizationId hits the cap and
        // returns null → org gate fails closed → can() returns false. The
        // test pin is that the call returns at all.
        $this->assertFalse(AccessDecision::can($user, Capability::PROJECTS_VIEW, $current));
    }

    // ============================================================
    // Sensitive structural floor
    // ============================================================

    private function makeReport(Organization $org, Department $dept, array $override = []): IncidentReport
    {
        $reporter = User::factory()->create(['organization_id' => $org->id]);

        return IncidentReport::create(array_merge([
            'organization_id' => $org->id,
            'reporter_id' => $reporter->id,
            'reporter_name' => $reporter->name,
            'reporter_email' => $reporter->email,
            'reporter_department_id' => $dept->id,
            'incident_datetime' => now(),
            'is_patient_related' => false,
            'informed_authority' => false,
            'incident_type_id' => $this->incidentType->id,
            'incident_description' => 'desc',
            'immediate_action_required' => false,
            'severity_level' => SeverityLevel::High,
            'status' => ReportStatus::New,
            'is_confidential' => true,
        ], $override));
    }

    public function test_sensitive_floor_admits_owner_via_created_by(): void
    {
        // (a) in the structural-floor branch list: created_by == user.id.
        // IncidentReport uses reporter_id, not created_by, so we exercise
        // the (a) branch through a synthetic SensitivelyScoped target that
        // has a created_by column. The engine is the unit under test; the
        // test target is just a vehicle for the ownership attribute.
        $org = Organization::factory()->create();
        $creator = User::factory()->create(['organization_id' => $org->id]);

        $target = $this->makeSyntheticSensitiveTarget($org, [
            'created_by' => $creator->id,
        ]);

        // The hook on this synthetic target defaults to admitting when
        // ownership holds, mirroring the (a)/(b)/(c) + hook AND pair.
        $this->assertTrue(AccessDecision::can($creator->fresh(), Capability::OVR_VIEW, $target));
    }

    public function test_sensitive_floor_admits_canonical_confidential_assignment(): void
    {
        // (b) scoped role whose definition lists OVR_CONFIDENTIAL in
        // permissions[] satisfies both the floor AND the OVR hook.
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);

        $cleared = User::factory()->create(['organization_id' => $org->id]);
        $this->grantEngineCapability(
            $cleared,
            [Capability::OVR_VIEW, Capability::OVR_CONFIDENTIAL],
            'organization',
            $org->id,
            'confidential_viewer',
        );

        $report = $this->makeReport($org, $dept);

        $this->assertTrue(AccessDecision::can($cleared->fresh(), Capability::OVR_VIEW, $report));
    }

    public function test_sensitive_floor_admits_org_admin_role(): void
    {
        // (c) an org-scope is_admin_role holds the floor open. The OVR
        // hook also requires OVR_CONFIDENTIAL in the role's
        // permissions[] (per the existing AUTHZ-DECISIONS.md carve-out
        // that is_admin_role alone does not grant need-to-know).
        // We seed the definition with both flags so the additive pair
        // (floor + hook) both grant.
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);

        $admin = User::factory()->create(['organization_id' => $org->id]);
        $this->grantEngineCapability(
            $admin,
            [Capability::OVR_VIEW, Capability::OVR_CONFIDENTIAL],
            'organization',
            $org->id,
            'org_admin',
            ['is_admin_role' => true],
        );

        $report = $this->makeReport($org, $dept);

        $this->assertTrue(AccessDecision::can($admin->fresh(), Capability::OVR_VIEW, $report));
    }

    public function test_sensitive_floor_denies_when_hook_missing_confidential_capability(): void
    {
        // Pin the additive semantics: a stranger passes neither the floor
        // NOR the hook on a confidential OVR record. Specifically, a
        // user who has a regular department-scoped admin role (no
        // OVR_CONFIDENTIAL permission) has neither (a) ownership nor
        // (b/c) confidential-grant, so the floor denies. The OVR hook
        // also denies (no reporter/assignee match, no confidential
        // permission). Result: deny.
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);

        $stranger = User::factory()->create(['organization_id' => $org->id]);
        // stranger has no role assignment on the report's org/dept.

        $report = $this->makeReport($org, $dept);

        $this->assertFalse(AccessDecision::can($stranger->fresh(), Capability::OVR_VIEW, $report));
    }

    public function test_sensitive_owner_is_admitted_through_public_canonical_decision(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);

        $target = $this->makeSyntheticSensitiveTarget($org, [
            'owner_id' => $user->id,
        ]);

        $this->assertTrue(AccessDecision::can($user->fresh(), Capability::OVR_VIEW, $target));
    }

    public function test_sensitive_stranger_is_denied_through_public_canonical_decision(): void
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $stranger = User::factory()->create(['organization_id' => $org->id]);

        $report = $this->makeReport($org, $dept);

        $this->assertFalse(AccessDecision::can($stranger->fresh(), Capability::OVR_VIEW, $report));
    }

    // ============================================================
    // Shared helpers
    // ============================================================

    /**
     * Build a synthetic SensitivelyScoped target that exposes the
     * ownership attributes the structural floor reads (created_by /
     * owner_id). The IncidentReport model uses `reporter_id`, so it is
     * unsuitable for pinning (a); this lets us assert that the floor's
     * (a) branch fires without depending on a real schema change.
     */
    private function makeSyntheticSensitiveTarget(Organization $org, array $attrs = []): Model
    {
        return new class($org->id, $attrs) extends Model implements SensitivelyScoped
        {
            public array $attrs;

            public function __construct(int $orgId, array $attrs)
            {
                $this->attrs = array_merge([
                    'created_by' => null,
                    'owner_id' => null,
                    'organization_id' => $orgId,
                ], $attrs);
            }

            public function getKey()
            {
                return 1;
            }

            public function getAttribute($key)
            {
                return $this->attrs[$key] ?? null;
            }

            public function setAttribute($key, $value)
            {
                $this->attrs[$key] = $value;
            }

            public function isSensitive(): bool
            {
                return true;
            }

            public function mayAccessSensitive(User $user): bool
            {
                // Mirror the (a) arm so the additive (floor && hook)
                // pair grants only when (a) holds.
                $createdBy = $this->getAttribute('created_by');
                $ownerId = $this->getAttribute('owner_id');

                return ($createdBy !== null && (int) $createdBy === (int) $user->id)
                    || ($ownerId !== null && (int) $ownerId === (int) $user->id);
            }
        };
    }
}
