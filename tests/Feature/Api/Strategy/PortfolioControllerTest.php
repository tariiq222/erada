<?php

namespace Tests\Feature\Api\Strategy;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Strategy\Models\Portfolio;
use App\Modules\Strategy\Models\Program;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

class PortfolioControllerTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    protected User $user;

    protected User $pmoUser;

    protected Department $department;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->department = Department::factory()->create();
        $organization = Organization::factory()->create();

        $this->user = User::factory()->create([
            'department_id' => $this->department->id,
            'organization_id' => $organization->id,
            'is_active' => true,
        ]);
        $this->grantCanonicalSuperAdmin($this->user);

        $this->pmoUser = User::factory()->create([
            'department_id' => $this->department->id,
            'organization_id' => $organization->id,
            'is_active' => true,
        ]);
        $this->grantCanonicalSuperAdmin($this->pmoUser);
    }

    // ========================================
    // اختبارات القراءة (GET)
    // ========================================

    public function test_can_list_portfolios(): void
    {
        Portfolio::factory()->count(5)->create();

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/strategy/portfolios');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data',
                'current_page',
                'last_page',
                'total',
            ]);
    }

    public function test_can_list_portfolios_with_search(): void
    {
        Portfolio::factory()->create(['name' => 'التزام تنفيذي خاص']);
        Portfolio::factory()->count(3)->create();

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/strategy/portfolios?search=خاص');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    public function test_can_filter_portfolios_by_status(): void
    {
        Portfolio::factory()->count(2)->create(['status' => 'active']);
        Portfolio::factory()->count(3)->create(['status' => 'draft']);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/strategy/portfolios?status=active');

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data'));
    }

    public function test_can_filter_portfolios_by_portfolio_status(): void
    {
        Portfolio::factory()->count(2)->create(['portfolio_status' => 'frozen']);
        Portfolio::factory()->count(3)->create(['portfolio_status' => 'active']);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/strategy/portfolios?portfolio_status=frozen');

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data'));
    }

    public function test_can_view_single_portfolio(): void
    {
        $portfolio = Portfolio::factory()->create();

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/strategy/portfolios/{$portfolio->id}");

        $response->assertStatus(200)
            ->assertJsonFragment(['id' => $portfolio->id]);
    }

    public function test_can_get_portfolios_list_for_dropdown(): void
    {
        Portfolio::factory()->active()->strategicallyActive()->count(3)->create();
        Portfolio::factory()->draft()->frozen()->count(2)->create();

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/strategy/portfolios/list');

        $response->assertStatus(200);
        $this->assertCount(3, $response->json());
    }

    public function test_can_get_portfolios_summary(): void
    {
        Portfolio::factory()->active()->count(3)->create();
        Portfolio::factory()->draft()->count(2)->create();

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/strategy/portfolios/summary');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'total',
                'active',
                'by_portfolio_status',
                'average_progress',
            ]);
    }

    // ========================================
    // اختبارات الإنشاء (POST)
    // ========================================

    public function test_can_create_portfolio(): void
    {
        $owner = User::factory()->create();

        $portfolioData = [
            'name' => 'التزام تنفيذي جديد',
            'description' => 'وصف الالتزام التنفيذي',
            'start_date' => now()->format('Y-m-d'),
            'end_date' => now()->addYear()->format('Y-m-d'),
            'status' => 'draft',
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/strategy/portfolios', $portfolioData);

        $response->assertStatus(201)
            ->assertJsonFragment(['name' => 'التزام تنفيذي جديد']);

        $this->assertDatabaseHas('portfolios', [
            'name' => 'التزام تنفيذي جديد',
        ]);
    }

    /**
     * اختبار أن المنشئ يُعيَّن تلقائياً للمستخدم الحالي إذا لم يُحدد
     */
    public function test_create_portfolio_assigns_current_user_as_creator_when_not_specified(): void
    {
        $portfolioData = [
            'name' => 'التزام تنفيذي نشط',
            'description' => 'وصف',
            'status' => 'active',
            'portfolio_status' => 'active',
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/strategy/portfolios', $portfolioData);

        // يجب أن ينجح الإنشاء مع تعيين المستخدم الحالي كمنشئ
        $response->assertStatus(201);

        $this->assertDatabaseHas('portfolios', [
            'name' => 'التزام تنفيذي نشط',
            'created_by' => $this->user->id,
        ]);
    }

    public function test_create_portfolio_generates_unique_code(): void
    {
        $owner = User::factory()->create();

        $response1 = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/strategy/portfolios', [
                'name' => 'الالتزام الأول',
                'status' => 'draft',
            ]);

        $response2 = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/strategy/portfolios', [
                'name' => 'الالتزام الثاني',
                'status' => 'draft',
            ]);

        $response1->assertStatus(201);
        $response2->assertStatus(201);

        $code1 = $response1->json('data.code');
        $code2 = $response2->json('data.code');

        $this->assertNotEquals($code1, $code2);
    }

    public function test_create_portfolio_validation(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/strategy/portfolios', [
                'name' => '', // مطلوب
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_create_portfolio_with_directive_source(): void
    {
        $owner = User::factory()->create();

        $portfolioData = [
            'name' => 'التزام مع جهة توجيه',
            'directive_source' => 'moh',
            'status' => 'draft',
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/strategy/portfolios', $portfolioData);

        $response->assertStatus(201);
        $this->assertDatabaseHas('portfolios', [
            'directive_source' => 'moh',
        ]);
    }

    public function test_create_portfolio_with_other_directive_source_requires_text(): void
    {
        $owner = User::factory()->create();

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/strategy/portfolios', [
                'name' => 'التزام مع جهة أخرى',
                'directive_source' => 'other',
                'status' => 'draft',
                // directive_source_other مفقود
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['directive_source_other']);
    }

    // ========================================
    // اختبارات التحديث (PUT)
    // ========================================

    public function test_can_update_portfolio(): void
    {
        $portfolio = Portfolio::factory()->create(['name' => 'اسم قديم']);
        $owner = User::factory()->create();

        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson("/api/strategy/portfolios/{$portfolio->id}", [
                'name' => 'اسم جديد',
                'status' => 'active',
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('portfolios', [
            'id' => $portfolio->id,
            'name' => 'اسم جديد',
        ]);
    }

    public function test_update_portfolio_strategic_status(): void
    {
        $owner = User::factory()->create();
        $portfolio = Portfolio::factory()->create([
            'portfolio_status' => 'active',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson("/api/strategy/portfolios/{$portfolio->id}/strategic-status", [
                'portfolio_status' => 'frozen',
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('portfolios', [
            'id' => $portfolio->id,
            'portfolio_status' => 'frozen',
        ]);
    }

    public function test_cannot_close_portfolio_with_active_programs(): void
    {
        $organization = Organization::factory()->create();

        // A non-admin strategy editor: can edit strategy (passes the update
        // gate) but does NOT hold strategy.change_status, so it cannot force-close
        // a portfolio that still has active programs. admin (is_admin_role) could,
        // which is why the denial case must be driven by a non-admin role.
        $regularUser = User::factory()->create([
            'department_id' => $this->department->id,
            'organization_id' => $organization->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability(
            $regularUser,
            [Capability::STRATEGY_VIEW, Capability::STRATEGY_EDIT],
            'organization',
            $organization->id,
            'strategy_editor',
        );

        $owner = User::factory()->create(['organization_id' => $organization->id]);
        $portfolio = Portfolio::factory()->create([
            'portfolio_status' => 'active',
            'organization_id' => $organization->id,
        ]);

        // إنشاء برنامج نشط
        Program::factory()->create([
            'portfolio_id' => $portfolio->id,
            'organization_id' => $organization->id,
            'status' => 'in_progress',
        ]);

        $response = $this->actingAs($regularUser, 'sanctum')
            ->putJson("/api/strategy/portfolios/{$portfolio->id}/strategic-status", [
                'portfolio_status' => 'closed_strategically',
            ]);

        $response->assertStatus(422);
    }

    public function test_can_update_portfolio_priority(): void
    {
        $portfolio = Portfolio::factory()->create([
            'priority_rank' => 5,
            'weight' => 10,
        ]);

        $response = $this->actingAs($this->pmoUser, 'sanctum')
            ->putJson("/api/strategy/portfolios/{$portfolio->id}/priority", [
                'priority_rank' => 10,
                'weight' => 25,
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('portfolios', [
            'id' => $portfolio->id,
            'priority_rank' => 10,
        ]);
    }

    // ========================================
    // اختبارات الحذف (DELETE)
    // ========================================

    public function test_can_delete_portfolio_without_programs(): void
    {
        $portfolio = Portfolio::factory()->create();

        $response = $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/strategy/portfolios/{$portfolio->id}");

        $response->assertStatus(200);
        $this->assertSoftDeleted('portfolios', ['id' => $portfolio->id]);
    }

    public function test_cannot_delete_portfolio_with_programs(): void
    {
        $portfolio = Portfolio::factory()->create();
        Program::factory()->create(['portfolio_id' => $portfolio->id]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/strategy/portfolios/{$portfolio->id}");

        $response->assertStatus(422);
        $this->assertDatabaseHas('portfolios', ['id' => $portfolio->id]);
    }

    // ========================================
    // اختبارات الأمان
    // ========================================

    public function test_unauthenticated_cannot_access_portfolios(): void
    {
        $response = $this->getJson('/api/strategy/portfolios');
        $response->assertStatus(401);
    }

    public function test_unauthenticated_cannot_create_portfolio(): void
    {
        $response = $this->postJson('/api/strategy/portfolios', [
            'name' => 'اختبار',
        ]);
        $response->assertStatus(401);
    }

    // ========================================
    // اختبارات الـ Model
    // ========================================

    public function test_portfolio_calculates_progress_from_programs(): void
    {
        $portfolio = Portfolio::factory()->create();

        Program::factory()->create([
            'portfolio_id' => $portfolio->id,
            'progress' => 50,
            'weight' => 1,
            'status' => 'in_progress',
        ]);

        Program::factory()->create([
            'portfolio_id' => $portfolio->id,
            'progress' => 100,
            'weight' => 1,
            'status' => 'completed',
        ]);

        $progress = $portfolio->calculateProgress();

        $this->assertEquals(75, $progress);
    }

    public function test_portfolio_status_labels(): void
    {
        $portfolio = Portfolio::factory()->create([
            'status' => 'active',
            'portfolio_status' => 'frozen',
        ]);

        $this->assertEquals('نشط', $portfolio->status_label);
        $this->assertEquals('مجمدة', $portfolio->portfolio_status_label);
    }

    public function test_portfolio_scopes(): void
    {
        Portfolio::factory()->active()->count(2)->create();
        Portfolio::factory()->draft()->count(3)->create();

        $this->assertCount(2, Portfolio::active()->get());
        $this->assertCount(3, Portfolio::draft()->get());
    }

    public function test_portfolio_has_programs_relationship(): void
    {
        $portfolio = Portfolio::factory()->create();
        Program::factory()->count(3)->create(['portfolio_id' => $portfolio->id]);

        $this->assertCount(3, $portfolio->programs);
    }

    // ============================================================
    // Task 3.7 — Strategy force-close + strategic-status logging
    // ============================================================

    public function test_force_close_via_strategic_status_endpoint_writes_decision_log(): void
    {
        // Portfolio with an ACTIVE program — this triggers the force-close
        // path through PortfolioDecisionService::logForceCloseDecision when the
        // actor holds STRATEGY_CHANGE_STATUS.
        $portfolio = Portfolio::factory()->create([
            'portfolio_status' => 'active',
        ]);
        Program::factory()->create([
            'portfolio_id' => $portfolio->id,
            'organization_id' => $portfolio->organization_id,
            'status' => 'in_progress',
        ]);

        // Use super_admin (this test user is super_admin in setUp) — they
        // bypass the gate via the engine's super-admin floor in
        // canForceClosePortfolio().
        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson("/api/strategy/portfolios/{$portfolio->id}/strategic-status", [
                'portfolio_status' => Portfolio::PORTFOLIO_STATUS_CLOSED,
                'decision_note' => 'Force-closing with active programs',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.portfolio_status', Portfolio::PORTFOLIO_STATUS_CLOSED);

        $portfolio->refresh();
        $this->assertSame(Portfolio::PORTFOLIO_STATUS_CLOSED, $portfolio->portfolio_status);

        // Decision log row was written.
        $this->assertDatabaseHas('activity_logs', [
            'loggable_type' => Portfolio::class,
            'loggable_id' => $portfolio->id,
            'action' => 'portfolio_force_closed',
            'user_id' => $this->user->id,
        ]);
    }

    public function test_strategic_status_change_via_strategic_status_endpoint_writes_decision_log(): void
    {
        // Portfolio without active programs → regular status change (not
        // force-close) → logStrategicStatusChange path.
        $portfolio = Portfolio::factory()->create([
            'portfolio_status' => 'active',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson("/api/strategy/portfolios/{$portfolio->id}/strategic-status", [
                'portfolio_status' => 'frozen',
                'decision_note' => 'Going dark for budget review',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.portfolio_status', 'frozen');

        $portfolio->refresh();
        $this->assertSame('frozen', $portfolio->portfolio_status);

        $this->assertDatabaseHas('activity_logs', [
            'loggable_type' => Portfolio::class,
            'loggable_id' => $portfolio->id,
            'action' => 'portfolio_strategic_status_changed',
            'user_id' => $this->user->id,
        ]);
    }

    public function test_force_close_via_generic_put_endpoint_writes_decision_log(): void
    {
        // The same force-close code path is reachable from PUT /portfolios/{id}
        // when portfolio_status=closed_strategically in the body and active
        // programs exist (UpdatePortfolioRequest does not validate portfolio_status).
        $portfolio = Portfolio::factory()->create([
            'portfolio_status' => 'active',
        ]);
        Program::factory()->create([
            'portfolio_id' => $portfolio->id,
            'organization_id' => $portfolio->organization_id,
            'status' => 'in_progress',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson("/api/strategy/portfolios/{$portfolio->id}", [
                'name' => $portfolio->name,
                'portfolio_status' => Portfolio::PORTFOLIO_STATUS_CLOSED,
                'status' => 'active',
                'decision_note' => 'Force-closing via generic PUT',
            ]);

        $response->assertStatus(200);

        $portfolio->refresh();
        $this->assertSame(Portfolio::PORTFOLIO_STATUS_CLOSED, $portfolio->portfolio_status);

        $this->assertDatabaseHas('activity_logs', [
            'loggable_type' => Portfolio::class,
            'loggable_id' => $portfolio->id,
            'action' => 'portfolio_force_closed',
            'user_id' => $this->user->id,
        ]);
    }
}
