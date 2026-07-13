<?php

namespace Tests\Feature\Meetings\Isolation;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Meetings\Models\Meeting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * MeetingIndexIsolationTest - Phase 5.C: org-A user cannot see org-B meetings in the index.
 *
 * GET /api/meetings is gated by engine_capability:MEETINGS_VIEW. The
 * MeetingController::index applies a where('organization_id', $actor->org_id)
 * floor for non-super users, and the FormRequest's authorize() returns false
 * for null-org actors (fail-closed). These tests pin those boundaries at the
 * HTTP layer.
 */
class MeetingIndexIsolationTest extends TestCase
{
    use GrantsEngineCapability;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_org_a_user_only_sees_org_a_meetings(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);
        $organizerA = User::factory()->create(['organization_id' => $orgA->id]);

        $actor = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
        ]);
        $this->grantEngineCapability($actor, Capability::MEETINGS_VIEW);

        // 2 in org A, 3 in org B (must not appear).
        Meeting::factory()->count(2)->create([
            'organization_id' => $orgA->id,
            'organizer_id' => $organizerA->id,
        ]);
        $organizerB = User::factory()->create(['organization_id' => $orgB->id]);
        Meeting::factory()->count(3)->create([
            'organization_id' => $orgB->id,
            'organizer_id' => $organizerB->id,
        ]);

        $response = $this->actingAs($actor, 'sanctum')
            ->getJson('/api/meetings');

        $response->assertStatus(200);
        $this->assertSame(2, $response->json('total'));
        foreach ($response->json('data') as $row) {
            $this->assertSame($orgA->id, $row['organization_id']);
        }
    }

    public function test_super_admin_sees_all_meetings(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);
        $organizerA = User::factory()->create(['organization_id' => $orgA->id]);
        $organizerB = User::factory()->create(['organization_id' => $orgB->id]);

        $superAdmin = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
        ]);
        $this->grantCanonicalSuperAdmin($superAdmin);

        Meeting::factory()->count(2)->create([
            'organization_id' => $orgA->id,
            'organizer_id' => $organizerA->id,
        ]);
        Meeting::factory()->count(3)->create([
            'organization_id' => $orgB->id,
            'organizer_id' => $organizerB->id,
        ]);

        $response = $this->actingAs($superAdmin, 'sanctum')
            ->getJson('/api/meetings');

        $response->assertStatus(200);
        $this->assertSame(5, $response->json('total'));
    }

    public function test_null_org_user_is_denied_at_form_request(): void
    {
        $orgA = Organization::factory()->create();
        $organizerA = User::factory()->create(['organization_id' => $orgA->id]);
        Meeting::factory()->create([
            'organization_id' => $orgA->id,
            'organizer_id' => $organizerA->id,
        ]);

        $actor = User::factory()->create([
            'organization_id' => null,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($actor, Capability::MEETINGS_VIEW);

        $response = $this->actingAs($actor, 'sanctum')
            ->getJson('/api/meetings');

        // Phase 5.B: FormRequest's authorize() returns false for null-org actor
        // (MeetingPolicy::viewAny fail-closed). Expect 403.
        $response->assertStatus(403);
    }
}
