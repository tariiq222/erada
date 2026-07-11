<?php

namespace Tests\Feature\Api\Admin;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Shared\Models\ActivityLog;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminRouteContractTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Department $department;

    private User $regularUser;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->organization = Organization::factory()->create();
        $this->department = Department::factory()->create([
            'organization_id' => $this->organization->id,
        ]);
        $this->regularUser = User::factory()->create([
            'organization_id' => $this->organization->id,
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        $this->regularUser->assignRole('member');

        $this->superAdmin = User::factory()->create([
            'organization_id' => $this->organization->id,
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        $this->superAdmin->assignRole('super_admin');
    }

    public function test_canonical_admin_route_families_require_authentication(): void
    {
        foreach ($this->canonicalReadRoutes() as $route) {
            $this->getJson($route)->assertUnauthorized();
        }
    }

    public function test_canonical_admin_route_families_reject_non_super_admins(): void
    {
        foreach ($this->canonicalReadRoutes() as $route) {
            $this->actingAs($this->regularUser, 'sanctum')
                ->getJson($route)
                ->assertForbidden();
        }
    }

    public function test_super_admin_can_read_every_canonical_admin_route_family(): void
    {
        foreach ($this->canonicalReadRoutes() as $route) {
            $this->actingAs($this->superAdmin, 'sanctum')
                ->getJson($route)
                ->assertOk();
        }
    }

    public function test_canonical_reads_are_json_equivalent_to_legacy_routes(): void
    {
        foreach ($this->legacyCanonicalPairs() as [$legacy, $canonical]) {
            $legacyResponse = $this->actingAs($this->superAdmin, 'sanctum')
                ->getJson($legacy)
                ->assertOk();
            $canonicalResponse = $this->actingAs($this->superAdmin, 'sanctum')
                ->getJson($canonical)
                ->assertOk();

            $this->assertSame(
                $this->normalizeRouteSpecificPagination($legacyResponse->json()),
                $this->normalizeRouteSpecificPagination($canonicalResponse->json()),
                "Canonical route [{$canonical}] differs from legacy route [{$legacy}]."
            );
        }
    }

    public function test_canonical_organization_mutation_retains_validation_idempotency_and_audit_logging(): void
    {
        $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/admin/organizations', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'code']);

        $payload = [
            'name' => 'Canonical Tenant',
            'code' => 'CANONICAL-TENANT',
            'is_active' => true,
        ];
        $headers = ['X-Idempotency-Key' => 'admin-route-contract-organization'];
        $organizationCountBefore = Organization::query()->count();
        $activityLogCountBefore = ActivityLog::query()->count();

        $first = $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/admin/organizations', $payload, $headers)
            ->assertCreated();
        $activityLogCountAfterFirstRequest = ActivityLog::query()->count();
        $second = $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/admin/organizations', $payload, $headers)
            ->assertOk();

        $this->assertSame($first->json(), $second->json());
        $this->assertDatabaseCount('organizations', $organizationCountBefore + 1);
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $this->superAdmin->id,
            'action' => ActivityLog::ACTION_CREATED,
            'loggable_type' => Organization::class,
            'loggable_id' => $first->json('data.id'),
        ]);
        $this->assertGreaterThan($activityLogCountBefore, $activityLogCountAfterFirstRequest);
        $this->assertSame($activityLogCountAfterFirstRequest, ActivityLog::query()->count());
    }

    public function test_canonical_governance_mutation_denies_cross_organization_target(): void
    {
        $foreignOrganization = Organization::factory()->create();
        $foreignDepartment = Department::factory()->create([
            'organization_id' => $foreignOrganization->id,
        ]);

        $this->actingAs($this->superAdmin, 'sanctum')
            ->putJson('/api/admin/governance-rules', [
                'resource_type' => 'project',
                'governing_unit_id' => $foreignDepartment->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['governing_unit_id']);

        $this->assertDatabaseMissing('governance_rules', [
            'organization_id' => $this->organization->id,
            'resource_type' => 'project',
            'governing_unit_id' => $foreignDepartment->id,
        ]);
    }

    /**
     * @return list<string>
     */
    private function canonicalReadRoutes(): array
    {
        return [
            '/api/admin/organizations',
            '/api/admin/scope-types',
            '/api/admin/roles',
            '/api/admin/governance-rules',
            '/api/admin/users',
            '/api/admin/scoped-roles/audit-logs',
            '/api/admin/activity-logs',
            '/api/admin/departments',
            '/api/admin/incident-types',
            '/api/admin/overview',
            '/api/admin/security/alerts',
            '/api/admin/audit/recent',
        ];
    }

    /**
     * @return list<array{string, string}>
     */
    private function legacyCanonicalPairs(): array
    {
        return [
            ['/api/organizations', '/api/admin/organizations'],
            ['/api/scope-types', '/api/admin/scope-types'],
            ['/api/roles', '/api/admin/roles'],
            ['/api/governance-rules', '/api/admin/governance-rules'],
            ['/api/users', '/api/admin/users'],
            ['/api/scoped-roles/audit-logs', '/api/admin/scoped-roles/audit-logs'],
            ['/api/activity-logs', '/api/admin/activity-logs'],
            ['/api/hr/departments', '/api/admin/departments'],
            ['/api/ovr/categories', '/api/admin/incident-types'],
        ];
    }

    /**
     * Pagination URLs correctly reflect the requested alias. The represented
     * resources and pagination values must otherwise remain equivalent.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeRouteSpecificPagination(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if ($key === 'path' || $key === 'url' || str_ends_with((string) $key, '_url')) {
                unset($payload[$key]);

                continue;
            }

            if (is_array($value)) {
                $payload[$key] = $this->normalizeRouteSpecificPagination($value);
            }
        }

        return $payload;
    }
}
