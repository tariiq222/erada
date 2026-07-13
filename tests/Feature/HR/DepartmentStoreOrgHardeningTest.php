<?php

namespace Tests\Feature\HR;

use App\Modules\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DepartmentStoreOrgHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_without_organization_cannot_store_department_without_explicit_organization(): void
    {
        $user = User::factory()->create([
            'organization_id' => null,
            'is_active' => true,
        ]);
        $this->grantCanonicalSuperAdmin($user);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/admin/departments', [
                'name' => 'HQ',
                'level' => 1,
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['organization_id'])
            ->assertExactJson([
                'message' => 'حقل المنظمة مطلوب.',
                'errors' => [
                    'organization_id' => ['حقل المنظمة مطلوب.'],
                ],
            ]);
        $this->assertDatabaseMissing('departments', [
            'name' => 'HQ',
            'organization_id' => null,
        ]);
    }
}
