<?php

namespace Tests\Feature\Api;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicDepartmentsEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_request_still_works(): void
    {
        $organization = Organization::factory()->create();
        $department = Department::factory()->create([
            'level' => 3,
            'organization_id' => $organization->id,
        ]);
        $user = User::factory()->create([
            'department_id' => $department->id,
            'organization_id' => $organization->id,
            'is_active' => true,
        ]);
        $this->assignCanonicalRole($user, 'admin');

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/hr/departments/list');

        $response->assertStatus(200);
        $this->assertIsArray($response->json());
    }
}
