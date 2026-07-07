<?php

namespace Tests\Feature\RiskManagement;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use App\Modules\RiskManagement\Models\Risk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Phase 5 morph-target gate (D-05 alias-to-FQCN resolution) on
 * POST /api/risk-management/risks:
 *
 *  - `riskable_type` accepts only the public aliases
 *    (project|program|portfolio|task); an unknown alias is a 422.
 *  - A cross-org target (org-A admin pointing at an org-B project) is
 *    DENIED and nothing is persisted. Actual module behavior: the deny is
 *    enforced in StoreRiskRequest::withValidator(), so it surfaces as a
 *    422 validation error on `riskable_id` — NOT the 403 the phase plan
 *    bullet sketched. The org-isolation property (request rejected, no row
 *    created) is what this test locks.
 *  - Anti-enumeration contract: a nonexistent id and a cross-org id are
 *    rejected with IDENTICAL 422 bodies (same neutral message,
 *    'العنصر المرتبط غير صالح'), so an org user cannot probe which ids
 *    exist platform-wide.
 *  - A same-org alias resolves to the FQCN before persisting (positive
 *    control proving D-05).
 */
class RiskMorphTargetValidationTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $orgA;

    protected Organization $orgB;

    protected Department $department;

    protected User $orgAAdmin;

    protected Project $orgAProject;

    protected Project $orgBProject;

    protected function setUp(): void
    {
        parent::setUp();
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $this->orgA = Organization::factory()->create();
        $this->orgB = Organization::factory()->create();

        // ProjectObserver::saving() enforces project.organization_id == dept.organization_id.
        // Each org's projects must use a department that belongs to the same org,
        // otherwise the observer auto-corrects the project's org and the cross-org
        // isolation tests become meaningless.
        $this->department = Department::factory()->withOrganization($this->orgA->id)->create();
        $orgBDepartment = Department::factory()->withOrganization($this->orgB->id)->create();

        $this->orgAAdmin = User::factory()->create([
            'organization_id' => $this->orgA->id,
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        $this->orgAAdmin->assignRole('admin');

        $this->orgAProject = Project::factory()->create([
            'organization_id' => $this->orgA->id,
            'department_id' => $this->department->id,
        ]);

        $this->orgBProject = Project::factory()->create([
            'organization_id' => $this->orgB->id,
            'department_id' => $orgBDepartment->id,
        ]);
    }

    /**
     * Minimal valid StoreRiskRequest payload; riskable fields per test.
     */
    private function payload(array $override = []): array
    {
        return array_merge([
            'title' => 'خطر مرتبط بعنصر',
            'discovery_date' => '2026-06-01',
            'type' => 'operational',
            'initial_likelihood' => 2,
            'initial_impact' => 2,
            'response_type' => 'mitigate',
        ], $override);
    }

    public function test_cross_org_project_target_is_denied_and_nothing_persisted(): void
    {
        $response = $this->actingAs($this->orgAAdmin, 'sanctum')
            ->postJson('/api/risk-management/risks', $this->payload([
                'riskable_type' => 'project',
                'riskable_id' => $this->orgBProject->id,
            ]));

        // Deny is enforced at the validation layer (422 on riskable_id),
        // not as a 403 — see class docblock.
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['riskable_id']);

        $this->assertSame(
            'العنصر المرتبط غير صالح',
            $response->json('errors.riskable_id.0'),
            'Cross-org morph target must be rejected with the neutral riskable error.'
        );

        $this->assertSame(0, Risk::count(), 'A denied cross-org request must not persist a risk.');
    }

    public function test_unknown_alias_is_rejected_with_422(): void
    {
        $response = $this->actingAs($this->orgAAdmin, 'sanctum')
            ->postJson('/api/risk-management/risks', $this->payload([
                'riskable_type' => 'banana',
                'riskable_id' => $this->orgAProject->id,
            ]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['riskable_type']);

        $this->assertSame(0, Risk::count(), 'An unknown morph alias must not persist a risk.');
    }

    public function test_nonexistent_target_id_is_rejected_with_422(): void
    {
        $response = $this->actingAs($this->orgAAdmin, 'sanctum')
            ->postJson('/api/risk-management/risks', $this->payload([
                'riskable_type' => 'project',
                'riskable_id' => 999999,
            ]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['riskable_id']);

        $this->assertSame(0, Risk::count());
    }

    /**
     * Anti-enumeration contract: the 422 response for a nonexistent id and
     * for an id that exists in another organization must be byte-for-byte
     * identical, so the API leaks no signal about which ids exist
     * platform-wide.
     */
    public function test_nonexistent_and_cross_org_targets_are_indistinguishable(): void
    {
        $nonexistent = $this->actingAs($this->orgAAdmin, 'sanctum')
            ->postJson('/api/risk-management/risks', $this->payload([
                'riskable_type' => 'project',
                'riskable_id' => 999999,
            ]));

        $crossOrg = $this->actingAs($this->orgAAdmin, 'sanctum')
            ->postJson('/api/risk-management/risks', $this->payload([
                'riskable_type' => 'project',
                'riskable_id' => $this->orgBProject->id,
            ]));

        $nonexistent->assertStatus(422)->assertJsonValidationErrors(['riskable_id']);
        $crossOrg->assertStatus(422)->assertJsonValidationErrors(['riskable_id']);

        $this->assertSame(
            $nonexistent->json(),
            $crossOrg->json(),
            'The 422 body for a nonexistent id and a cross-org id must be identical — any difference is an existence oracle.'
        );

        $this->assertSame(
            'العنصر المرتبط غير صالح',
            $nonexistent->json('errors.riskable_id.0'),
            'Both rejections must use the single neutral message.'
        );

        $this->assertSame(0, Risk::count());
    }

    /**
     * Positive control (D-05): a same-org alias is accepted and stored as
     * the FQCN, never the raw alias string.
     */
    public function test_same_org_project_target_resolves_alias_to_fqcn(): void
    {
        $response = $this->actingAs($this->orgAAdmin, 'sanctum')
            ->postJson('/api/risk-management/risks', $this->payload([
                'title' => 'خطر مشروع داخل المؤسسة',
                'riskable_type' => 'project',
                'riskable_id' => $this->orgAProject->id,
            ]));

        $response->assertStatus(201);

        $risk = Risk::where('title', 'خطر مشروع داخل المؤسسة')->firstOrFail();
        $this->assertSame(Project::class, $risk->riskable_type, 'Alias `project` must be stored as the FQCN.');
        $this->assertSame($this->orgAProject->id, (int) $risk->riskable_id);
        $this->assertSame($this->orgA->id, (int) $risk->organization_id);
    }
}
