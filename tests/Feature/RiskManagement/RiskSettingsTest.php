<?php

namespace Tests\Feature\RiskManagement;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\RiskManagement\Enums\RiskType;
use App\Modules\RiskManagement\Models\Risk;
use App\Modules\RiskManagement\Models\RiskImpactType;
use App\Modules\RiskManagement\Models\RiskType as RiskTypeModel;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RiskSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $org;

    protected User $admin;

    protected User $viewer;

    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->org = Organization::factory()->create();
        $this->admin = User::factory()->create([
            'organization_id' => $this->org->id,
            'is_active' => true,
        ]);
        $this->grantCanonicalAdmin($this->admin);

        $this->viewer = User::factory()->create([
            'organization_id' => $this->org->id,
            'is_active' => true,
        ]);
        $this->grantCanonicalViewer($this->viewer);

        $this->token = $this->admin->createToken('test')->plainTextToken;
    }

    private function authHeaders(?User $user = null): array
    {
        $token = $user ? $user->createToken('test')->plainTextToken : $this->token;

        return ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
    }

    public function test_admin_can_list_risk_settings_defaults(): void
    {
        $response = $this->getJson('/api/risk-management/settings', $this->authHeaders());

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'risk_types' => [
                        '*' => ['id', 'value', 'label', 'is_active', 'sort_order'],
                    ],
                    'impact_types' => [
                        '*' => ['id', 'value', 'label', 'is_active', 'sort_order'],
                    ],
                ],
            ]);

        $typeValues = collect($response->json('data.risk_types'))->pluck('value')->all();
        $this->assertEqualsCanonicalizing(
            collect(RiskType::cases())->map(fn (RiskType $type) => $type->value)->all(),
            $typeValues,
        );

        $impactTypeValues = collect($response->json('data.impact_types'))->pluck('value')->all();
        $this->assertEqualsCanonicalizing([
            'workflow',
            'employees',
            'patients',
            'safety',
            'quality',
            'financial',
            'reputation',
            'compliance',
            'technology',
        ], $impactTypeValues);

        $this->assertNotContains('1', $impactTypeValues);
    }

    public function test_viewer_cannot_manage_risk_settings(): void
    {
        $this->getJson('/api/risk-management/settings', $this->authHeaders($this->viewer))
            ->assertForbidden();
    }

    public function test_viewer_cannot_create_risk_type(): void
    {
        $this->postJson('/api/risk-management/settings/risk-types', [
            'value' => 'cybersecurity',
            'label' => 'أمن سيبراني',
        ], $this->authHeaders($this->viewer))->assertForbidden();

        $this->assertDatabaseMissing('risk_types', ['value' => 'cybersecurity']);
    }

    public function test_custom_risk_type_can_be_created_updated_and_deleted_when_unused(): void
    {
        $created = $this->postJson('/api/risk-management/settings/risk-types', [
            'value' => 'cybersecurity',
            'label' => 'أمن سيبراني',
            'is_active' => true,
            'sort_order' => 20,
        ], $this->authHeaders());

        $created->assertCreated()
            ->assertJsonPath('data.value', 'cybersecurity')
            ->assertJsonPath('data.label', 'أمن سيبراني');
        $this->assertDatabaseHas('risk_types', ['value' => 'cybersecurity']);

        $id = $created->json('data.id');

        // Duplicate value is rejected.
        $this->postJson('/api/risk-management/settings/risk-types', [
            'value' => 'cybersecurity',
            'label' => 'مكرر',
        ], $this->authHeaders())->assertUnprocessable()->assertJsonValidationErrors('value');

        // Update label/value of an unused type succeeds.
        $this->patchJson('/api/risk-management/settings/risk-types/'.$id, [
            'value' => 'cyber_security',
            'label' => 'أمن المعلومات',
            'sort_order' => 5,
        ], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('data.value', 'cyber_security')
            ->assertJsonPath('data.label', 'أمن المعلومات');

        // Deleting an unused type succeeds.
        $this->deleteJson('/api/risk-management/settings/risk-types/'.$id, [], $this->authHeaders())
            ->assertOk();
        $this->assertDatabaseMissing('risk_types', ['id' => $id]);
    }

    public function test_risk_type_in_use_cannot_be_revalued_or_deleted(): void
    {
        $operationalId = RiskTypeModel::query()->where('value', RiskType::Operational->value)->value('id');
        $this->assertNotNull($operationalId);

        Risk::factory()->forOrganization($this->org)->create([
            'type' => RiskType::Operational->value,
        ]);

        // Changing the value of a used type is blocked.
        $this->patchJson('/api/risk-management/settings/risk-types/'.$operationalId, [
            'value' => 'operational_renamed',
        ], $this->authHeaders())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('value');

        // Deleting a used type is blocked.
        $this->deleteJson('/api/risk-management/settings/risk-types/'.$operationalId, [], $this->authHeaders())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('risk_type');

        $this->assertDatabaseHas('risk_types', ['id' => $operationalId]);
    }

    public function test_custom_impact_type_can_be_created_updated_and_deleted_when_unused(): void
    {
        $response = $this->postJson('/api/risk-management/settings/impact-types', [
            'value' => 'service_continuity',
            'label' => 'استمرارية الخدمة',
            'is_active' => true,
            'sort_order' => 20,
        ], $this->authHeaders());

        $response->assertCreated()
            ->assertJsonPath('data.value', 'service_continuity')
            ->assertJsonPath('data.label', 'استمرارية الخدمة');

        $id = $response->json('data.id');

        $this->patchJson('/api/risk-management/settings/impact-types/'.$id, [
            'label' => 'تعطل الخدمة',
            'is_active' => false,
            'sort_order' => 21,
        ], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('data.label', 'تعطل الخدمة')
            ->assertJsonPath('data.is_active', false);

        $this->deleteJson('/api/risk-management/settings/impact-types/'.$id, [], $this->authHeaders())
            ->assertOk();
        $this->assertDatabaseMissing('risk_impact_types', ['id' => $id]);
    }

    public function test_impact_type_in_use_cannot_be_revalued_or_deleted(): void
    {
        $workflowId = RiskImpactType::query()->where('value', 'workflow')->value('id');
        $this->assertNotNull($workflowId);

        $risk = Risk::factory()->forOrganization($this->org)->create([
            'type' => RiskType::Operational->value,
        ]);

        // impact_details is not a fillable/cast attribute on Risk, so set the raw
        // JSONB column directly to mark the 'workflow' impact type as in use.
        DB::table('risks')->where('id', $risk->id)->update([
            'impact_details' => json_encode([['type' => 'workflow', 'description' => 'x']]),
        ]);

        $this->patchJson('/api/risk-management/settings/impact-types/'.$workflowId, [
            'value' => 'workflow_renamed',
        ], $this->authHeaders())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('value');

        $this->deleteJson('/api/risk-management/settings/impact-types/'.$workflowId, [], $this->authHeaders())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('impact_type');

        $this->assertDatabaseHas('risk_impact_types', ['id' => $workflowId]);
    }
}
