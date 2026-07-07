<?php

namespace Tests\Feature\Core;

use App\Modules\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleControllerCatalogSlimTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @dataProvider deadLadderStrings
     */
    public function test_dead_ladder_string_not_in_catalog(string $legacy): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');
        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/roles/permissions');
        $response->assertStatus(200);
        $payload = $response->json();

        $emitted = collect($payload['data']['scoped'] ?? [])->flatMap(fn ($mod) => collect($mod['actions'] ?? [])->flatMap(fn ($act) => collect($act['scopes'] ?? [])->pluck('permission')->merge([$act['permission'] ?? null])
        )->filter()
        )->all();

        $this->assertNotContains($legacy, $emitted, "Dead ladder '$legacy' must NOT appear in catalog");
    }

    public static function deadLadderStrings(): array
    {
        return [
            ['view_own_projects'], ['view_department_projects'],
            ['view_own_tasks'], ['view_department_tasks'],
            ['ovr.view_own'], ['ovr.view_department'],
            ['ovr.edit_own'], ['ovr.delete_own'],
        ];
    }
}
