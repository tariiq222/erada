<?php

namespace Tests\Feature\Api\Core;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Core\Scopes\UserOrganizationScope;
use App\Modules\HR\Models\Department;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Phase 9-B — Organization hierarchy schema + model helpers coverage.
 *
 * يثبت:
 *   1. الـ migration يضيف الأعمدة والـ constraints والـ indexes بشكل idempotent
 *   2. القيم الافتراضية backward-compatible: existing orgs get type=organization
 *      و parent_id=null
 *   3. العلاقات (parent/children/activeChildren) تعمل
 *   4. الـ predicates (isCluster/isHospital/.../isRoot/isChildOf/hasChildren) صحيحة
 *   5. الـ child-type policy (canHaveChildren/allowedChildTypes/canAcceptChildType)
 *      يطابق القاموس الموعود
 *   6. الـ scopes (scopeRoots/scopeChildrenOf/scopeOfType) تفلتر صحيحاً
 *   7. CRITICAL: مستخدم في parent org لا يرى بيانات child org records
 *      (يثبت أن الـ engine لا يزال strict equality ولا يمشي شجرة المؤسسات)
 *
 * ملاحظة حاسمة — النطاق:
 * لا يختبر هذا الـ test أي feature في frontend ولا أي cluster_tree
 * (مؤجّل لـ Phase 9-D). الـ test يثبت فقط أن البيانات الجديدة لا تكسر
 * العزل الحالي.
 */
class OrganizationHierarchyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    // ===========================================================
    // 1. Schema verification — أعمدة وconstraints وindexes موجودة
    // ===========================================================

    public function test_organizations_table_has_hierarchy_columns(): void
    {
        $this->assertTrue(Schema::hasColumn('organizations', 'parent_id'));
        $this->assertTrue(Schema::hasColumn('organizations', 'type'));
        $this->assertTrue(Schema::hasColumn('organizations', 'sort_order'));
    }

    public function test_parent_id_is_nullable(): void
    {
        $org = Organization::factory()->create();
        $this->assertNull($org->fresh()->parent_id);
    }

    public function test_type_defaults_to_organization_for_existing_rows(): void
    {
        $org = Organization::factory()->create();
        $this->assertSame(Organization::TYPE_ORGANIZATION, $org->fresh()->type);
    }

    public function test_type_check_constraint_is_enforced(): void
    {
        // أدخل قيمة خارج القاموس المسموح — يجب أن يفشل الـ CHECK
        $this->expectException(QueryException::class);

        DB::table('organizations')->insert([
            'name' => 'invalid',
            'code' => 'INVALID-TYPE',
            'type' => 'ministry', // غير مسموح
            'parent_id' => null,
            'is_active' => true,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_parent_id_not_self_check_constraint_is_enforced(): void
    {
        $org = Organization::factory()->create();

        // محاولة self-reference: parent_id = id — يجب أن يفشل
        $this->expectException(QueryException::class);

        DB::table('organizations')->where('id', $org->id)->update([
            'parent_id' => $org->id,
        ]);
    }

    public function test_parent_id_fk_constraint_rejects_orphan(): void
    {
        $this->expectException(QueryException::class);

        DB::table('organizations')->insert([
            'name' => 'orphan',
            'code' => 'ORPHAN-1',
            'type' => Organization::TYPE_HOSPITAL,
            'parent_id' => 999999, // غير موجود
            'is_active' => true,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_organization_index_exists(): void
    {
        $indexes = collect(DB::select(
            "SELECT indexname FROM pg_indexes WHERE tablename = 'organizations' AND schemaname = current_schema()"
        ))->pluck('indexname')->all();

        $this->assertContains('organizations_parent_id_idx', $indexes);
        $this->assertContains('organizations_type_idx', $indexes);
        $this->assertContains('organizations_parent_type_idx', $indexes);
    }

    public function test_organization_check_constraints_exist(): void
    {
        $constraints = collect(DB::select(
            "SELECT constraint_name FROM information_schema.table_constraints
              WHERE table_name = 'organizations'
                AND constraint_type = 'CHECK'
                AND table_schema = current_schema()"
        ))->pluck('constraint_name')->all();

        $this->assertContains('organizations_type_check', $constraints);
        $this->assertContains('organizations_parent_id_not_self_check', $constraints);
    }

    // ===========================================================
    // 2. Backward compatibility — القيم الافتراضية للبيانات الحالية
    // ===========================================================

    public function test_existing_organization_factory_creates_with_type_organization_and_null_parent(): void
    {
        $org = Organization::factory()->create();

        $this->assertSame(Organization::TYPE_ORGANIZATION, $org->type);
        $this->assertNull($org->parent_id);
        $this->assertSame(0, (int) $org->sort_order);
    }

    public function test_existing_orgs_backfill_to_type_organization_on_migration(): void
    {
        // محاكاة صف قديم بدون type
        DB::table('organizations')->insert([
            'name' => 'legacy',
            'code' => 'LEGACY-1',
            'is_active' => true,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // تشغيل backfill الـ migration يدوياً (WHERE type IS NULL)
        DB::statement("
            UPDATE organizations
               SET type = 'organization'
             WHERE type IS NULL OR type = ''
        ");

        $row = DB::table('organizations')->where('code', 'LEGACY-1')->first();
        $this->assertSame(Organization::TYPE_ORGANIZATION, $row->type);
        $this->assertNull($row->parent_id);
    }

    // ===========================================================
    // 3. Relations — parent / children / activeChildren
    // ===========================================================

    public function test_organization_parent_returns_parent_organization(): void
    {
        $cluster = Organization::factory()->cluster()->create();
        $hospital = Organization::factory()->hospital()->childOf($cluster)->create();

        $this->assertNotNull($hospital->parent);
        $this->assertSame($cluster->id, $hospital->parent->id);
        $this->assertTrue($hospital->parent->isCluster());
    }

    public function test_organization_parent_returns_null_for_root(): void
    {
        $root = Organization::factory()->create();

        $this->assertNull($root->parent);
    }

    public function test_organization_children_returns_direct_children(): void
    {
        $cluster = Organization::factory()->cluster()->create();
        $h1 = Organization::factory()->hospital()->childOf($cluster)->create();
        $h2 = Organization::factory()->hospital()->childOf($cluster)->create();
        // ابن غير مباشر (root) — لا يجب أن يظهر
        $standalone = Organization::factory()->create();

        $children = $cluster->children;
        $this->assertCount(2, $children);
        $this->assertTrue($children->contains($h1));
        $this->assertTrue($children->contains($h2));
        $this->assertFalse($children->contains($standalone));
    }

    public function test_organization_active_children_filters_inactive(): void
    {
        $cluster = Organization::factory()->cluster()->create();
        $active = Organization::factory()->hospital()->childOf($cluster)->create();
        $inactive = Organization::factory()->hospital()->childOf($cluster)->inactive()->create();

        $activeChildren = $cluster->activeChildren;
        $this->assertCount(1, $activeChildren);
        $this->assertTrue($activeChildren->contains($active));
        $this->assertFalse($activeChildren->contains($inactive));
    }

    // ===========================================================
    // 4. Type predicates
    // ===========================================================

    public function test_is_cluster_returns_true_for_cluster_type(): void
    {
        $org = Organization::factory()->cluster()->create();
        $this->assertTrue($org->isCluster());
        $this->assertFalse($org->isHospital());
        $this->assertFalse($org->isCenter());
        $this->assertFalse($org->isStandaloneOrganization());
        $this->assertFalse($org->isOther());
    }

    public function test_is_hospital_returns_true_for_hospital_type(): void
    {
        $org = Organization::factory()->hospital()->create();
        $this->assertTrue($org->isHospital());
        $this->assertFalse($org->isCluster());
    }

    public function test_is_center_returns_true_for_center_type(): void
    {
        $org = Organization::factory()->center()->create();
        $this->assertTrue($org->isCenter());
    }

    public function test_is_standalone_organization_returns_true_for_organization_type(): void
    {
        $org = Organization::factory()->create();
        $this->assertTrue($org->isStandaloneOrganization());
    }

    public function test_is_other_returns_true_for_other_type(): void
    {
        $org = Organization::factory()->ofType(Organization::TYPE_OTHER)->create();
        $this->assertTrue($org->isOther());
    }

    // ===========================================================
    // 5. Tree predicates
    // ===========================================================

    public function test_is_root_returns_true_when_parent_id_is_null(): void
    {
        $root = Organization::factory()->create();
        $this->assertTrue($root->isRoot());

        $child = Organization::factory()->childOf($root)->create();
        $this->assertFalse($child->isRoot());
    }

    public function test_is_child_of_returns_true_for_direct_child(): void
    {
        $parent = Organization::factory()->create();
        $child = Organization::factory()->childOf($parent)->create();

        $this->assertTrue($child->isChildOf($parent));
        $this->assertFalse($parent->isChildOf($child));
    }

    public function test_is_child_of_returns_false_for_unrelated_org(): void
    {
        $a = Organization::factory()->create();
        $b = Organization::factory()->create();

        $this->assertFalse($a->isChildOf($b));
        $this->assertFalse($b->isChildOf($a));
    }

    public function test_has_children_returns_true_when_child_exists(): void
    {
        $parent = Organization::factory()->create();
        Organization::factory()->childOf($parent)->create();

        $this->assertTrue($parent->hasChildren());
    }

    public function test_has_children_returns_false_when_no_children(): void
    {
        $org = Organization::factory()->create();
        $this->assertFalse($org->hasChildren());
    }

    public function test_has_children_returns_false_for_brand_new_unsaved_instance(): void
    {
        $org = new Organization;
        $this->assertFalse($org->hasChildren());
    }

    // ===========================================================
    // 6. Child-type policy
    // ===========================================================

    public function test_cluster_can_have_children(): void
    {
        $cluster = Organization::factory()->cluster()->create();
        $this->assertTrue($cluster->canHaveChildren());
        $this->assertContains(Organization::TYPE_HOSPITAL, $cluster->allowedChildTypes());
        $this->assertContains(Organization::TYPE_CENTER, $cluster->allowedChildTypes());
        $this->assertContains(Organization::TYPE_ORGANIZATION, $cluster->allowedChildTypes());
    }

    public function test_hospital_cannot_have_children_in_phase_9b(): void
    {
        $hospital = Organization::factory()->hospital()->create();
        $this->assertFalse($hospital->canHaveChildren());
        $this->assertSame([], $hospital->allowedChildTypes());
    }

    public function test_can_accept_child_type_for_cluster(): void
    {
        $cluster = Organization::factory()->cluster()->create();
        $this->assertTrue($cluster->canAcceptChildType(Organization::TYPE_HOSPITAL));
        $this->assertTrue($cluster->canAcceptChildType(Organization::TYPE_CENTER));
        $this->assertFalse($cluster->canAcceptChildType(Organization::TYPE_CLUSTER), 'لا يقبل cluster ابن cluster في المرحلة الأولى');
    }

    public function test_hospital_rejects_hospital_under_hospital_in_phase_9b(): void
    {
        $hospital = Organization::factory()->hospital()->create();
        $this->assertFalse($hospital->canAcceptChildType(Organization::TYPE_HOSPITAL));
    }

    public function test_can_accept_child_type_for_unknown_type_returns_false(): void
    {
        $cluster = Organization::factory()->cluster()->create();
        $this->assertFalse($cluster->canAcceptChildType('ministry'));
    }

    // ===========================================================
    // 7. Scopes
    // ===========================================================

    public function test_scope_roots_returns_only_orgs_with_null_parent(): void
    {
        $root1 = Organization::factory()->create();
        $root2 = Organization::factory()->create();
        $child = Organization::factory()->childOf($root1)->create();

        $roots = Organization::roots()->pluck('id')->all();

        $this->assertContains($root1->id, $roots);
        $this->assertContains($root2->id, $roots);
        $this->assertNotContains($child->id, $roots);
    }

    public function test_scope_children_of_filters_to_specific_parent(): void
    {
        $a = Organization::factory()->create();
        $b = Organization::factory()->create();
        $childA1 = Organization::factory()->childOf($a)->create();
        $childA2 = Organization::factory()->childOf($a)->create();
        $childB = Organization::factory()->childOf($b)->create();

        $aChildren = Organization::childrenOf($a->id)->pluck('id')->all();
        $this->assertCount(2, $aChildren);
        $this->assertContains($childA1->id, $aChildren);
        $this->assertContains($childA2->id, $aChildren);
        $this->assertNotContains($childB->id, $aChildren);
    }

    public function test_scope_of_type_filters_to_specific_type(): void
    {
        $cluster = Organization::factory()->cluster()->create();
        $hospital = Organization::factory()->hospital()->create();
        $org = Organization::factory()->create();

        $hospitals = Organization::ofType(Organization::TYPE_HOSPITAL)->pluck('id')->all();

        $this->assertContains($hospital->id, $hospitals);
        $this->assertNotContains($cluster->id, $hospitals);
        $this->assertNotContains($org->id, $hospitals);
    }

    // ===========================================================
    // 8. CRITICAL — Auth regression: parent org user cannot see child org data
    // ===========================================================

    /**
     * السيناريو الواقعي:
     *   - التجمع الصحي (cluster) = orgCluster
     *   - مستشفى تابع للتجمع (parent_id = orgCluster.id) = orgHospital
     *   - userCluster في orgCluster
     *   - userHospital في orgHospital
     *
     * المتوقّع: userCluster لا يستطيع رؤية userHospital عبر /api/users
     * (وإلا فالـ engine يكسر العزل — failure).
     */
    public function test_user_in_cluster_org_cannot_see_user_in_child_hospital_org_via_users_index(): void
    {
        $orgCluster = Organization::factory()->cluster()->create();
        $orgHospital = Organization::factory()->hospital()->childOf($orgCluster)->create();

        $deptCluster = Department::factory()->create(['organization_id' => $orgCluster->id]);
        $deptHospital = Department::factory()->create(['organization_id' => $orgHospital->id]);

        $userCluster = User::factory()->create([
            'organization_id' => $orgCluster->id,
            'department_id' => $deptCluster->id,
            'is_active' => true,
        ]);
        $userCluster->assignRole('admin');

        $userHospital = User::factory()->create([
            'organization_id' => $orgHospital->id,
            'department_id' => $deptHospital->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($userCluster, 'sanctum')
            ->getJson('/api/users')
            ->assertOk();

        $data = $response->json('data');
        $ids = collect($data)->pluck('id')->all();

        $this->assertContains($userCluster->id, $ids, 'cluster user must see self');
        $this->assertNotContains(
            $userHospital->id,
            $ids,
            'CRITICAL: cluster user must NOT see child-org user — parent_id must not widen isolation'
        );
    }

    /**
     * السيناريو المعاكس — user في مستشفى لا يرى user في الـ cluster التابع له.
     */
    public function test_user_in_child_hospital_cannot_see_user_in_parent_cluster_org_via_users_index(): void
    {
        $orgCluster = Organization::factory()->cluster()->create();
        $orgHospital = Organization::factory()->hospital()->childOf($orgCluster)->create();

        $deptCluster = Department::factory()->create(['organization_id' => $orgCluster->id]);
        $deptHospital = Department::factory()->create(['organization_id' => $orgHospital->id]);

        $userCluster = User::factory()->create([
            'organization_id' => $orgCluster->id,
            'department_id' => $deptCluster->id,
            'is_active' => true,
        ]);

        $userHospital = User::factory()->create([
            'organization_id' => $orgHospital->id,
            'department_id' => $deptHospital->id,
            'is_active' => true,
        ]);
        $userHospital->assignRole('admin');

        $response = $this->actingAs($userHospital, 'sanctum')
            ->getJson('/api/users')
            ->assertOk();

        $data = $response->json('data');
        $ids = collect($data)->pluck('id')->all();

        $this->assertContains($userHospital->id, $ids);
        $this->assertNotContains(
            $userCluster->id,
            $ids,
            'CRITICAL: child-org user must NOT see parent-org user — strict equality holds'
        );
    }

    /**
     * super_admin لا يزال يرى كل المنظمات بما فيها children (لم يكسر الـ super_admin short-circuit).
     */
    public function test_super_admin_still_sees_all_orgs_after_hierarchy_added(): void
    {
        $orgCluster = Organization::factory()->cluster()->create();
        $orgHospital = Organization::factory()->hospital()->childOf($orgCluster)->create();

        $deptCluster = Department::factory()->create(['organization_id' => $orgCluster->id]);

        $superAdmin = User::factory()->create([
            'organization_id' => $orgCluster->id,
            'department_id' => $deptCluster->id,
            'is_active' => true,
        ]);
        $superAdmin->assignRole('super_admin');

        $response = $this->actingAs($superAdmin, 'sanctum')
            ->getJson('/api/organizations')
            ->assertOk();

        $data = $response->json('data');
        $ids = collect($data)->pluck('id')->all();

        $this->assertContains($orgCluster->id, $ids);
        $this->assertContains($orgHospital->id, $ids, 'super_admin must see both cluster and child hospital');
    }

    /**
     * مستخدم في cluster لا يستطيع رؤية user نشط في child org — حتى عبر show endpoint.
     */
    public function test_user_in_cluster_cannot_show_user_in_child_org(): void
    {
        $orgCluster = Organization::factory()->cluster()->create();
        $orgHospital = Organization::factory()->hospital()->childOf($orgCluster)->create();

        $deptCluster = Department::factory()->create(['organization_id' => $orgCluster->id]);
        $deptHospital = Department::factory()->create(['organization_id' => $orgHospital->id]);

        $userCluster = User::factory()->create([
            'organization_id' => $orgCluster->id,
            'department_id' => $deptCluster->id,
            'is_active' => true,
        ]);
        $userCluster->assignRole('admin');

        $userHospital = User::factory()->create([
            'organization_id' => $orgHospital->id,
            'department_id' => $deptHospital->id,
            'is_active' => true,
        ]);

        $this->actingAs($userCluster, 'sanctum')
            ->getJson("/api/users/{$userHospital->id}")
            ->assertStatus(403);
    }

    /**
     * مستخدم في cluster لا يستطيع رؤية department تابع لـ child org
     * (يثبت نفس العزل عبر UserOrganizationScope — فالـ engine لا يفرّق
     * بين Users و Departments في العزل).
     */
    public function test_user_in_cluster_cannot_see_child_org_user_via_user_organization_scope(): void
    {
        $orgCluster = Organization::factory()->cluster()->create();
        $orgHospital = Organization::factory()->hospital()->childOf($orgCluster)->create();

        $deptCluster = Department::factory()->create(['organization_id' => $orgCluster->id]);
        $deptHospital = Department::factory()->create(['organization_id' => $orgHospital->id]);

        $userCluster = User::factory()->create([
            'organization_id' => $orgCluster->id,
            'department_id' => $deptCluster->id,
            'is_active' => true,
        ]);

        $userHospital = User::factory()->create([
            'organization_id' => $orgHospital->id,
            'department_id' => $deptHospital->id,
            'is_active' => true,
        ]);

        // تأكيد مباشر على أن استعلام users بـ scope = org.id الـ cluster
        // لا يعيد user في child org — هذا هو قلب العزل
        $scope = new UserOrganizationScope;
        $query = User::query();
        $filtered = $scope->applyToUsers($query, $userCluster);

        $returnedIds = $filtered->pluck('id')->all();

        $this->assertContains($userCluster->id, $returnedIds);
        $this->assertNotContains(
            $userHospital->id,
            $returnedIds,
            'CRITICAL: UserOrganizationScope لـ cluster user لا يجب أن يعيد users في child org'
        );
    }

    // ===========================================================
    // 9. Counters
    // ===========================================================

    public function test_active_children_count_excludes_inactive(): void
    {
        $cluster = Organization::factory()->cluster()->create();
        Organization::factory()->hospital()->childOf($cluster)->create();
        Organization::factory()->hospital()->childOf($cluster)->inactive()->create();

        $this->assertSame(1, $cluster->activeChildrenCount());
    }

    public function test_active_children_count_returns_zero_for_new_unsaved_instance(): void
    {
        $org = new Organization;
        $this->assertSame(0, $org->activeChildrenCount());
    }
}
