<?php

namespace Tests\Feature\Api;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\ScopedRole;
use App\Modules\Core\Models\ScopeType;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * Phase 4.5 — Projects organization isolation regressions.
 *
 * يثبت SC1/SC2/SC3/SC4 من Phase 4.5:
 * - SC1: مستخدم (project_manager أو admin) في مؤسسة A يُمنع view/update/delete/manageMembers
 *        على مشروع مؤسسة B حتى لو كان يحمل الصلاحية العامة view_projects/edit_projects/delete_projects.
 * - SC2: addMember/removeMember يرفضان هدفاً من مؤسسة B (cross-org target user) في مشروع مؤسسة A لغير super.
 * - SC3: null-org لغير super_admin → 403 على كل ability.
 * - SC4: super_admin (مع أو بدون organization_id) يتجاوز org-floor.
 * - ترتيب assertSameOrg: يُثبت كأول سطر في كل ability — الفحوصات اللاحقة (Spatie, isProjectAdmin, isProjectLeader)
 *   تظل تشتغل بعد اجتياز org-floor.
 */
class ProjectOrganizationScopeTest extends TestCase
{
    use DatabaseTransactions;
    use GrantsEngineCapability;

    protected Organization $orgA;

    protected Organization $orgB;

    protected Department $deptA;

    protected Department $deptB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeaders(['X-Skip-Csrf' => '1']);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->orgA = Organization::factory()->create();
        $this->orgB = Organization::factory()->create();

        $this->deptA = Department::factory()->create(['organization_id' => $this->orgA->id]);
        $this->deptB = Department::factory()->create(['organization_id' => $this->orgB->id]);

        Cache::flush();
        $this->seedProjectScopeDefinitions();
        $this->seedOrgScopeDefinitions();
    }

    /**
     * ينشئ ScopeType=project وتعريفات الأدوار للمحرّك (engine=ON).
     */
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
                'permissions' => json_encode($this->expandFlags(
                    ['projects.view', 'projects.edit', 'projects.manage_members', 'projects.assign_roles'],
                    ['can_manage_members' => true, 'can_edit' => true, 'can_delete' => false, 'can_view_all' => true]
                )),
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
        ];

        foreach ($definitions as $def) {
            $exists = DB::table('scoped_role_definitions')
                ->where('name', $def['name'])
                ->where('scope_type', $def['scope_type'])
                ->exists();

            if (! $exists) {
                DB::table('scoped_role_definitions')->insert($def);
            }
        }

        Cache::flush();
    }

    /**
     * ينشئ ScopeType=organization وتعريف دور admin (is_admin_role=true).
     * اللازم لاختبارات addMember/removeMember حيث manageMembers يمر بالمحرّك.
     */
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

    /**
     * يُسند للمستخدم دوراً سياقياً على مستوى المؤسسة (admin).
     */
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

    // ========== Helpers ==========

    /**
     * إنشاء مستخدم بدور اختياري. عند null → مستخدم بلا مؤسسة (SC3).
     */
    private function makeUser(?Organization $org, ?string $role = null): User
    {
        $deptId = $org ? $this->deptForOrg($org)->id : null;

        $user = User::factory()->create([
            'organization_id' => $org?->id,
            'department_id' => $deptId,
            'is_active' => true,
        ]);
        if ($role) {
            $user->assignRole($role);
        }

        return $user;
    }

    private function deptForOrg(Organization $org): Department
    {
        return $org->id === $this->orgA->id ? $this->deptA : $this->deptB;
    }

    /**
     * إنشاء مشروع في مؤسسة محددة. organization_id يُمرّر صراحةً
     * حتى تكون دلالات الـ org-floor واضحة في كل اختبار.
     */
    private function makeProject(Organization $org, array $overrides = []): Project
    {
        return Project::factory()->create(array_merge([
            'organization_id' => $org->id,
            'department_id' => $this->deptForOrg($org)->id,
        ], $overrides));
    }

    /**
     * Admin actor يحمل كل الصلاحيات العامة للمشاريع. المنح الصريح يعزل
     * org-floor عن permission denial في اختبار cross-org.
     * مع engine=ON: نُسند له org-scoped admin role لمنحه صلاحيات view/edit/delete/manageMembers.
     * manage_organization لازمة لـ ProjectAuthorizationService::isAdmin() في مسار addMember/removeMember.
     */
    private function makeProjectAdmin(?Organization $org): User
    {
        $user = $this->makeUser($org, 'admin');
        $user->givePermissionTo(['view_projects', 'edit_projects', 'delete_projects']);
        $this->grantEngineCapability($user, Capability::SETTINGS_MANAGE);
        $this->grantOrgAdminScopedRole($user);

        return $user;
    }

    /**
     * Project manager actor يحمل نفس الصلاحيات العامة للـ admin. هذا الدور
     * يتجاوز فحوصات isAdmin()-only ويسمح لنا بعزل org-floor عن isAdmin check
     * (D-08, D-09).
     */
    private function makeProjectManagerActor(?Organization $org): User
    {
        $user = $this->makeUser($org, 'viewer');
        $user->givePermissionTo(['view_projects', 'edit_projects', 'delete_projects']);

        return $user;
    }

    private function assertDeniedByIsolation(int $status, string $message): void
    {
        $this->assertContains($status, [403, 404], $message);
    }

    private function assertRejectedCrossOrgWrite(int $status, string $message): void
    {
        $this->assertContains($status, [403, 422], $message);
    }

    // ========== SC1: project_manager cross-org ==========

    public function test_cross_org_project_manager_cannot_view_org_b_project(): void
    {
        $actor = $this->makeProjectManagerActor($this->orgA);
        $projectB = $this->makeProject($this->orgB);

        $this->assertDeniedByIsolation(
            $this->actingAs($actor, 'sanctum')->getJson("/api/projects/{$projectB->id}")->status(),
            'يجب منع قراءة مشروع مؤسسة أخرى لمدير مشروع'
        );
    }

    public function test_cross_org_project_manager_cannot_update_org_b_project(): void
    {
        $actor = $this->makeProjectManagerActor($this->orgA);
        $projectB = $this->makeProject($this->orgB);

        $this->assertDeniedByIsolation(
            $this->actingAs($actor, 'sanctum')->putJson("/api/projects/{$projectB->id}", [
                'name' => 'محاولة تعديل عابرة للمؤسسات',
                'status' => 'in_progress',
            ])->status(),
            'يجب منع تعديل مشروع مؤسسة أخرى لمدير مشروع'
        );
    }

    public function test_cross_org_project_manager_cannot_delete_org_b_project(): void
    {
        $actor = $this->makeProjectManagerActor($this->orgA);
        $projectB = $this->makeProject($this->orgB);

        $this->assertDeniedByIsolation(
            $this->actingAs($actor, 'sanctum')->deleteJson("/api/projects/{$projectB->id}")->status(),
            'يجب منع حذف مشروع مؤسسة أخرى لمدير مشروع'
        );
        $this->assertDatabaseHas('projects', ['id' => $projectB->id, 'deleted_at' => null]);
    }

    public function test_cross_org_project_manager_cannot_manage_members_of_org_b_project(): void
    {
        $actor = $this->makeProjectManagerActor($this->orgA);
        $projectB = $this->makeProject($this->orgB);

        // عزل فحص الصلاحية عن فحص الـ org-floor (D-09): نتأكد أن الفاعل
        // يحمل edit_projects أولاً حتى لا يُفشل الاختبار بسبب permission
        // denial بدل org-floor denial.
        $this->assertTrue(
            $actor->can('edit_projects'),
            'project_manager actor must hold edit_projects permission for this test to isolate the org-floor'
        );

        // لا توجد route مباشرة لـ manageMembers (addMember يستخدم 'update' في
        // authService) فنختبر الـ policy ability مباشرة عبر Gate. نستخدم
        // denies() (يرجع bool) لا authorize() (يرمي exception) لأن النوعين
        // لا يمكن chain معاً.
        $this->assertTrue(
            Gate::forUser($actor)->denies('manageMembers', $projectB),
            'cross-org project_manager must be denied manageMembers by the org-floor'
        );
    }

    // ========== SC1 (admin variant): cross-org admin ==========

    public function test_cross_org_admin_cannot_view_update_delete_or_manage_org_b_project(): void
    {
        $actor = $this->makeProjectAdmin($this->orgA);
        $projectB = $this->makeProject($this->orgB);

        $this->assertDeniedByIsolation(
            $this->actingAs($actor, 'sanctum')->getJson("/api/projects/{$projectB->id}")->status(),
            'يجب منع admin من قراءة مشروع مؤسسة أخرى'
        );
        $this->assertDeniedByIsolation(
            $this->actingAs($actor, 'sanctum')->putJson("/api/projects/{$projectB->id}", [
                'name' => 'محاولة admin',
                'status' => 'in_progress',
            ])->status(),
            'يجب منع admin من تعديل مشروع مؤسسة أخرى'
        );
        $this->assertDeniedByIsolation(
            $this->actingAs($actor, 'sanctum')->deleteJson("/api/projects/{$projectB->id}")->status(),
            'يجب منع admin من حذف مشروع مؤسسة أخرى'
        );
        $this->assertTrue(
            Gate::forUser($actor)->denies('manageMembers', $projectB),
            'cross-org admin must be denied manageMembers by the org-floor'
        );
    }

    // ========== SC2: cross-org target user on addMember / removeMember ==========

    public function test_add_member_rejects_cross_org_target_user(): void
    {
        $actor = $this->makeProjectAdmin($this->orgA);
        $projectA = $this->makeProject($this->orgA);
        $userB = $this->makeUser($this->orgB);
        $userA = $this->makeUser($this->orgA);

        // cross-org target → رفض
        $this->assertRejectedCrossOrgWrite(
            $this->actingAs($actor, 'sanctum')->postJson("/api/projects/{$projectA->id}/members", [
                'user_id' => $userB->id,
                'role' => 'member',
            ])->status(),
            'يجب رفض addMember بهدف مستخدم من مؤسسة أخرى'
        );

        // positive control: same-org target → نجاح
        $this->actingAs($actor, 'sanctum')
            ->postJson("/api/projects/{$projectA->id}/members", [
                'user_id' => $userA->id,
                'role' => 'member',
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('model_has_scoped_roles', [
            'scope_id' => $projectA->id,
            'user_id' => $userA->id,
        ]);
    }

    public function test_remove_member_rejects_cross_org_target_user(): void
    {
        $actor = $this->makeProjectAdmin($this->orgA);
        $projectA = $this->makeProject($this->orgA);
        $userA = $this->makeUser($this->orgA);
        $userB = $this->makeUser($this->orgB);

        // positive setup: assign a same-org member so the DELETE call has a real member to remove
        $userA->assignProjectRole($projectA, ScopedRole::PROJECT_MEMBER);
        $this->assertDatabaseHas('model_has_scoped_roles', [
            'scope_id' => $projectA->id,
            'user_id' => $userA->id,
        ]);

        // cross-org target → رفض (حتى لو لم يكن مضافاً، D-06)
        $this->assertDeniedByIsolation(
            $this->actingAs($actor, 'sanctum')->deleteJson("/api/projects/{$projectA->id}/members/{$userB->id}")->status(),
            'يجب رفض removeMember بهدف مستخدم من مؤسسة أخرى'
        );

        // positive control: same-org target → نجاح
        $this->actingAs($actor, 'sanctum')
            ->deleteJson("/api/projects/{$projectA->id}/members/{$userA->id}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('model_has_scoped_roles', [
            'scope_id' => $projectA->id,
            'user_id' => $userA->id,
        ]);
    }

    // ========== SC3: null-org non-super denied on every Projects ability ==========

    public function test_null_org_non_super_admin_is_denied_on_every_projects_ability(): void
    {
        $actor = $this->makeProjectAdmin(null);
        $projectB = $this->makeProject($this->orgB);
        $userA = $this->makeUser($this->orgA); // هدف cross-org لإثارة guard الـ target-user

        // controller path: addMember مع هدف cross-org يُرفض بـ 403 من
        // guard الـ target-user floor (العضو والمشروع يجب أن ينتميا لنفس
        // المؤسسة) — هذا يثبت أن null-org admin لا يستطيع تهريب عضو من
        // مؤسسة أخرى إلى مشروع مؤسسة B. الـ target-user floor في
        // addMember/removeMember يطبّق بغض النظر عن actor's org-null status.
        $this->assertRejectedCrossOrgWrite(
            $this->actingAs($actor, 'sanctum')->postJson("/api/projects/{$projectB->id}/members", [
                'user_id' => $userA->id,
                'role' => 'member',
            ])->status(),
            'null-org admin must be denied addMember with cross-org target user'
        );

        // policy-level: project_manager actor بنفس الشكل يُرفض على Policy
        // مباشرة. هذا يثبت أن الـ policy org-floor وحده كافٍ لمنع null-org
        // actor من الوصول لمشروع مؤسسة أخرى عبر كل ability (view/update/
        // delete/manageMembers). نستخدم project_manager بدل admin لأن الـ
        // ProjectPolicy لا يمر عبر ProjectAuthorizationService (الذي يعمل
        // short-circuit على isAdmin()).
        $pmActor = $this->makeProjectManagerActor(null);

        $this->assertTrue(
            Gate::forUser($pmActor)->denies('view', $projectB),
            'null-org project_manager must be denied view by the policy org-floor'
        );
        $this->assertTrue(
            Gate::forUser($pmActor)->denies('update', $projectB),
            'null-org project_manager must be denied update by the policy org-floor'
        );
        $this->assertTrue(
            Gate::forUser($pmActor)->denies('delete', $projectB),
            'null-org project_manager must be denied delete by the policy org-floor'
        );
        $this->assertTrue(
            Gate::forUser($pmActor)->denies('manageMembers', $projectB),
            'null-org project_manager must be denied manageMembers by the policy org-floor'
        );
    }

    // ========== SC4: super_admin bypass ==========

    public function test_super_admin_can_access_projects_across_organizations(): void
    {
        $superAdmin = $this->makeUser($this->orgA, 'super_admin');
        $projectB = $this->makeProject($this->orgB);
        $userB = $this->makeUser($this->orgB);

        $this->actingAs($superAdmin, 'sanctum')
            ->getJson("/api/projects/{$projectB->id}")
            ->assertStatus(200);

        $this->actingAs($superAdmin, 'sanctum')
            ->putJson("/api/projects/{$projectB->id}", [
                'name' => 'super_admin edit',
                'status' => 'in_progress',
            ])
            ->assertStatus(200);

        $this->actingAs($superAdmin, 'sanctum')
            ->deleteJson("/api/projects/{$projectB->id}")
            ->assertStatus(200);

        $this->assertSoftDeleted('projects', ['id' => $projectB->id]);
    }

    public function test_null_org_super_admin_is_not_blocked_by_projects_org_check(): void
    {
        $nullOrgSuperAdmin = $this->makeUser(null, 'super_admin');
        $projectB = $this->makeProject($this->orgB);
        $userA = $this->makeUser($this->orgA);

        $this->actingAs($nullOrgSuperAdmin, 'sanctum')
            ->getJson("/api/projects/{$projectB->id}")
            ->assertStatus(200);

        // null-org super_admin يجب أن يستطيع إضافة عضو (بعد اجتياز view perms)
        $this->actingAs($nullOrgSuperAdmin, 'sanctum')
            ->postJson("/api/projects/{$projectB->id}/members", [
                'user_id' => $userA->id,
                'role' => 'member',
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('model_has_scoped_roles', [
            'scope_id' => $projectB->id,
            'user_id' => $userA->id,
        ]);
    }

    // ========== Same-org positive controls (D-05 ordering) ==========

    public function test_same_org_actor_passes_org_floor_and_existing_checks_still_apply(): void
    {
        $actor = $this->makeProjectAdmin($this->orgA);
        $projectA = $this->makeProject($this->orgA);
        // المدير يُمثَّل كدور سياقي (scoped role) لا كعمود manager_id
        $actor->assignProjectRole($projectA, ScopedRole::PROJECT_MANAGER, $actor->id);

        // GET → 200 (org-floor نجح، الصلاحية العامة + admin department access يمر)
        $this->actingAs($actor, 'sanctum')
            ->getJson("/api/projects/{$projectA->id}")
            ->assertStatus(200);

        // PUT → 200 (org-floor نجح + edit_projects + admin department access + scoped manager)
        $this->actingAs($actor, 'sanctum')
            ->putJson("/api/projects/{$projectA->id}", [
                'name' => 'مشروع مؤسسة الفاعل',
                'status' => 'in_progress',
            ])
            ->assertStatus(200);

        $projectA->refresh();
        $this->assertSame('مشروع مؤسسة الفاعل', $projectA->name, 'PUT must update the same-org project successfully.');
        $this->assertSame('in_progress', $projectA->status);
    }

    public function test_null_org_project_is_denied_to_non_super_actor(): void
    {
        // مشروع يتيم (organization_id = null, department_id = null) — لا يُقبل
        // في الواقع لكنه قد يوجد لصف قديم. D-02: deny-not-bypass لغير super.
        $orphan = Project::factory()->create([
            'organization_id' => null,
            'department_id' => null,
        ]);

        $actor = $this->makeProjectAdmin($this->orgA);

        // ملاحظة: queryService->getProjectWithRelations يطبّق
        // where('organization_id', $user->organization_id) فيطبق فلتر على
        // orgA فيستبعد الـ orphan → findOrFail يرمي ModelNotFoundException
        // (404). هذا لا يزال رفضاً فعلياً — لا يوجد تسريب للمعلومات، لا
        // يصل الفاعل للـ project. الـ policy org-floor في assertSameOrg
        // سيعطي 403 لو وصل الـ request للـ policy؛ query service يوقفه
        // قبل ذلك. السلوكان متكافئان أمنياً. نقبل [403, 404].
        $this->assertDeniedByIsolation(
            $this->actingAs($actor, 'sanctum')->getJson("/api/projects/{$orphan->id}")->status(),
            'يجب منع قراءة مشروع يتيم لمستخدم من مؤسسة أخرى (403 من policy أو 404 من query filter)'
        );

        // policy-level (الأدق): project_manager actor من orgA يُرفض على
        // Policy مباشرة. هذا يثبت أن الـ policy floor وحده (بدون query
        // filter) كافٍ لمنع الوصول للـ orphan.
        $pmActor = $this->makeProjectManagerActor($this->orgA);
        $this->assertTrue(
            Gate::forUser($pmActor)->denies('view', $orphan),
            'null-org project must be denied to project_manager from another org by the policy org-floor'
        );
        $this->assertTrue(
            Gate::forUser($pmActor)->denies('update', $orphan),
            'null-org project must be denied update to project_manager from another org by the policy org-floor'
        );
        $this->assertTrue(
            Gate::forUser($pmActor)->denies('delete', $orphan),
            'null-org project must be denied delete to project_manager from another org by the policy org-floor'
        );

        $this->assertDatabaseHas('projects', ['id' => $orphan->id, 'deleted_at' => null]);
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
