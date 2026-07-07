<?php

namespace Tests\Feature\Core;

use App\Modules\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SurveysCatalogSlimTest extends TestCase
{
    use RefreshDatabase;

    public function test_dead_surveys_strings_not_in_catalog(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');
        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/roles/permissions');
        $response->assertStatus(200);
        $payload = $response->json();

        $emitted = collect($payload['data']['scoped'] ?? [])->flatMap(fn ($mod) => collect($mod['actions'] ?? [])->flatMap(fn ($act) => collect($act['scopes'] ?? [])->pluck('permission')->merge([$act['permission'] ?? null])
        )->filter()
        )->all();

        foreach (['view_surveys', 'edit_surveys', 'create_surveys', 'delete_surveys'] as $dead) {
            $this->assertNotContains($dead, $emitted, "Dead Surveys '$dead' must NOT appear in catalog");
        }
    }
}
