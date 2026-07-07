<?php

namespace Tests\Feature\RiskManagement;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\RiskManagement\Enums\RiskLevel;
use App\Modules\RiskManagement\Enums\RiskResponseType;
use App\Modules\RiskManagement\Enums\RiskStatus;
use App\Modules\RiskManagement\Enums\RiskType;
use App\Modules\RiskManagement\Models\Risk;
use App\Modules\RiskManagement\Models\RiskAssessment;
use App\Modules\RiskManagement\Models\RiskStatusChange;
use App\Modules\RiskManagement\Notifications\RiskLevelEscalatedNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

class RiskApiTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    protected Organization $org;

    protected Organization $otherOrg;

    protected User $admin;

    protected User $viewer;

    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->org = Organization::factory()->create();
        $this->otherOrg = Organization::factory()->create();
        $this->admin = User::factory()->create([
            'organization_id' => $this->org->id,
            'is_active' => true,
        ]);
        $this->admin->assignRole('admin');

        $this->viewer = User::factory()->create([
            'organization_id' => $this->org->id,
            'is_active' => true,
        ]);
        $this->viewer->assignRole('viewer');

        $this->token = $this->admin->createToken('test')->plainTextToken;
    }

    private function authHeaders(?User $user = null): array
    {
        $token = $user ? $user->createToken('test')->plainTextToken : $this->token;

        return ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
    }

    public function test_admin_can_create_risk_with_auto_code(): void
    {
        $payload = [
            'title' => 'انقطاع التيار الكهربائي في قسم الطوارئ',
            'discovery_date' => now()->toDateString(),
            'type' => RiskType::Operational->value,
            'initial_likelihood' => 5,
            'initial_impact' => 5,
            'response_type' => RiskResponseType::Mitigate->value,
        ];

        $response = $this->postJson('/api/risk-management/risks', $payload, $this->authHeaders());

        $response->assertCreated();
        $response->assertJsonPath('data.current_score', 25);
        $response->assertJsonPath('data.current_level', RiskLevel::Critical->value);
        $response->assertJsonPath('data.status', RiskStatus::Open->value);

        $this->assertDatabaseHas('risks', [
            'organization_id' => $this->org->id,
            'code' => 'RSK-'.date('Y').'-0001',
            'title' => $payload['title'],
        ]);
    }

    public function test_admin_can_reassess_and_escalate_level(): void
    {
        Notification::fake();
        $risk = Risk::factory()->forOrganization($this->org)->create([
            'initial_likelihood' => 1,
            'initial_impact' => 1,
            'current_likelihood' => 1,
            'current_impact' => 1,
            'current_score' => 1,
            'current_level' => RiskLevel::Low->value,
            'owner_id' => $this->admin->id,
        ]);

        $response = $this->postJson("/api/risk-management/risks/{$risk->id}/assessments", [
            'likelihood' => 5,
            'impact' => 5,
            'next_review_at' => now()->addMonth()->toDateString(),
        ], $this->authHeaders());

        $response->assertCreated();
        $this->assertSame(RiskLevel::Critical->value, $risk->fresh()->current_level);
        Notification::assertSentTo($this->admin, RiskLevelEscalatedNotification::class);
    }

    public function test_super_admin_without_org_can_create_global_risk(): void
    {
        $superAdmin = User::factory()->create([
            'organization_id' => null,
            'is_active' => true,
        ]);
        $superAdmin->assignRole('super_admin');

        $payload = [
            'title' => 'خطر عام على مستوى المنصة',
            'discovery_date' => now()->toDateString(),
            'type' => RiskType::Operational->value,
            'initial_likelihood' => 2,
            'initial_impact' => 2,
            'response_type' => RiskResponseType::Mitigate->value,
        ];

        $this->postJson('/api/risk-management/risks', $payload, $this->authHeaders($superAdmin))
            ->assertCreated();

        $this->assertDatabaseHas('risks', [
            'title' => $payload['title'],
            'organization_id' => null,
        ]);
    }

    public function test_super_admin_without_org_can_reassess_org_risk(): void
    {
        $superAdmin = User::factory()->create([
            'organization_id' => null,
            'is_active' => true,
        ]);
        $superAdmin->assignRole('super_admin');

        $risk = Risk::factory()->forOrganization($this->org)->create();

        $this->postJson("/api/risk-management/risks/{$risk->id}/assessments", [
            'likelihood' => 4,
            'impact' => 4,
            'next_review_at' => now()->addMonth()->toDateString(),
        ], $this->authHeaders($superAdmin))
            ->assertCreated();
    }

    public function test_viewer_cannot_create_risk(): void
    {
        $payload = [
            'title' => 'محاولة',
            'discovery_date' => now()->toDateString(),
            'type' => RiskType::Operational->value,
            'initial_likelihood' => 2,
            'initial_impact' => 2,
            'response_type' => RiskResponseType::Mitigate->value,
        ];

        $this->postJson('/api/risk-management/risks', $payload, $this->authHeaders($this->viewer))
            ->assertForbidden();
    }

    // ===== A15: GET /risks/{risk}/assessments + /status-changes =====

    public function test_unauthenticated_cannot_list_assessments(): void
    {
        $risk = Risk::factory()->forOrganization($this->org)->create();

        $this->getJson("/api/risk-management/risks/{$risk->id}/assessments")
            ->assertStatus(401);
    }

    public function test_admin_can_list_assessments(): void
    {
        $risk = Risk::factory()->forOrganization($this->org)->create([
            'owner_id' => $this->admin->id,
        ]);
        RiskAssessment::create([
            'risk_id' => $risk->id,
            'organization_id' => $this->org->id,
            'likelihood' => 3,
            'impact' => 4,
            'score' => 12,
            'level' => RiskLevel::Medium->value,
            'assessor_id' => $this->admin->id,
            'notes' => 'Initial assessment',
        ]);
        RiskAssessment::create([
            'risk_id' => $risk->id,
            'organization_id' => $this->org->id,
            'likelihood' => 5,
            'impact' => 5,
            'score' => 25,
            'level' => RiskLevel::Critical->value,
            'assessor_id' => $this->admin->id,
            'notes' => 'Escalated',
        ]);

        $response = $this->getJson(
            "/api/risk-management/risks/{$risk->id}/assessments",
            $this->authHeaders(),
        );

        $response->assertOk();
        $body = $response->json();
        $this->assertIsArray($body);
        $this->assertCount(2, $body);
        $this->assertEqualsCanonicalizing(
            [3, 5],
            array_column($body, 'likelihood'),
        );
    }

    public function test_viewer_can_list_assessments(): void
    {
        // Viewer holds risks.view (verified in the seeder), so a read on the
        // assessments index MUST succeed.
        $risk = Risk::factory()->forOrganization($this->org)->create();
        RiskAssessment::create([
            'risk_id' => $risk->id,
            'organization_id' => $this->org->id,
            'likelihood' => 2,
            'impact' => 2,
            'score' => 4,
            'level' => RiskLevel::Low->value,
            'assessor_id' => $this->admin->id,
        ]);

        $this->getJson(
            "/api/risk-management/risks/{$risk->id}/assessments",
            $this->authHeaders($this->viewer),
        )->assertOk();
    }

    public function test_user_without_view_capability_cannot_list_assessments(): void
    {
        // A user with NO risks.view capability (a fresh member with no role)
        // must NOT be able to read the assessments log.
        $risk = Risk::factory()->forOrganization($this->org)->create();
        $noCap = User::factory()->create([
            'organization_id' => $this->org->id,
            'is_active' => true,
        ]);

        $this->getJson(
            "/api/risk-management/risks/{$risk->id}/assessments",
            $this->authHeaders($noCap),
        )->assertStatus(403);
    }

    public function test_cross_org_actor_cannot_list_assessments_of_foreign_risk(): void
    {
        $foreignRisk = Risk::factory()->forOrganization($this->otherOrg)->create([
            'owner_id' => $this->admin->id,
        ]);
        RiskAssessment::create([
            'risk_id' => $foreignRisk->id,
            'organization_id' => $this->otherOrg->id,
            'likelihood' => 3,
            'impact' => 3,
            'score' => 9,
            'level' => RiskLevel::Medium->value,
            'assessor_id' => $this->admin->id,
        ]);

        // Admin of org-A tries to read org-B risk's assessments. Even though
        // the admin role grants risks.view, the org-isolation gate must fire
        // first (assertSameOrganization) and deny the request. Accept either
        // 403 (policy denial) or 404 (hidden via scope filter).
        $status = $this->getJson(
            "/api/risk-management/risks/{$foreignRisk->id}/assessments",
            $this->authHeaders(),
        )->status();
        $this->assertContains($status, [403, 404], 'cross-org assessments read must be denied');
    }

    public function test_super_admin_can_list_assessments_of_any_org_risk(): void
    {
        $foreignRisk = Risk::factory()->forOrganization($this->otherOrg)->create([
            'owner_id' => $this->admin->id,
        ]);
        RiskAssessment::create([
            'risk_id' => $foreignRisk->id,
            'organization_id' => $this->otherOrg->id,
            'likelihood' => 4,
            'impact' => 4,
            'score' => 16,
            'level' => RiskLevel::High->value,
            'assessor_id' => $this->admin->id,
        ]);

        $superAdmin = User::factory()->create([
            'organization_id' => null,
            'is_active' => true,
        ]);
        $superAdmin->assignRole('super_admin');

        $this->getJson(
            "/api/risk-management/risks/{$foreignRisk->id}/assessments",
            $this->authHeaders($superAdmin),
        )->assertOk();
    }

    public function test_unauthenticated_cannot_list_status_changes(): void
    {
        $risk = Risk::factory()->forOrganization($this->org)->create();

        $this->getJson("/api/risk-management/risks/{$risk->id}/status-changes")
            ->assertStatus(401);
    }

    public function test_admin_can_list_status_changes(): void
    {
        $risk = Risk::factory()->forOrganization($this->org)->create([
            'status' => RiskStatus::Open,
            'owner_id' => $this->admin->id,
        ]);
        RiskStatusChange::create([
            'risk_id' => $risk->id,
            'organization_id' => $this->org->id,
            'from_status' => RiskStatus::Open->value,
            'to_status' => RiskStatus::Treating->value,
            'changed_by' => $this->admin->id,
            'reason' => 'Treatment plan approved',
        ]);
        RiskStatusChange::create([
            'risk_id' => $risk->id,
            'organization_id' => $this->org->id,
            'from_status' => RiskStatus::Open->value,
            'to_status' => RiskStatus::Accepted->value,
            'changed_by' => $this->admin->id,
            'reason' => 'Risk accepted by committee',
        ]);

        $response = $this->getJson(
            "/api/risk-management/risks/{$risk->id}/status-changes",
            $this->authHeaders(),
        );

        $response->assertOk();
        $body = $response->json();
        $this->assertIsArray($body);
        $this->assertCount(2, $body);
        $this->assertEqualsCanonicalizing(
            [
                RiskStatus::Treating->value,
                RiskStatus::Accepted->value,
            ],
            array_column($body, 'to_status'),
        );
    }

    public function test_viewer_can_list_status_changes(): void
    {
        // Viewer holds risks.view, which is what statusHistory uses.
        $risk = Risk::factory()->forOrganization($this->org)->create();
        RiskStatusChange::create([
            'risk_id' => $risk->id,
            'organization_id' => $this->org->id,
            'from_status' => RiskStatus::Open->value,
            'to_status' => RiskStatus::Treating->value,
            'changed_by' => $this->admin->id,
        ]);

        $this->getJson(
            "/api/risk-management/risks/{$risk->id}/status-changes",
            $this->authHeaders($this->viewer),
        )->assertOk();
    }

    public function test_user_without_view_capability_cannot_list_status_changes(): void
    {
        $risk = Risk::factory()->forOrganization($this->org)->create();
        $noCap = User::factory()->create([
            'organization_id' => $this->org->id,
            'is_active' => true,
        ]);

        $this->getJson(
            "/api/risk-management/risks/{$risk->id}/status-changes",
            $this->authHeaders($noCap),
        )->assertStatus(403);
    }

    public function test_cross_org_actor_cannot_list_status_changes_of_foreign_risk(): void
    {
        $foreignRisk = Risk::factory()->forOrganization($this->otherOrg)->create([
            'owner_id' => $this->admin->id,
        ]);
        RiskStatusChange::create([
            'risk_id' => $foreignRisk->id,
            'organization_id' => $this->otherOrg->id,
            'from_status' => RiskStatus::Open->value,
            'to_status' => RiskStatus::Treating->value,
            'changed_by' => $this->admin->id,
        ]);

        $status = $this->getJson(
            "/api/risk-management/risks/{$foreignRisk->id}/status-changes",
            $this->authHeaders(),
        )->status();
        $this->assertContains($status, [403, 404], 'cross-org status-changes read must be denied');
    }
}
