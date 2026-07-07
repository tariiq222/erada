<?php

namespace Tests\Feature\Api;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\ScopedRole;
use App\Modules\Core\Models\ScopeType;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Milestone;
use App\Modules\Projects\Models\Project;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Authorization tests for MilestoneController.
 *
 * The controller authorizes all write operations via `$this->authorize('update', $project)`.
 * These tests cover:
 *   - Cross-project / cross-org denial (user from org B cannot touch org A milestones)
 *   - Project viewer/member without edit capability is denied on store/update/destroy
 *   - Org admin (is_admin_role=true scoped role) is allowed
 *   - super_admin bypasses everything
 */
class MilestoneAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Department $dept;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeaders(['X-Skip-Csrf' => '1']);
        $this->seed(RolesAndPermissionsSeeder::class);
        Cache::flush();
        $this->seedProjectScopeDefinitions();
        $this->seedOrgScopeDefinitions();

        $this->org = Organization::factory()->create();
        $this->dept = Department::factory()->create(['organization_id' => $this->org->id]);
        $this->project = Project::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
            'start_date' => now(),
            'end_date' => now()->addMonths(6),
        ]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeUser(string $role, ?int $orgId = null): User
    {
        $org = $orgId ?? $this->org->id;
        $user = User::factory()->create([
            'organization_id' => $org,
            'department_id' => $this->dept->id,
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function grantOrgAdminScopedRole(User $user): void
    {
        if ($user->organization_id === null) {
            return;
        }

        $exists = DB::table('model_has_scoped_roles')
            ->where('user_id', $user->id)
            ->where('scope_type', ScopedRole::SCOPE_ORGANIZATION)
            ->where('scope_id', $user->organization_id)
            ->exists();

        if (! $exists) {
            DB::table('model_has_scoped_roles')->insert([
                'user_id' => $user->id,
                'role' => 'admin',
                'scope_type' => ScopedRole::SCOPE_ORGANIZATION,
                'scope_id' => $user->organization_id,
                'inherit_to_children' => true,
                'granted_by' => null,
                'expires_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Cache::flush();
    }

    private function storePayload(): array
    {
        return [
            'project_id' => $this->project->id,
            'name' => 'Test Milestone',
            'duration_value' => 1,
            'duration_unit' => 'week',
        ];
    }

    private function seedProjectScopeDefinitions(): void
    {
        $scopeType = ScopeType::firstOrCreate(
            ['key' => ScopedRole::SCOPE_PROJECT],
            [
                'label_ar' => 'مشروع',
                'label_en' => 'Project',
                'model_class' => Project::class,
                'supports_hierarchy' => true,
                'supports_expiry' => false,
                'is_active' => true,
                'sort_order' => 10,
            ]
        );

        $now = now();
        $definitions = [
            [
                'name' => 'project_manager',
                'display_name' => 'Project Manager',
                'scope_type' => ScopedRole::SCOPE_PROJECT,
                'level' => 1,
                'scope_type_id' => $scopeType->id,
                'role_key' => ScopedRole::PROJECT_MANAGER,
                'label_ar' => 'مدير المشروع',
                'label_en' => 'Project Manager',
                'is_admin_role' => false,
                'permissions' => json_encode($this->expandFlags(['projects.view', 'projects.edit'], [
                    'can_manage_members' => true, 'can_edit' => true, 'can_delete' => false, 'can_view_all' => true,
                ])),
                'is_active' => true,
                'sort_order' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'project_member',
                'display_name' => 'Project Member',
                'scope_type' => ScopedRole::SCOPE_PROJECT,
                'level' => 2,
                'scope_type_id' => $scopeType->id,
                'role_key' => ScopedRole::PROJECT_MEMBER,
                'label_ar' => 'عضو',
                'label_en' => 'Member',
                'is_admin_role' => false,
                'permissions' => json_encode($this->expandFlags(['projects.view'], [
                    'can_manage_members' => false, 'can_edit' => false, 'can_delete' => false, 'can_view_all' => true,
                ])),
                'is_active' => true,
                'sort_order' => 2,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'project_viewer',
                'display_name' => 'Project Viewer',
                'scope_type' => ScopedRole::SCOPE_PROJECT,
                'level' => 3,
                'scope_type_id' => $scopeType->id,
                'role_key' => ScopedRole::PROJECT_VIEWER,
                'label_ar' => 'مشاهد',
                'label_en' => 'Viewer',
                'is_admin_role' => false,
                'permissions' => json_encode($this->expandFlags(['projects.view'], [
                    'can_manage_members' => false, 'can_edit' => false, 'can_delete' => false, 'can_view_all' => true,
                ])),
                'is_active' => true,
                'sort_order' => 3,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        foreach ($definitions as $def) {
            $exists = DB::table('scoped_role_definitions')
                ->where('scope_type_id', $def['scope_type_id'])
                ->where('role_key', $def['role_key'])
                ->exists();

            if (! $exists) {
                DB::table('scoped_role_definitions')->insert($def);
            }
        }

        Cache::flush();
    }

    private function seedOrgScopeDefinitions(): void
    {
        $scopeType = ScopeType::firstOrCreate(
            ['key' => ScopedRole::SCOPE_ORGANIZATION],
            [
                'label_ar' => 'المؤسسة',
                'label_en' => 'Organization',
                'model_class' => 'App\\Modules\\Core\\Models\\Organization',
                'supports_hierarchy' => false,
                'supports_expiry' => false,
                'is_active' => true,
                'sort_order' => 1,
            ]
        );

        $now = now();
        $exists = DB::table('scoped_role_definitions')
            ->where('scope_type', ScopedRole::SCOPE_ORGANIZATION)
            ->where('role_key', 'admin')
            ->exists();

        if (! $exists) {
            DB::table('scoped_role_definitions')->insert([
                'name' => 'organization_admin',
                'display_name' => 'Admin',
                'scope_type' => ScopedRole::SCOPE_ORGANIZATION,
                'level' => 1,
                'scope_type_id' => $scopeType->id,
                'role_key' => 'admin',
                'label_ar' => 'مدير إدارة',
                'label_en' => 'Admin',
                'is_admin_role' => true,
                'permissions' => null,
                'is_active' => true,
                'sort_order' => 10,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        Cache::flush();
    }

    // -------------------------------------------------------------------------
    // Cross-org denial: user from org B cannot access org A milestones
    // -------------------------------------------------------------------------

    public function test_cross_org_user_gets_403_on_store(): void
    {
        $orgB = Organization::factory()->create();
        $outsider = User::factory()->create([
            'organization_id' => $orgB->id,
            'department_id' => null,
            'is_active' => true,
        ]);
        $outsider->assignRole('admin');

        $response = $this->actingAs($outsider, 'sanctum')
            ->postJson('/api/milestones', $this->storePayload());

        $response->assertForbidden();
    }

    public function test_cross_org_user_gets_403_on_update(): void
    {
        $milestone = Milestone::factory()->create(['project_id' => $this->project->id]);

        $orgB = Organization::factory()->create();
        $outsider = User::factory()->create([
            'organization_id' => $orgB->id,
            'department_id' => null,
            'is_active' => true,
        ]);
        $outsider->assignRole('admin');

        $response = $this->actingAs($outsider, 'sanctum')
            ->putJson("/api/milestones/{$milestone->id}", ['name' => 'Changed']);

        $response->assertForbidden();
    }

    public function test_cross_org_user_gets_403_on_destroy(): void
    {
        $milestone = Milestone::factory()->create(['project_id' => $this->project->id]);

        $orgB = Organization::factory()->create();
        $outsider = User::factory()->create([
            'organization_id' => $orgB->id,
            'department_id' => null,
            'is_active' => true,
        ]);
        $outsider->assignRole('admin');

        $response = $this->actingAs($outsider, 'sanctum')
            ->deleteJson("/api/milestones/{$milestone->id}");

        $response->assertForbidden();
    }

    // -------------------------------------------------------------------------
    // Project viewer (no edit capability) is denied write operations
    // -------------------------------------------------------------------------

    public function test_project_viewer_cannot_store_milestone(): void
    {
        $viewer = $this->makeUser('viewer');
        $viewer->assignProjectRole($this->project, ScopedRole::PROJECT_VIEWER);

        $response = $this->actingAs($viewer, 'sanctum')
            ->postJson('/api/milestones', $this->storePayload());

        $response->assertForbidden();
    }

    public function test_project_viewer_cannot_update_milestone(): void
    {
        $milestone = Milestone::factory()->create(['project_id' => $this->project->id]);
        $viewer = $this->makeUser('viewer');
        $viewer->assignProjectRole($this->project, ScopedRole::PROJECT_VIEWER);

        $response = $this->actingAs($viewer, 'sanctum')
            ->putJson("/api/milestones/{$milestone->id}", ['name' => 'Changed']);

        $response->assertForbidden();
    }

    public function test_project_viewer_cannot_destroy_milestone(): void
    {
        $milestone = Milestone::factory()->create(['project_id' => $this->project->id]);
        $viewer = $this->makeUser('viewer');
        $viewer->assignProjectRole($this->project, ScopedRole::PROJECT_VIEWER);

        $response = $this->actingAs($viewer, 'sanctum')
            ->deleteJson("/api/milestones/{$milestone->id}");

        $response->assertForbidden();
    }

    // -------------------------------------------------------------------------
    // Project member (no edit capability) is denied write operations
    // -------------------------------------------------------------------------

    public function test_project_member_cannot_store_milestone(): void
    {
        $member = $this->makeUser('viewer');
        $member->assignProjectRole($this->project, ScopedRole::PROJECT_MEMBER);

        $response = $this->actingAs($member, 'sanctum')
            ->postJson('/api/milestones', $this->storePayload());

        $response->assertForbidden();
    }

    public function test_project_member_cannot_update_milestone(): void
    {
        $milestone = Milestone::factory()->create(['project_id' => $this->project->id]);
        $member = $this->makeUser('viewer');
        $member->assignProjectRole($this->project, ScopedRole::PROJECT_MEMBER);

        $response = $this->actingAs($member, 'sanctum')
            ->putJson("/api/milestones/{$milestone->id}", ['name' => 'Changed']);

        $response->assertForbidden();
    }

    public function test_project_member_cannot_destroy_milestone(): void
    {
        $milestone = Milestone::factory()->create(['project_id' => $this->project->id]);
        $member = $this->makeUser('viewer');
        $member->assignProjectRole($this->project, ScopedRole::PROJECT_MEMBER);

        $response = $this->actingAs($member, 'sanctum')
            ->deleteJson("/api/milestones/{$milestone->id}");

        $response->assertForbidden();
    }

    // -------------------------------------------------------------------------
    // Project manager (can_edit=true) is allowed
    // -------------------------------------------------------------------------

    public function test_project_manager_can_store_milestone(): void
    {
        $manager = $this->makeUser('viewer');
        $manager->assignProjectRole($this->project, ScopedRole::PROJECT_MANAGER);

        $response = $this->actingAs($manager, 'sanctum')
            ->postJson('/api/milestones', $this->storePayload());

        $response->assertStatus(201);
    }

    public function test_project_manager_can_update_milestone(): void
    {
        $milestone = Milestone::factory()->create(['project_id' => $this->project->id]);
        $manager = $this->makeUser('viewer');
        $manager->assignProjectRole($this->project, ScopedRole::PROJECT_MANAGER);

        $response = $this->actingAs($manager, 'sanctum')
            ->putJson("/api/milestones/{$milestone->id}", ['name' => 'Updated by Manager']);

        $response->assertOk();
    }

    public function test_project_manager_can_destroy_milestone(): void
    {
        $milestone = Milestone::factory()->create(['project_id' => $this->project->id]);
        $manager = $this->makeUser('viewer');
        $manager->assignProjectRole($this->project, ScopedRole::PROJECT_MANAGER);

        $response = $this->actingAs($manager, 'sanctum')
            ->deleteJson("/api/milestones/{$milestone->id}");

        $response->assertOk();
    }

    // -------------------------------------------------------------------------
    // Org admin (is_admin_role=true) is allowed
    // -------------------------------------------------------------------------

    public function test_org_admin_can_store_milestone(): void
    {
        $admin = $this->makeUser('admin');
        $this->grantOrgAdminScopedRole($admin);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/milestones', $this->storePayload());

        $response->assertStatus(201);
    }

    public function test_org_admin_can_update_milestone(): void
    {
        $milestone = Milestone::factory()->create(['project_id' => $this->project->id]);
        $admin = $this->makeUser('admin');
        $this->grantOrgAdminScopedRole($admin);

        $response = $this->actingAs($admin, 'sanctum')
            ->putJson("/api/milestones/{$milestone->id}", ['name' => 'Admin Updated']);

        $response->assertOk();
    }

    public function test_org_admin_can_destroy_milestone(): void
    {
        $milestone = Milestone::factory()->create(['project_id' => $this->project->id]);
        $admin = $this->makeUser('admin');
        $this->grantOrgAdminScopedRole($admin);

        $response = $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/milestones/{$milestone->id}");

        $response->assertOk();
    }

    // -------------------------------------------------------------------------
    // super_admin bypasses all checks
    // -------------------------------------------------------------------------

    public function test_super_admin_can_store_milestone(): void
    {
        $sa = $this->makeUser('super_admin');

        $response = $this->actingAs($sa, 'sanctum')
            ->postJson('/api/milestones', $this->storePayload());

        $response->assertStatus(201);
    }

    public function test_super_admin_can_update_milestone(): void
    {
        $milestone = Milestone::factory()->create(['project_id' => $this->project->id]);
        $sa = $this->makeUser('super_admin');

        $response = $this->actingAs($sa, 'sanctum')
            ->putJson("/api/milestones/{$milestone->id}", ['name' => 'SA Updated']);

        $response->assertOk();
    }

    public function test_super_admin_can_destroy_milestone(): void
    {
        $milestone = Milestone::factory()->create(['project_id' => $this->project->id]);
        $sa = $this->makeUser('super_admin');

        $response = $this->actingAs($sa, 'sanctum')
            ->deleteJson("/api/milestones/{$milestone->id}");

        $response->assertOk();
    }

    /**
     * Expand legacy granular flags into the equivalent explicit permissions
     * (Phase 3, ADR-UNIFIED-ROLE-ACCESS — the flag columns were dropped from
     * scoped_role_definitions; the engine now reads permissions[] only).
     *
     * @param  array<int, string>  $permissions
     * @param  array<string, bool>  $flags
     * @return array<int, string>
     */
    private function expandFlags(array $permissions, array $flags): array
    {
        $byAction = fn (array $actions): array => array_values(array_filter(
            Capability::all(),
            function (string $c) use ($actions) {
                $a = str_contains($c, '.') ? substr($c, strrpos($c, '.') + 1) : $c;

                return in_array($a, $actions, true);
            }
        ));
        if (! empty($flags['can_edit'])) {
            $permissions = array_merge($permissions, $byAction(['edit', 'update']));
        }
        if (! empty($flags['can_delete'])) {
            $permissions = array_merge($permissions, $byAction(['delete', 'remove']));
        }
        if (! empty($flags['can_view_all'])) {
            $permissions = array_merge($permissions, $byAction(['view', 'view_all', 'view_reports']));
        }
        if (! empty($flags['can_manage_members'])) {
            $permissions = array_merge($permissions, $byAction(['manage_members', 'assign_roles']));
        }
        if (! empty($flags['can_view_confidential'])) {
            $permissions[] = Capability::OVR_VIEW_CONFIDENTIAL;
        }

        return array_values(array_unique($permissions));
    }
}
