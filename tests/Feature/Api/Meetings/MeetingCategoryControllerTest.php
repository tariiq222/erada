<?php

namespace Tests\Feature\Api\Meetings;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Meetings\Models\MeetingCategory;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * Coverage rows A13 + F1: MeetingCategoryController full action sweep.
 *
 * Endpoints under test:
 *   POST   /api/meeting-categories          -> store
 *   PUT    /api/meeting-categories/{id}     -> update (assertSameOrganization guard)
 *   DELETE /api/meeting-categories/{id}     -> destroy
 *
 * Per action, applied templates:
 *   T-B unauthenticated -> 401
 *   happy path with right role -> 2xx + DB assertion
 *   T-C same-org wrong-role -> 403
 *   T-A cross-org read/mutate -> [403, 404]
 */
class MeetingCategoryControllerTest extends TestCase
{
    use GrantsEngineCapability;
    use RefreshDatabase;

    protected Organization $orgA;

    protected Organization $orgB;

    protected Department $deptA;

    protected Department $deptB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->orgA = Organization::factory()->create();
        $this->orgB = Organization::factory()->create();
        $this->deptA = Department::factory()->create(['organization_id' => $this->orgA->id]);
        $this->deptB = Department::factory()->create(['organization_id' => $this->orgB->id]);
    }

    private function makeUser(?Organization $org, ?string $role = null): User
    {
        $user = User::factory()->create([
            'organization_id' => $org?->id,
            'department_id' => $org
                ? ($org->id === $this->orgA->id ? $this->deptA->id : $this->deptB->id)
                : null,
            'is_active' => true,
        ]);
        if ($role) {
            $this->assignCanonicalRole($user, $role);
        }

        return $user;
    }

    private function assertDeniedByIsolation(int $status, string $msg): void
    {
        $this->assertContains($status, [403, 404], $msg);
    }

    private function adminOf(Organization $org): User
    {
        $user = $this->makeUser($org, 'admin');
        $this->grantEngineCapability($user, Capability::MEETINGS_CREATE);

        return $user;
    }

    // ============================================================
    // store (POST /api/meeting-categories)
    // ============================================================

    public function test_store_requires_authentication(): void
    {
        $this->postJson('/api/meeting-categories', [
            'name' => 'تصنيف',
        ])->assertStatus(401);
    }

    public function test_store_creates_category_with_correct_role(): void
    {
        $admin = $this->adminOf($this->orgA);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/meeting-categories', [
                'name' => 'تصنيف شهري',
                'is_active' => true,
                'sort_order' => 5,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('category.name', 'تصنيف شهري')
            ->assertJsonPath('category.sort_order', 5);

        $this->assertDatabaseHas('meeting_categories', [
            'name' => 'تصنيف شهري',
            'organization_id' => $this->orgA->id,
            'is_active' => true,
            'sort_order' => 5,
        ]);
    }

    public function test_store_denies_viewer_without_manage_capability(): void
    {
        $viewer = $this->makeUser($this->orgA, 'viewer');

        $this->actingAs($viewer, 'sanctum')
            ->postJson('/api/meeting-categories', ['name' => 'تصنيف'])
            ->assertStatus(403);
    }

    public function test_store_validates_name_required(): void
    {
        $admin = $this->adminOf($this->orgA);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/meeting-categories', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    // ============================================================
    // update (PUT /api/meeting-categories/{id})
    // ============================================================

    public function test_update_requires_authentication(): void
    {
        $category = MeetingCategory::factory()->create([
            'organization_id' => $this->orgA->id,
        ]);

        $this->putJson("/api/meeting-categories/{$category->id}", [
            'name' => 'updated',
        ])->assertStatus(401);
    }

    public function test_update_modifies_category_with_correct_role(): void
    {
        $admin = $this->adminOf($this->orgA);
        $this->grantEngineCapability($admin, Capability::MEETINGS_EDIT);
        $category = MeetingCategory::factory()->create([
            'organization_id' => $this->orgA->id,
            'name' => 'قديم',
            'sort_order' => 1,
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->putJson("/api/meeting-categories/{$category->id}", [
                'name' => 'جديد',
                'sort_order' => 9,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('category.name', 'جديد')
            ->assertJsonPath('category.sort_order', 9);

        $category->refresh();
        $this->assertSame('جديد', $category->name);
        $this->assertSame(9, $category->sort_order);
    }

    public function test_update_denies_same_org_viewer_without_edit_capability(): void
    {
        $viewer = $this->makeUser($this->orgA, 'viewer');
        $category = MeetingCategory::factory()->create([
            'organization_id' => $this->orgA->id,
        ]);

        $this->actingAs($viewer, 'sanctum')
            ->putJson("/api/meeting-categories/{$category->id}", [
                'name' => 'hijack',
            ])
            ->assertStatus(403);

        $category->refresh();
        $this->assertNotSame('hijack', $category->name, 'viewer must not silently rename the category');
    }

    public function test_cross_org_actor_cannot_update_foreign_category(): void
    {
        $actor = $this->adminOf($this->orgA);
        $this->grantEngineCapability($actor, Capability::MEETINGS_EDIT);
        $foreign = MeetingCategory::factory()->create([
            'organization_id' => $this->orgB->id,
            'name' => 'foreign',
        ]);

        $this->assertDeniedByIsolation(
            $this->actingAs($actor, 'sanctum')
                ->putJson("/api/meeting-categories/{$foreign->id}", ['name' => 'hijack'])
                ->status(),
            'org-A admin must not update an org-B category'
        );

        $foreign->refresh();
        $this->assertSame('foreign', $foreign->name, 'foreign category must remain untouched');
    }

    // ============================================================
    // destroy (DELETE /api/meeting-categories/{id})
    // ============================================================

    public function test_destroy_requires_authentication(): void
    {
        $category = MeetingCategory::factory()->create([
            'organization_id' => $this->orgA->id,
        ]);

        $this->deleteJson("/api/meeting-categories/{$category->id}")
            ->assertStatus(401);
    }

    public function test_destroy_soft_deletes_category_with_correct_role(): void
    {
        $admin = $this->adminOf($this->orgA);
        $this->grantEngineCapability($admin, Capability::MEETINGS_DELETE);
        $category = MeetingCategory::factory()->create([
            'organization_id' => $this->orgA->id,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/meeting-categories/{$category->id}")
            ->assertStatus(200);

        $this->assertSoftDeleted('meeting_categories', ['id' => $category->id]);
    }

    public function test_destroy_denies_same_org_viewer_without_delete_capability(): void
    {
        $viewer = $this->makeUser($this->orgA, 'viewer');
        $category = MeetingCategory::factory()->create([
            'organization_id' => $this->orgA->id,
        ]);

        $this->actingAs($viewer, 'sanctum')
            ->deleteJson("/api/meeting-categories/{$category->id}")
            ->assertStatus(403);

        $this->assertNotSoftDeleted('meeting_categories', ['id' => $category->id]);
    }

    public function test_cross_org_actor_cannot_destroy_foreign_category(): void
    {
        $actor = $this->adminOf($this->orgA);
        $this->grantEngineCapability($actor, Capability::MEETINGS_DELETE);
        $foreign = MeetingCategory::factory()->create([
            'organization_id' => $this->orgB->id,
        ]);

        $this->assertDeniedByIsolation(
            $this->actingAs($actor, 'sanctum')
                ->deleteJson("/api/meeting-categories/{$foreign->id}")
                ->status(),
            'org-A admin must not delete an org-B category'
        );

        $this->assertNotSoftDeleted('meeting_categories', ['id' => $foreign->id]);
    }

    // ============================================================
    // index (sanity sweep — included since the route exists)
    // ============================================================

    public function test_index_lists_only_user_org_categories_for_admin(): void
    {
        $admin = $this->adminOf($this->orgA);
        $this->grantEngineCapability($admin, Capability::MEETINGS_VIEW);

        MeetingCategory::factory()->create(['organization_id' => $this->orgA->id, 'name' => 'mine']);
        MeetingCategory::factory()->create(['organization_id' => $this->orgB->id, 'name' => 'theirs']);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/meeting-categories')
            ->assertStatus(200);

        $names = collect($response->json('data'))->pluck('name')->all();
        $this->assertContains('mine', $names);
        $this->assertNotContains('theirs', $names);
    }
}
