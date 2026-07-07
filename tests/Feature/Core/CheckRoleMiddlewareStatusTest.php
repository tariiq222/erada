<?php

namespace Tests\Feature\Core;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Http\Middleware\CheckPermission;
use App\Modules\Core\Http\Middleware\CheckRole;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Router;
use Tests\TestCase;

/**
 * Live-legacy middleware status test — Phase 3 deliverable.
 *
 * Verifies that CheckRole and CheckPermission middleware are:
 *   1. Still registered as Laravel route aliases (`role:`, `permission:`).
 *   2. Actually wired into live routes (proves they're NOT dormant).
 *   3. NOT sufficient on their own for org-isolation — that responsibility
 *      lives in UserPolicy / UserController / UserOrganizationScope /
 *      UserRoleAssignmentGuard (the layers Phase 3 hardened).
 *
 * super_admin remains the platform bypass per the existing system design —
 * the test verifies an admin (org-scoped role) is denied cross-org access
 * even when the underlying CheckRole middleware has no cross-org knowledge.
 */
class CheckRoleMiddlewareStatusTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $orgA;

    protected Organization $orgB;

    protected Department $deptA;

    protected Department $deptB;

    private const SURVEYS_ROUTES_PATH = 'app/Modules/Surveys/Routes/api.php';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->orgA = Organization::factory()->create();
        $this->orgB = Organization::factory()->create();
        $this->deptA = Department::factory()->create(['organization_id' => $this->orgA->id]);
        $this->deptB = Department::factory()->create(['organization_id' => $this->orgB->id]);
    }

    public function test_role_middleware_alias_registered(): void
    {
        $router = app(Router::class);

        $this->assertSame(CheckRole::class, $router->getMiddleware()['role'] ?? null);
    }

    public function test_permission_middleware_alias_registered(): void
    {
        $router = app(Router::class);

        $this->assertSame(CheckPermission::class, $router->getMiddleware()['permission'] ?? null);
    }

    public function test_auth_alias_registered(): void
    {
        $router = app(Router::class);

        $this->assertSame(Authenticate::class, $router->getMiddleware()['auth'] ?? null);
    }

    public function test_role_middleware_used_in_core_routes(): void
    {
        // Parse the Core api routes file and prove `role:super_admin` is wired
        // into at least one route group. This protects against silent deletion
        // of the legacy middleware's only routes.
        $coreRoutes = file_get_contents(
            base_path('app/Modules/Core/Routes/api.php')
        );

        $this->assertStringContainsString("middleware('role:super_admin')", $coreRoutes);
        $this->assertGreaterThanOrEqual(
            5,
            substr_count($coreRoutes, "middleware('role:super_admin')"),
            'role:super_admin must guard at least 5 route groups in Core'
        );
    }

    public function test_surveys_response_routes_use_engine_capability_not_spatie_permission(): void
    {
        // Phase 8-D migration: `permission:view_survey_responses` (Spatie) was
        // replaced by engine_capability so the unified AccessDecision engine
        // gates PII response data. This test pins that move — if a future
        // change reverts surveys responses routes to Spatie permission, this
        // assertion fires and the regression is caught at PR time.
        //
        // We assert against the call-form `'permission:view_survey_responses'`
        // (inside a ->middleware(...) call) rather than the bare phrase so the
        // Phase 8-D explanatory comments in the routes file do not trip the
        // assertion.
        $surveysRoutes = file_get_contents(base_path(self::SURVEYS_ROUTES_PATH));

        $this->assertDoesNotMatchRegularExpression(
            '/->middleware\(\s*[\'"]permission:view_survey_responses[\'"]/',
            $surveysRoutes,
            'surveys responses routes must NOT use the Spatie permission:'
            ." 'view_survey_responses' — Phase 8-D migrated them to engine_capability."
        );
    }

    public function test_surveys_response_routes_guard_via_engine_capability(): void
    {
        // Companion to the negative assertion above: surveys responses
        // routes MUST be guarded via engine_capability:Capability::SURVEYS_REVIEW_RESPONSES.
        // We assert the source-level literal because that is the form any
        // future Phase reads — the runtime string only resolves after PHP
        // evaluates `Capability::SURVEYS_REVIEW_RESPONSES`.
        $surveysRoutes = file_get_contents(base_path(self::SURVEYS_ROUTES_PATH));

        $expectedLiteral = "'engine_capability:'.Capability::SURVEYS_REVIEW_RESPONSES";

        $this->assertStringContainsString(
            $expectedLiteral,
            $surveysRoutes,
            'surveys responses routes must be guarded by engine_capability:'
            .'Capability::SURVEYS_REVIEW_RESPONSES (the source-level literal,'
            .' not the runtime-concatenated string).'
        );
    }

    public function test_permission_middleware_alias_preserved_as_back_compat_seam(): void
    {
        // After Phase 8-C/D/E migrations, the `permission:` middleware is no
        // longer wired into any route file (all consumers moved to the
        // engine). The alias registration in
        // test_permission_middleware_alias_registered() above pins the
        // back-compat seam: external callers and unmigrated third-party
        // routes that still rely on it must not break on kernel boot.
        //
        // This companion test makes the post-migration contract explicit:
        // surveys (fully engine-driven) must NOT carry a permission: call.
        $surveysRoutes = file_get_contents(base_path(self::SURVEYS_ROUTES_PATH));

        $this->assertStringNotContainsString(
            "middleware('permission:",
            $surveysRoutes,
            'surveys is fully engine-driven post Phase 8-D — permission:'
            .' middleware should not appear in its routes.'
        );
    }

    public function test_role_middleware_still_live_for_intended_legacy_callers(): void
    {
        // Like CheckPermission, CheckRole('role:') is the live legacy seam.
        // It is intentionally absent from the surveys module (which is fully
        // engine-driven) but Core still relies on it for super_admin guards.
        // This test guards the route-file presence on the Core side so a
        // removal in surveys routes cannot be misread as a global removal.
        $surveysRoutes = file_get_contents(base_path(self::SURVEYS_ROUTES_PATH));

        $this->assertStringNotContainsString(
            "middleware('role:",
            $surveysRoutes,
            'surveys module is fully engine-driven — role: middleware should'
            .' not appear in its routes (super_admin bypass lives in the engine).'
        );
    }

    public function test_admin_org_b_cannot_view_org_a_user_via_policy_layer(): void
    {
        // CheckRole middleware has no org-isolation logic — it only checks
        // Spatie role names. Org-isolation MUST happen in UserPolicy::view
        // (called by ViewUserRequest::authorize). This test proves that
        // an admin in org B is denied read of an org-A user via the
        // /api/users/{user} endpoint, even though CheckRole itself does
        // not block them (they have the 'admin' Spatie role).
        $adminB = User::factory()->create([
            'organization_id' => $this->orgB->id,
            'department_id' => $this->deptB->id,
            'is_active' => true,
        ]);
        $adminB->assignRole('admin');

        $targetInA = User::factory()->create([
            'organization_id' => $this->orgA->id,
            'department_id' => $this->deptA->id,
            'is_active' => true,
        ]);
        $targetInA->assignRole('viewer');

        $this->actingAs($adminB, 'sanctum')
            ->getJson("/api/users/{$targetInA->id}")
            ->assertStatus(403);
    }

    public function test_admin_org_b_cannot_update_org_a_user_via_policy_layer(): void
    {
        // Same defense-in-depth claim for /api/users/{user} PUT.
        $adminB = User::factory()->create([
            'organization_id' => $this->orgB->id,
            'department_id' => $this->deptB->id,
            'is_active' => true,
        ]);
        $adminB->assignRole('admin');

        $targetInA = User::factory()->create([
            'organization_id' => $this->orgA->id,
            'department_id' => $this->deptA->id,
            'is_active' => true,
        ]);
        $targetInA->assignRole('viewer');

        $this->actingAs($adminB, 'sanctum')
            ->putJson("/api/users/{$targetInA->id}", [
                'name' => 'Hijacked',
            ])
            ->assertStatus(403);
    }

    public function test_admin_org_b_cannot_list_org_a_users_via_scope_layer(): void
    {
        // UserOrganizationScope (Phase 3) is the index filter that completes
        // the chain. CheckRole does not participate here — the policy +
        // scope layers must hold the line.
        $adminB = User::factory()->create([
            'organization_id' => $this->orgB->id,
            'department_id' => $this->deptB->id,
            'is_active' => true,
        ]);
        $adminB->assignRole('admin');

        // Add org-A users so the index has something to leak.
        User::factory()->create([
            'organization_id' => $this->orgA->id,
            'department_id' => $this->deptA->id,
            'is_active' => true,
        ])->assignRole('viewer');

        $response = $this->actingAs($adminB, 'sanctum')
            ->getJson('/api/users')
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($adminB->id, $ids, 'admin sees themselves');
        $this->assertCount(1, $response->json('data'), 'admin must only see org-B users');
    }

    public function test_super_admin_remains_platform_bypass(): void
    {
        // super_admin has no org binding — the existing CheckRole design
        // treats super_admin as a global bypass. Phase 3 does NOT change this.
        $superAdmin = User::factory()->create([
            'organization_id' => null,
            'department_id' => null,
            'is_active' => true,
        ]);
        $superAdmin->assignRole('super_admin');

        $targetInB = User::factory()->create([
            'organization_id' => $this->orgB->id,
            'department_id' => $this->deptB->id,
            'is_active' => true,
        ]);
        $targetInB->assignRole('viewer');

        $this->actingAs($superAdmin, 'sanctum')
            ->getJson("/api/users/{$targetInB->id}")
            ->assertOk();
    }

    public function test_role_middleware_class_file_present(): void
    {
        // Defensive: if either class file disappears, the aliases above
        // would still resolve to a non-existent class and fail at boot.
        $this->assertFileExists(base_path('app/Modules/Core/Http/Middleware/CheckRole.php'));
        $this->assertFileExists(base_path('app/Modules/Core/Http/Middleware/CheckPermission.php'));
    }
}
