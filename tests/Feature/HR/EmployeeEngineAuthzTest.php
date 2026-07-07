<?php

namespace Tests\Feature\HR;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * EmployeeEngineAuthzTest — engine-based gates for EmployeeController and
 * EmployeeCertificateController helpers, plus the engine_capability route gate.
 *
 * Each controller has its own authorizeHr(Request, string $permission) helper.
 * The helpers previously read $user->hasPermissionTo($permission) (legacy Spatie
 * permission strings). The migration changes the gate to
 * AccessDecision::can($user, $permission) and updates callers to pass resolved
 * Capability constants (Capability::HR_VIEW / Capability::HR_MANAGE) instead of
 * the legacy 'view_hr' / 'manage_hr' strings.
 *
 * Post-cutover (HR pre-cleanup + Wave 3 route middleware migration, 2026-06-28):
 *   - The HR route group is now gated by engine_capability:Capability::HR_VIEW
 *     (replaces the legacy `middleware('permission:view_hr')`). The engine
 *     capability grant alone satisfies the route gate; no Spatie `givePermissionTo`
 *     is needed any more.
 *   - StoreEmployeeProfileRequest::authorize() now reads via AccessDecision too,
 *     so HR_MANAGE engine capability satisfies the FormRequest gate.
 *   - The legacy `manage_hr` grant is no longer needed (engine replaces it).
 *
 * URL notes (verified against app/Modules/HR/Routes/api.php):
 *   - HR routes are mounted at /api/hr/* (NOT /api/employees as the brief
 *     originally suggested). The actual route prefix inside HR/Routes/api.php
 *     is `Route::prefix('hr')`, applied on top of the service provider's
 *     `/api` base. So GET /api/hr/employees is the canonical URL.
 *   - POST /api/hr/employees flows through StoreEmployeeProfileRequest which
 *     has validation rules (e.g. employee_no required). With just `['name' => 'x']`
 *     the request is rejected by FormRequest validation with 422 BEFORE the
 *     controller's profile create runs; 422 therefore proves the engine let it
 *     through (the pre-cutover 403 path is replaced by 422 once the migration
 *     lands for all three gates in the chain).
 */
class EmployeeEngineAuthzTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    public function test_view_employees_requires_engine_capability(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        // Engine route middleware AND controller helper both read Capability::HR_VIEW.
        $this->grantEngineCapability($user, Capability::HR_VIEW);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/hr/employees')
            ->assertStatus(200);
    }

    public function test_create_employee_requires_manage_capability(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        // Engine route middleware + controller helper both need HR_VIEW; HR_MANAGE
        // satisfies the FormRequest authorize() gate (formerly manage_hr). Single
        // combined role covers both reads and writes — see the note in
        // EmployeeControllerTest::setUp about assignScopedRole single-role-per-scope.
        $this->grantEngineCapability(
            $user,
            [Capability::HR_VIEW, Capability::HR_MANAGE]
        );

        // StoreEmployeeProfileRequest validates `employee_no` + a user_id that
        // exists. With ['name' => 'x'] only, the FormRequest rejects with 422
        // AFTER the engine helper lets the request through.
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/hr/employees', ['name' => 'x'])
            ->assertStatus(422);
    }

    public function test_missing_capability_denies(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        // No engine capability: 403 (engine_capability route middleware
        // short-circuits before the controller helper).

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/hr/employees')
            ->assertStatus(403);
    }
}
