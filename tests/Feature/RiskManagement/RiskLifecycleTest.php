<?php

namespace Tests\Feature\RiskManagement;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\RiskManagement\Enums\RiskStatus;
use App\Modules\RiskManagement\Models\Risk;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RiskLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_transition_creates_audit_row(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $this->grantCanonicalAdmin($user);

        $risk = Risk::factory()->forOrganization($org)->create([
            'status' => RiskStatus::Open->value,
            'owner_id' => $user->id,
        ]);

        $headers = [
            'Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken,
            'Accept' => 'application/json',
        ];

        $this->postJson("/api/risk-management/risks/{$risk->id}/status-changes", [
            'to_status' => RiskStatus::Treating->value,
            'reason' => 'بدء خطة التخفيف',
        ], $headers)->assertCreated();

        $this->assertDatabaseHas('risk_status_changes', [
            'risk_id' => $risk->id,
            'from_status' => RiskStatus::Open->value,
            'to_status' => RiskStatus::Treating->value,
            'changed_by' => $user->id,
        ]);
        $this->assertSame(RiskStatus::Treating, $risk->fresh()->status);
    }

    public function test_illegal_status_transition_is_rejected(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $this->grantCanonicalAdmin($user);

        // Closed -> Open is allowed, but Open -> Accepted is NOT in our state
        // machine (only Open->Treating/Accepted/Closed). We use a transition
        // that the state machine forbids: Accepted -> Closed is forbidden.
        $risk = Risk::factory()->forOrganization($org)->create([
            'status' => RiskStatus::Accepted->value,
            'owner_id' => $user->id,
        ]);

        $headers = [
            'Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken,
            'Accept' => 'application/json',
        ];

        // Accepted -> Open IS allowed; check a forbidden one:
        // We force risk into Treating so Treating->Closed test isn't a forbidden edge.
        // Better: Closed->Treating is forbidden.
        $risk->forceFill(['status' => RiskStatus::Closed->value])->save();

        $this->postJson("/api/risk-management/risks/{$risk->id}/status-changes", [
            'to_status' => RiskStatus::Treating->value,
        ], $headers)->assertStatus(422);
    }
}
