<?php

namespace Tests\Unit\Core\Resources;

use App\Modules\Core\Http\Resources\UserDirectoryResource;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * UserDirectoryResourceWhitelistTest - Phase CFA-07: assert that
 * UserDirectoryResource emits EXACTLY the audit-approved whitelist of keys.
 *
 * HIGH PII safety net: this test is the single source of truth for the
 * directory shape. If a developer accidentally adds a new key (or removes
 * one), the whitelist assertion fails and the change is caught BEFORE it
 * ships. Combined with UserDirectoryResourceFieldExclusionTest, the suite
 * guarantees no PII leak reaches a cluster actor.
 */
class UserDirectoryResourceWhitelistTest extends TestCase
{
    use RefreshDatabase;

    public function test_resource_exactly_emits_the_whitelisted_keys(): void
    {
        $user = $this->makeUser();

        $payload = (new UserDirectoryResource($user))->toArray(Request::create('/api/users/'.$user->id));

        $this->assertSame(
            UserDirectoryResource::WHITELISTED_KEYS,
            array_keys($payload),
            'UserDirectoryResource must emit EXACTLY the WHITELISTED_KEYS in EXACTLY that order'
        );
    }

    public function test_resource_emits_id_name_email_org_dept_job_title_is_active(): void
    {
        $org = Organization::factory()->create(['name' => 'org X']);
        $dept = Department::factory()->create(['organization_id' => $org->id]);

        $user = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
            'name' => 'Ahmed Cluster',
            'email' => 'ahmed.cluster@example.test',
            'job_title' => 'Project Coordinator',
            'is_active' => true,
        ]);

        $payload = (new UserDirectoryResource($user))->toArray(Request::create('/api/users/'.$user->id));

        $this->assertSame($user->id, $payload['id']);
        $this->assertSame('Ahmed Cluster', $payload['name']);
        $this->assertSame('ahmed.cluster@example.test', $payload['email']);
        $this->assertSame($org->id, $payload['organization_id']);
        $this->assertSame($dept->id, $payload['department_id']);
        $this->assertSame('Project Coordinator', $payload['job_title']);
        $this->assertTrue($payload['is_active']);
    }

    public function test_resource_handles_null_organization_id_and_department_id(): void
    {
        $user = User::factory()->create([
            'organization_id' => null,
            'department_id' => null,
        ]);

        $payload = (new UserDirectoryResource($user))->toArray(Request::create('/api/users/'.$user->id));

        $this->assertNull($payload['organization_id']);
        $this->assertNull($payload['department_id']);
        // The whitelisted shape is intact even with nullable FKs.
        $this->assertSame(UserDirectoryResource::WHITELISTED_KEYS, array_keys($payload));
    }

    public function test_resource_handles_null_job_title_and_is_active_false(): void
    {
        $user = $this->makeUser();
        $user->forceFill([
            'job_title' => null,
            'is_active' => false,
        ])->save();

        $payload = (new UserDirectoryResource($user))->toArray(Request::create('/api/users/'.$user->id));

        $this->assertNull($payload['job_title']);
        $this->assertFalse($payload['is_active']);
    }

    public function test_whitelist_constant_has_exactly_seven_audit_approved_keys(): void
    {
        // The whitelist constant is the audit-approved boundary. Locking its count
        // catches accidental add/remove of keys without diff'ing the resource class.
        $this->assertCount(7, UserDirectoryResource::WHITELISTED_KEYS);
    }

    private function makeUser(): User
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);

        return User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
            'is_active' => true,
        ]);
    }
}
