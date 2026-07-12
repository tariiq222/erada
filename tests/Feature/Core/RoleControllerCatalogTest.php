<?php

namespace Tests\Feature\Core;

use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleControllerCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $department = Department::factory()->create();

        $this->superAdmin = User::factory()->create([
            'department_id' => $department->id,
            'is_active' => true,
        ]);
        $this->grantCanonicalSuperAdmin($this->superAdmin);
    }

    /**
     * Pluck every permission string the legacy `/api/roles/permissions` catalog emits,
     * across BOTH the `scoped` (resource actions) and `flat` (toggle groups) branches.
     *
     * @return array<int, string>
     */
    private function emittedPermissions(array $payload): array
    {
        $scoped = $payload['data']['scoped'] ?? [];
        $flat = $payload['data']['flat'] ?? [];

        $fromScoped = collect($scoped)->flatMap(fn ($mod) => collect($mod['actions'] ?? [])->flatMap(fn ($act) => collect($act['scopes'] ?? [])->pluck('permission')
            ->merge([$act['permission'] ?? null])
        )->filter()
        )->all();

        $fromFlat = collect($flat)->flatMap(fn ($group) => collect($group['permissions'] ?? [])->pluck('name')
        )->all();

        return array_merge($fromScoped, $fromFlat);
    }

    /**
     * @dataProvider legacyPermissionStrings
     */
    public function test_legacy_string_not_in_catalog(string $legacy): void
    {
        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson('/api/roles/permissions');

        $response->assertStatus(200);
        $emitted = $this->emittedPermissions($response->json());

        $this->assertNotContains(
            $legacy,
            $emitted,
            "Legacy permission '$legacy' must NOT appear in the /api/roles/permissions catalog"
        );
    }

    public static function legacyPermissionStrings(): array
    {
        return [
            'view_hr' => ['view_hr'],
            'manage_hr' => ['manage_hr'],
            'view_kpis' => ['view_kpis'],
            'manage_kpis' => ['manage_kpis'],
            'view_risks' => ['view_risks'],
            'create_risks' => ['create_risks'],
            'edit_risks' => ['edit_risks'],
            'delete_risks' => ['delete_risks'],
            'reassess_risks' => ['reassess_risks'],
            'change_risk_status' => ['change_risk_status'],
            'view_risk_reports' => ['view_risk_reports'],
            'view_ovr_categories' => ['view_ovr_categories'],
            'manage_ovr_categories' => ['manage_ovr_categories'],
            'ovr.manage_types' => ['ovr.manage_types'],
            'ovr.delete_all' => ['ovr.delete_all'],
            'manage_organization' => ['manage_organization'],
            'view_settings' => ['view_settings'],
            'edit_settings' => ['edit_settings'],
        ];
    }
}
