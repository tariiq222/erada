<?php

namespace Tests\Feature\RiskManagement;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\RiskManagement\Enums\RiskResponseType;
use App\Modules\RiskManagement\Enums\RiskStatus;
use App\Modules\RiskManagement\Enums\RiskType;
use App\Modules\RiskManagement\Models\Risk;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RiskCreateFormTest extends TestCase
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

    public function test_get_risks_create_returns_form_schema_for_authorized_user(): void
    {
        $response = $this->getJson('/api/risk-management/risks/create', $this->authHeaders());

        $response->assertOk();

        $response->assertJsonStructure([
            'data' => [
                'types',
                'response_types',
                'likelihood_scale',
                'impact_scale',
                'statuses',
                'default_status',
            ],
        ]);

        $response->assertJsonPath('data.default_status', RiskStatus::Open->value);

        $typeValues = collect($response->json('data.types'))->pluck('value')->all();
        $this->assertEqualsCanonicalizing(
            collect(RiskType::cases())->map(fn ($t) => $t->value)->all(),
            $typeValues,
        );

        $responseTypeValues = collect($response->json('data.response_types'))->pluck('value')->all();
        $this->assertEqualsCanonicalizing(
            collect(RiskResponseType::cases())->map(fn ($t) => $t->value)->all(),
            $responseTypeValues,
        );

        $this->assertCount(5, $response->json('data.likelihood_scale'));
        $this->assertCount(5, $response->json('data.impact_scale'));
    }

    public function test_get_risks_create_does_not_treat_create_as_risk_id(): void
    {
        $response = $this->getJson('/api/risk-management/risks/create', $this->authHeaders());

        $response->assertOk();

        $this->assertDatabaseCount('risks', 0);
    }

    public function test_get_risks_create_requires_authentication(): void
    {
        $this->getJson('/api/risk-management/risks/create')
            ->assertUnauthorized();
    }

    public function test_get_risks_create_forbids_user_without_create_permission(): void
    {
        $this->getJson('/api/risk-management/risks/create', $this->authHeaders($this->viewer))
            ->assertForbidden();
    }

    public function test_numeric_risk_show_route_still_works(): void
    {
        $risk = Risk::factory()->forOrganization($this->org)->create([
            'title' => 'انقطاع التيار الكهربائي',
            'owner_id' => $this->admin->id,
        ]);

        $this->getJson("/api/risk-management/risks/{$risk->id}", $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('data.id', $risk->id);
    }
}
