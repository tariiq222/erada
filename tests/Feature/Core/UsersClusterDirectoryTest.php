<?php

namespace Tests\Feature\Core;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Http\Resources\UserDirectoryResource;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * UsersClusterDirectoryTest - Phase CFA-07: full HTTP test for the cluster
 * limited-directory endpoint (HIGH PII safety).
 *
 * Covers the contract:
 *   1) Cluster user with USERS_VIEW + CLUSTER_TREE_VIEW on actor.org =>
 *      cross-org show endpoint returns UserDirectoryResource shape (no full
 *      UserResource).
 *   2) Cluster user with both grants => all sensitive columns absent from the
 *      response payload (no password, tokens, 2FA, last_login_ip, etc).
 *   3) Cluster user with both grants => can show users in their own org too
 *      (returns full shape), because same-org reads route through `view()`,
 *      not the directory path.
 *   4) Cluster user with USERS_VIEW only (no CLUSTER_TREE_VIEW) => cross-org
 *      shows return 403 (strict same-org), no widening.
 *   5) Cluster user with CLUSTER_TREE_VIEW only (no USERS_VIEW) => cross-org
 *      shows return 403 (the cluster primitive alone does NOT widen).
 *   6) Cluster user with both grants => sibling-org shows return 403.
 *   7) Child org user with both grants => cannot show parent cluster user
 *      (one-directional walk).
 *   8) super_admin => sees full UserResource regardless (no sanitization;
 *      super_admin already has full access).
 *   9) Non-cluster admin (USERS_VIEW via org admin role) => sees full
 *      UserResource for own org (no widening).
 */
class UsersClusterDirectoryTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    protected Organization $cluster;

    protected Organization $hospital;

    protected Organization $sibling;

    protected Department $deptCluster;

    protected Department $deptHospital;

    protected Department $deptSibling;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->cluster = Organization::factory()->cluster()->create(['name' => 'Cluster']);
        $this->hospital = Organization::factory()->hospital()
            ->childOf($this->cluster)
            ->create(['name' => 'Hospital Under Cluster']);
        $this->sibling = Organization::factory()->create(['name' => 'Sibling Cluster']);

        $this->deptCluster = Department::factory()->create(['organization_id' => $this->cluster->id]);
        $this->deptHospital = Department::factory()->create(['organization_id' => $this->hospital->id]);
        $this->deptSibling = Department::factory()->create(['organization_id' => $this->sibling->id]);
    }

    public function test_cluster_actor_gets_directory_shape_on_cross_org_show(): void
    {
        $actor = $this->makeClusterActor();
        $target = $this->makeTarget($this->hospital);

        $response = $this->actingAs($actor, 'sanctum')
            ->getJson("/api/users/{$target->id}");

        $response->assertOk();

        $payload = $response->json();

        // The payload MUST be exactly the whitelist.
        $this->assertSame(
            UserDirectoryResource::WHITELISTED_KEYS,
            array_keys($payload),
            'Cross-org show response must match the UserDirectoryResource whitelist exactly'
        );

        // Spot-check the whitelisted fields are populated.
        $this->assertSame($target->id, $payload['id']);
        $this->assertSame($target->name, $payload['name']);
        $this->assertSame($target->email, $payload['email']);
        $this->assertSame($target->organization_id, $payload['organization_id']);
        $this->assertSame($target->department_id, $payload['department_id']);
        $this->assertSame($target->job_title, $payload['job_title']);
        $this->assertSame($target->is_active, $payload['is_active']);
    }

    public function test_cluster_actor_show_response_excludes_every_sensitive_field(): void
    {
        $actor = $this->makeClusterActor();
        $target = $this->makeTargetWithSensitiveColumns($this->hospital);

        $response = $this->actingAs($actor, 'sanctum')
            ->getJson("/api/users/{$target->id}")
            ->assertOk();

        $encoded = json_encode($response->json());

        // Every CFA-00 stop-condition field MUST be absent in both keys and values.
        $this->assertStringNotContainsString('SuperSecretPassword!2026', $encoded);
        $this->assertStringNotContainsString('TOP_SECRET_2FA', $encoded);
        $this->assertStringNotContainsString('203.0.113.99', $encoded);
        $this->assertStringNotContainsString('remember-token-stub', $encoded);
        $this->assertStringNotContainsString('recovery-1', $encoded);
        $this->assertStringNotContainsString('hash-1', $encoded);

        // Explicit forbidden keys
        foreach ([
            'password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes',
            'two_factor_recovery_code_hashes', 'two_factor_confirmed_at', 'two_factor_required',
            'last_login_at', 'last_login_ip', 'last_failed_login_at', 'failed_login_attempts',
            'locked_until', 'scoped_roles', 'permissions', 'roles',
            'created_by', 'updated_by', 'pivot',
        ] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $response->json(), "Cluster response must not expose `{$forbidden}`");
        }
    }

    public function test_cluster_actor_same_org_show_returns_full_shape(): void
    {
        $actor = $this->makeClusterActor();
        // Same-org target: the cluster actor's own org (cluster).
        $sameOrgTarget = User::factory()->create([
            'organization_id' => $this->cluster->id,
            'department_id' => $this->deptCluster->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($actor, 'sanctum')
            ->getJson("/api/users/{$sameOrgTarget->id}")
            ->assertOk();

        // Same-org reads return the full UserResource shape (with roles/permissions).
        $this->assertArrayHasKey('roles', $response->json());
    }

    public function test_cluster_actor_without_cluster_tree_view_gets_403_on_cross_org_show(): void
    {
        $actor = User::factory()->create([
            'organization_id' => $this->cluster->id,
            'department_id' => $this->deptCluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($actor, Capability::USERS_VIEW);

        $target = $this->makeTarget($this->hospital);

        // No CLUSTER_TREE_VIEW => no widening => strict same-org => 403 cross-org.
        $this->actingAs($actor, 'sanctum')
            ->getJson("/api/users/{$target->id}")
            ->assertStatus(403);
    }

    public function test_cluster_actor_without_users_view_gets_403_on_cross_org_show(): void
    {
        $actor = User::factory()->create([
            'organization_id' => $this->cluster->id,
            'department_id' => $this->deptCluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($actor, Capability::CLUSTER_TREE_VIEW);

        $target = $this->makeTarget($this->hospital);

        // No USERS_VIEW => even with the cluster primitive, no widening => 403.
        $this->actingAs($actor, 'sanctum')
            ->getJson("/api/users/{$target->id}")
            ->assertStatus(403);
    }

    public function test_cluster_actor_cannot_view_sibling_org_user(): void
    {
        $actor = $this->makeClusterActor();
        $siblingUser = User::factory()->create([
            'organization_id' => $this->sibling->id,
            'department_id' => $this->deptSibling->id,
            'is_active' => true,
        ]);

        // The sibling cluster is NOT a descendant of this cluster -> denied.
        $this->actingAs($actor, 'sanctum')
            ->getJson("/api/users/{$siblingUser->id}")
            ->assertStatus(403);
    }

    public function test_child_org_actor_cannot_view_parent_cluster_user(): void
    {
        // Hospital user with both capabilities tries to see the cluster root user.
        $childUser = User::factory()->create([
            'organization_id' => $this->hospital->id,
            'department_id' => $this->deptHospital->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($childUser, [
            Capability::USERS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $parentUser = User::factory()->create([
            'organization_id' => $this->cluster->id,
            'department_id' => $this->deptCluster->id,
            'is_active' => true,
        ]);

        // One-directional walk: child cannot see parent via cluster_tree.
        $this->actingAs($childUser, 'sanctum')
            ->getJson("/api/users/{$parentUser->id}")
            ->assertStatus(403);
    }

    public function test_super_admin_sees_full_userresource_on_cross_org_show(): void
    {
        $super = User::factory()->create([
            'organization_id' => $this->cluster->id,
            'is_active' => true,
        ]);
        $this->grantCanonicalSuperAdmin($super);

        $target = $this->makeTarget($this->hospital);

        $response = $this->actingAs($super, 'sanctum')
            ->getJson("/api/users/{$target->id}")
            ->assertOk();

        // super_admin does NOT get sanitized: they have full access.
        // Full UserResource has roles + permissions arrays.
        $this->assertArrayHasKey('roles', $response->json());
    }

    public function test_non_cluster_admin_same_org_sees_full_userresource(): void
    {
        $admin = User::factory()->create([
            'organization_id' => $this->hospital->id,
            'department_id' => $this->deptHospital->id,
            'is_active' => true,
        ]);
        $this->grantCanonicalAdmin($admin);

        $target = User::factory()->create([
            'organization_id' => $this->hospital->id,
            'department_id' => $this->deptHospital->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/users/{$target->id}")
            ->assertOk();

        // Same-org admin still gets the full UserResource (no widening applied).
        $this->assertArrayHasKey('roles', $response->json());
    }

    public function test_null_org_actor_with_cluster_grants_still_gets_403_cross_org(): void
    {
        $orphan = User::factory()->create([
            'organization_id' => null,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($orphan, [
            Capability::USERS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $target = User::factory()->create([
            'organization_id' => $this->hospital->id,
            'is_active' => true,
        ]);

        // null-org actor => fail-closed => 403.
        $this->actingAs($orphan, 'sanctum')
            ->getJson("/api/users/{$target->id}")
            ->assertStatus(403);
    }

    public function test_cluster_actor_index_response_does_not_widen_without_grant_pair(): void
    {
        $actor = User::factory()->create([
            'organization_id' => $this->cluster->id,
            'department_id' => $this->deptCluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($actor, Capability::USERS_VIEW);

        // Same-org create
        User::factory()->create([
            'organization_id' => $this->cluster->id,
            'department_id' => $this->deptCluster->id,
            'is_active' => true,
        ]);
        // Cross-org (descendant)
        User::factory()->create([
            'organization_id' => $this->hospital->id,
            'department_id' => $this->deptHospital->id,
            'is_active' => true,
        ]);

        // Without CLUSTER_TREE_VIEW, the index endpoint does NOT widen.
        // Only same-org users appear.
        $response = $this->actingAs($actor, 'sanctum')
            ->getJson('/api/users')
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($actor->id, $ids);

        // The descendant user is NOT in the index (no widening without both caps).
        $descendantUserIds = collect($response->json('data'))
            ->where('organization_id', $this->hospital->id)
            ->pluck('id')
            ->all();
        $this->assertEmpty($descendantUserIds);
    }

    public function test_cluster_actor_list_returns_descendant_users_in_directory_shape(): void
    {
        $actor = $this->makeClusterActor();
        $target = $this->makeTarget($this->hospital);
        $sibling = $this->makeTarget($this->sibling);

        $response = $this->actingAs($actor, 'sanctum')
            ->getJson('/api/users/list')
            ->assertOk();

        $rows = collect($response->json());

        $this->assertNotNull($rows->firstWhere('id', $target->id));
        $this->assertNull($rows->firstWhere('id', $sibling->id));

        foreach ($rows as $row) {
            $this->assertSame(UserDirectoryResource::WHITELISTED_KEYS, array_keys($row));
        }
    }

    public function test_user_list_stays_same_org_without_cluster_tree_view(): void
    {
        $actor = User::factory()->create([
            'organization_id' => $this->cluster->id,
            'department_id' => $this->deptCluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($actor, Capability::USERS_VIEW);

        $target = $this->makeTarget($this->hospital);

        $response = $this->actingAs($actor, 'sanctum')
            ->getJson('/api/users/list')
            ->assertOk();

        $this->assertNull(collect($response->json())->firstWhere('id', $target->id));
    }

    /**
     * Make a cluster actor (cluster org + USERS_VIEW + CLUSTER_TREE_VIEW).
     */
    private function makeClusterActor(): User
    {
        $actor = User::factory()->create([
            'organization_id' => $this->cluster->id,
            'department_id' => $this->deptCluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($actor, [
            Capability::USERS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        return $actor;
    }

    private function makeTarget(Organization $org): User
    {
        return User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $org->id === $this->hospital->id ? $this->deptHospital->id : $this->deptCluster->id,
            'is_active' => true,
            'name' => 'Target User',
            'job_title' => 'Coordinator',
        ]);
    }

    private function makeTargetWithSensitiveColumns(Organization $org): User
    {
        $target = $this->makeTarget($org);

        // Force-fill sensitive columns on the actual DB row so the response body
        // would have something to leak if the resource/scope is buggy.
        $target->forceFill([
            'password' => 'SuperSecretPassword!2026',
            'two_factor_secret' => 'TOP_SECRET_2FA',
            'two_factor_recovery_codes' => ['recovery-1'],
            'two_factor_recovery_code_hashes' => ['hash-1'],
            'two_factor_confirmed_at' => now(),
            'two_factor_required' => true,
            'failed_login_attempts' => 3,
            'locked_until' => now()->addMinutes(15),
            'last_login_at' => now(),
            'last_login_ip' => '203.0.113.99',
            'last_failed_login_at' => now(),
            'remember_token' => 'remember-token-stub',
        ])->save();

        return $target;
    }
}
