<?php

namespace Tests\Feature\RiskManagement;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\RiskManagement\Enums\RiskActionStatus;
use App\Modules\RiskManagement\Enums\RiskActionType;
use App\Modules\RiskManagement\Models\Risk;
use App\Modules\RiskManagement\Models\RiskAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RiskActionControllerTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Department $department;

    private User $riskEditor;

    private User $actionOwner;

    private Risk $risk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->department = Department::factory()->create([
            'organization_id' => $this->organization->id,
        ]);
        $this->riskEditor = User::factory()->create([
            'organization_id' => $this->organization->id,
            'department_id' => $this->department->id,
        ]);
        // Engine-only path (Wave 3 task 8): the 'admin' Spatie role maps to
        // a ScopedRoleDefinition with is_admin_role=true (created by the
        // backfill migration), which grants every capability via the engine.
        // The legacy Spatie delete_risks fallback has been removed.
        $this->riskEditor->assignRole('admin');

        $this->actionOwner = User::factory()->create([
            'organization_id' => $this->organization->id,
            'department_id' => $this->department->id,
        ]);

        $this->risk = Risk::factory()->create([
            'organization_id' => $this->organization->id,
            'department_id' => $this->department->id,
            'owner_id' => $this->riskEditor->id,
            'created_by' => $this->riskEditor->id,
        ]);
    }

    public function test_store_creates_action_scoped_to_risk_organization_with_owner_structure(): void
    {
        $foreignOrganization = Organization::factory()->create();

        $response = $this->actingAs($this->riskEditor, 'sanctum')
            ->postJson("/api/risk-management/risks/{$this->risk->id}/actions", [
                'title' => 'Mitigate supplier delay',
                'type' => RiskActionType::Preventive->value,
                'description' => 'Follow up with supplier weekly',
                'owner_id' => $this->actionOwner->id,
                'organization_id' => $foreignOrganization->id,
                'due_date' => now()->addDays(5)->toDateString(),
                'status' => RiskActionStatus::InProgress->value,
            ]);

        $response->assertCreated()
            ->assertJsonStructure([
                'message',
                'data' => [
                    'id',
                    'risk_id',
                    'title',
                    'type',
                    'status',
                    'owner' => ['id', 'name'],
                ],
            ])
            ->assertJsonPath('message', 'تم إنشاء الإجراء بنجاح')
            ->assertJsonPath('data.title', 'Mitigate supplier delay')
            ->assertJsonPath('data.owner.id', $this->actionOwner->id);

        $this->assertDatabaseHas('risk_actions', [
            'risk_id' => $this->risk->id,
            'organization_id' => $this->organization->id,
            'title' => 'Mitigate supplier delay',
            'owner_id' => $this->actionOwner->id,
            'status' => RiskActionStatus::InProgress->value,
        ]);
    }

    public function test_store_rejects_owner_from_another_organization(): void
    {
        $outsideOwner = User::factory()->create();

        $this->actingAs($this->riskEditor, 'sanctum')
            ->postJson("/api/risk-management/risks/{$this->risk->id}/actions", [
                'title' => 'Invalid owner action',
                'type' => RiskActionType::Corrective->value,
                'owner_id' => $outsideOwner->id,
                'due_date' => now()->addDay()->toDateString(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['owner_id']);
    }

    public function test_show_update_add_update_list_and_destroy_action_lifecycle(): void
    {
        $action = RiskAction::factory()->create([
            'risk_id' => $this->risk->id,
            'organization_id' => $this->organization->id,
            'owner_id' => $this->actionOwner->id,
            'status' => RiskActionStatus::Pending->value,
            'progress_pct' => 0,
        ]);

        $this->actingAs($this->riskEditor, 'sanctum')
            ->getJson("/api/risk-management/actions/{$action->id}")
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'risk_id',
                    'title',
                    'status',
                    'owner' => ['id', 'name'],
                    'updates',
                ],
            ])
            ->assertJsonPath('data.id', $action->id);

        $this->actingAs($this->riskEditor, 'sanctum')
            ->patchJson("/api/risk-management/actions/{$action->id}", [
                'title' => 'Updated mitigation action',
                'status' => RiskActionStatus::InProgress->value,
                'progress_pct' => 30,
            ])
            ->assertOk()
            ->assertJsonPath('message', 'تم تحديث الإجراء بنجاح')
            ->assertJsonPath('data.title', 'Updated mitigation action')
            ->assertJsonPath('data.progress_pct', 30);

        $this->actingAs($this->riskEditor, 'sanctum')
            ->postJson("/api/risk-management/actions/{$action->id}/updates", [
                'progress_pct' => 80,
                'status' => RiskActionStatus::Completed->value,
                'notes' => 'Mitigation completed and validated.',
            ])
            ->assertCreated()
            ->assertJsonStructure([
                'message',
                'data' => ['id', 'risk_action_id', 'progress_pct', 'status', 'notes', 'user' => ['id', 'name']],
            ])
            ->assertJsonPath('message', 'تم تسجيل التحديث بنجاح')
            ->assertJsonPath('data.progress_pct', 80)
            ->assertJsonPath('data.status', RiskActionStatus::Completed->value);

        $this->assertDatabaseHas('risk_actions', [
            'id' => $action->id,
            'status' => RiskActionStatus::Completed->value,
            'progress_pct' => 80,
        ]);

        $this->actingAs($this->riskEditor, 'sanctum')
            ->getJson("/api/risk-management/actions/{$action->id}/updates")
            ->assertOk()
            ->assertJsonStructure([
                '*' => ['id', 'risk_action_id', 'progress_pct', 'status', 'notes', 'user' => ['id', 'name']],
            ])
            ->assertJsonPath('0.notes', 'Mitigation completed and validated.');

        $this->actingAs($this->riskEditor, 'sanctum')
            ->deleteJson("/api/risk-management/actions/{$action->id}")
            ->assertOk()
            ->assertJson(['message' => 'تم حذف الإجراء بنجاح']);

        $this->assertDatabaseMissing('risk_actions', ['id' => $action->id]);
    }

    public function test_cross_organization_action_access_is_forbidden(): void
    {
        $outsideAction = RiskAction::factory()->create();

        $this->actingAs($this->riskEditor, 'sanctum')
            ->getJson("/api/risk-management/actions/{$outsideAction->id}")
            ->assertForbidden();
    }

    public function test_action_write_requires_engine_risks_edit_capability(): void
    {
        // Engine-only path: a viewer without Capability::RISKS_EDIT cannot
        // POST a new action even though they may hold the legacy view_risks
        // permission. Engine gate wins; the legacy Spatie fallback has been
        // removed (Wave 3 task 8).
        $viewer = User::factory()->create([
            'organization_id' => $this->organization->id,
            'department_id' => $this->department->id,
        ]);

        $this->actingAs($viewer, 'sanctum')
            ->postJson("/api/risk-management/risks/{$this->risk->id}/actions", [
                'title' => 'Not allowed',
                'type' => RiskActionType::Preventive->value,
            ])
            ->assertForbidden();
    }
}
