<?php

namespace Tests\Feature\Authorization;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\OVR\Enums\ReportStatus;
use App\Modules\OVR\Enums\SeverityLevel;
use App\Modules\OVR\Models\IncidentReport;
use App\Modules\OVR\Models\IncidentType;
use App\Modules\OVR\Policies\IncidentReportPolicy;
use App\Modules\Projects\Models\Project;
use App\Modules\RiskManagement\Models\Risk;
use App\Modules\Strategy\Models\Portfolio;
use App\Modules\Strategy\Models\Program;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CutoverValidationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private Department $deptA;

    private Department $deptB;

    private IncidentType $incidentType;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->orgA = Organization::factory()->create();
        $this->orgB = Organization::factory()->create();
        $this->deptA = Department::factory()->create(['organization_id' => $this->orgA->id]);
        $this->deptB = Department::factory()->create(['organization_id' => $this->orgB->id]);

        $this->incidentType = IncidentType::create([
            'name' => 'Test Type',
            'name_ar' => 'نوع اختباري',
            'is_active' => true,
        ]);

        Cache::flush();
        $this->seedEngineDefinitions();
    }

    // ====================================================
    // GAP-STR05 Closed: cross-org Portfolio isolation
    // ====================================================

    public function test_str05_cross_org_portfolio_now_denied_with_strategy_flag_on(): void
    {
        config()->set('authz.modules.strategy', true);

        $userB = $this->makeUser('admin', $this->orgB->id, $this->deptB->id);
        $this->grantScopedRole($userB, 'admin', 'organization', $this->orgB->id);

        // Portfolio belonging to orgA — organization_id now set
        $portfolioA = Portfolio::factory()->create([
            'organization_id' => $this->orgA->id,
        ]);

        // AccessDecision: sameOrganization should now return false for orgB user + orgA portfolio
        $result = AccessDecision::can($userB, Capability::STRATEGY_VIEW, $portfolioA);

        $this->assertFalse($result, 'GAP-STR05 closed: cross-org portfolio must be DENIED when strategy flag is ON');

        config()->set('authz.modules.strategy', false);
    }

    public function test_str05_same_org_portfolio_owner_allowed(): void
    {
        config()->set('authz.modules.strategy', true);

        $owner = $this->makeUser('viewer', $this->orgA->id, $this->deptA->id);
        $portfolioA = Portfolio::factory()->create([
            'organization_id' => $this->orgA->id,
        ]);
        $this->grantScopedRole($owner, 'owner', 'portfolio', $portfolioA->id, inherit: true);

        $result = AccessDecision::can($owner, Capability::STRATEGY_EDIT, $portfolioA);
        $this->assertTrue($result, 'STR05: same-org portfolio owner must be ALLOWED');

        config()->set('authz.modules.strategy', false);
    }

    // ====================================================
    // OVR Confidentiality Gap Closed
    // ====================================================

    public function test_ovr_admin_without_can_view_confidential_denied_confidential_report(): void
    {
        config()->set('authz.modules.ovr', true);

        $admin = $this->makeUser('admin');
        $this->grantScopedRole($admin, 'admin', 'organization', $this->orgA->id);

        // Ensure admin role def does NOT carry ovr.confidential in permissions[]
        $orgType = DB::table('scope_types')->where('key', 'organization')->first();
        if ($orgType) {
            $definition = DB::table('scoped_role_definitions')
                ->where('scope_type_id', $orgType->id)
                ->where('role_key', 'admin')
                ->first();
            $permissions = array_values(array_diff(
                json_decode($definition->permissions, true) ?? [],
                [Capability::OVR_CONFIDENTIAL]
            ));
            DB::table('scoped_role_definitions')
                ->where('scope_type_id', $orgType->id)
                ->where('role_key', 'admin')
                ->update(['permissions' => json_encode($permissions)]);
        }
        Cache::flush();

        $report = $this->makeConfidentialReport();

        $policy = new IncidentReportPolicy;
        $result = $policy->view($admin, $report);

        $this->assertFalse($result, 'OVR-GAP: admin without OVR_CONFIDENTIAL must be DENIED confidential report');

        config()->set('authz.modules.ovr', false);
    }

    public function test_ovr_user_with_can_view_confidential_allowed_confidential_report(): void
    {
        config()->set('authz.modules.ovr', true);

        $user = $this->makeUser('admin');
        $this->grantScopedRole($user, 'admin', 'organization', $this->orgA->id);

        // Add ovr.confidential to this user's role def permissions[]
        $orgType = DB::table('scope_types')->where('key', 'organization')->first();
        if ($orgType) {
            $definition = DB::table('scoped_role_definitions')
                ->where('scope_type_id', $orgType->id)
                ->where('role_key', 'admin')
                ->first();
            $permissions = array_values(array_unique(array_merge(
                json_decode($definition->permissions, true) ?? [],
                [Capability::OVR_CONFIDENTIAL]
            )));
            DB::table('scoped_role_definitions')
                ->where('scope_type_id', $orgType->id)
                ->where('role_key', 'admin')
                ->update(['permissions' => json_encode($permissions)]);
        }
        Cache::flush();

        $report = $this->makeConfidentialReport();

        $policy = new IncidentReportPolicy;
        $result = $policy->view($user, $report);

        $this->assertTrue($result, 'OVR-GAP closed: user with the OVR_CONFIDENTIAL grant must be ALLOWED');

        config()->set('authz.modules.ovr', false);
    }

    public function test_ovr_reporter_can_always_see_own_confidential_report(): void
    {
        config()->set('authz.modules.ovr', true);

        $reporter = $this->makeUser('viewer');
        $this->grantScopedRole($reporter, 'viewer', 'organization', $this->orgA->id);

        $report = $this->makeConfidentialReport($reporter->id);

        $policy = new IncidentReportPolicy;
        $result = $policy->view($reporter, $report);

        $this->assertTrue($result, 'OVR: reporter can always view own confidential report');

        config()->set('authz.modules.ovr', false);
    }

    // ====================================================
    // Module-by-module flag activation (no new failures)
    // ====================================================

    public function test_projects_flag_on_no_new_failures(): void
    {
        config()->set('authz.modules.projects', true);

        $admin = $this->makeUser('admin');
        $this->grantScopedRole($admin, 'admin', 'organization', $this->orgA->id);
        $project = Project::factory()->create(['organization_id' => $this->orgA->id, 'department_id' => $this->deptA->id]);

        $this->assertTrue(AccessDecision::can($admin, Capability::PROJECTS_VIEW, $project), 'admin sees own project');

        $userB = $this->makeUser('admin', $this->orgB->id, $this->deptB->id);
        $this->assertFalse(AccessDecision::can($userB, Capability::PROJECTS_VIEW, $project), 'cross-org project denied');

        config()->set('authz.modules.projects', false);
    }

    public function test_strategy_flag_on_programs_chain_works(): void
    {
        config()->set('authz.modules.strategy', true);

        $manager = $this->makeUser('viewer');
        $portfolio = Portfolio::factory()->create(['organization_id' => $this->orgA->id]);
        $program = Program::factory()->create([
            'portfolio_id' => $portfolio->id,
            'organization_id' => $this->orgA->id,
        ]);
        $this->grantScopedRole($manager, 'program_manager', 'program', $program->id, inherit: true);

        $this->assertTrue(AccessDecision::can($manager, Capability::STRATEGY_EDIT, $program), 'program_manager can edit program');

        config()->set('authz.modules.strategy', false);
    }

    public function test_risks_flag_on_cross_org_denied(): void
    {
        config()->set('authz.modules.risks', true);

        $userB = $this->makeUser('admin', $this->orgB->id, $this->deptB->id);
        $this->grantScopedRole($userB, 'admin', 'organization', $this->orgB->id);

        $riskA = Risk::factory()->create(['organization_id' => $this->orgA->id, 'department_id' => $this->deptA->id]);

        $this->assertFalse(AccessDecision::can($userB, Capability::RISKS_VIEW, $riskA), 'cross-org risk denied');

        config()->set('authz.modules.risks', false);
    }

    public function test_super_admin_passes_all_flags_on(): void
    {
        foreach (['projects', 'tasks', 'departments', 'strategy', 'risks', 'ovr'] as $module) {
            config()->set("authz.modules.{$module}", true);
        }

        $sa = $this->makeUser('super_admin');

        $this->assertTrue(AccessDecision::can($sa, Capability::PROJECTS_VIEW, null), 'SA: projects');
        $this->assertTrue(AccessDecision::can($sa, Capability::STRATEGY_EDIT, null), 'SA: strategy');
        $this->assertTrue(AccessDecision::can($sa, Capability::RISKS_DELETE, null), 'SA: risks');

        foreach (['projects', 'tasks', 'departments', 'strategy', 'risks', 'ovr'] as $module) {
            config()->set("authz.modules.{$module}", false);
        }
    }

    // ====================================================
    // Migration idempotency
    // ====================================================

    public function test_portfolios_organization_id_column_exists(): void
    {
        $this->assertTrue(
            Schema::hasColumn('portfolios', 'organization_id'),
            'portfolios.organization_id column must exist'
        );
    }

    public function test_scoped_role_definitions_can_view_confidential_column_dropped(): void
    {
        // Phase 3 (ADR-UNIFIED-ROLE-ACCESS): the granular can_view_confidential
        // flag was backfilled into permissions[] and the column dropped. This
        // asserts the drop migration ran and the grant now lives in permissions[].
        $this->assertFalse(
            Schema::hasColumn('scoped_role_definitions', 'can_view_confidential'),
            'scoped_role_definitions.can_view_confidential column must be dropped post Phase 3'
        );
    }

    public function test_portfolio_scope_organization_id_returns_correct_value(): void
    {
        $portfolio = Portfolio::factory()->create(['organization_id' => $this->orgA->id]);
        $this->assertEquals($this->orgA->id, $portfolio->scopeOrganizationId(), 'Portfolio::scopeOrganizationId() must return the organization_id');
    }

    /**
     * /api/user يصدّر قدرات المحرّك على مستوى المؤسسة ضمن capabilities[]
     * (vocabulary موحّد module.action). بعد قطع Phase 9.3 تمّ إزالة الـ
     * permissions[] الـ flat Spatie القديم — الـ canonical capabilities هي
     * المصدر الوحيد الآن.
     */
    public function test_me_endpoint_exposes_engine_capabilities(): void
    {
        $user = $this->makeUser('admin', $this->orgA->id, $this->deptA->id);
        $this->grantScopedRole($user, 'admin', 'organization', $this->orgA->id);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/user');

        $response->assertOk();

        $capabilities = $response->json('user.capabilities');
        $this->assertIsArray($capabilities);

        $this->assertContains(
            Capability::STRATEGY_VIEW,
            $capabilities,
            'قدرة المحرّك على مستوى المؤسسة يجب أن تظهر في /api/user'
        );

        // بعد قطع Phase 9.3 — لا توجد مفاتيح Spatie مسطّحة في الـ payload.
        $this->assertNull(
            $response->json('user.permissions'),
            'permissions[] الـ flat القديم يجب أن يكون محذوفاً من الـ payload',
        );

        // كل العناصر في capabilities[] بجب أن تكون بصيغة module.action.
        foreach ($capabilities as $cap) {
            $this->assertMatchesRegularExpression(
                '/^[a-z_]+\.[a-z_]+$/',
                $cap,
                "capability '{$cap}' ليس بصيغة module.action موحّدة",
            );
        }
    }

    // ====================================================
    // Helpers
    // ====================================================

    /**
     * Expand the retired granular boolean flags into permissions[] entries,
     * mirroring the Phase 3 (ADR-UNIFIED-ROLE-ACCESS) backfill semantics:
     * each flag grants every Capability::all() entry whose action suffix
     * (the part after the last '.') matches, across ALL modules.
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
            $permissions[] = Capability::OVR_CONFIDENTIAL;
        }

        return array_values(array_unique($permissions));
    }

    private function makeUser(string $role, ?int $orgId = null, ?int $deptId = null): User
    {
        $user = User::factory()->create([
            'organization_id' => $orgId ?? $this->orgA->id,
            'department_id' => $deptId ?? $this->deptA->id,
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function makeConfidentialReport(?int $reporterId = null): IncidentReport
    {
        $reporter = $reporterId
            ? User::findOrFail($reporterId)
            : $this->makeUser('viewer');

        return IncidentReport::create([
            'organization_id' => $this->orgA->id,
            'reporter_id' => $reporter->id,
            'reporter_name' => $reporter->name,
            'reporter_email' => $reporter->email,
            'reporter_department_id' => $this->deptA->id,
            'incident_datetime' => now(),
            'is_patient_related' => false,
            'informed_authority' => false,
            'incident_type_id' => $this->incidentType->id,
            'incident_description' => 'confidential test report',
            'immediate_action_required' => false,
            'severity_level' => SeverityLevel::High,
            'status' => ReportStatus::New,
            'is_confidential' => true,
        ]);
    }

    private function grantScopedRole(User $user, string $role, string $scopeType, int $scopeId, bool $inherit = false): void
    {
        $exists = DB::table('model_has_scoped_roles')
            ->where('user_id', $user->id)
            ->where('role', $role)
            ->where('scope_type', $scopeType)
            ->where('scope_id', $scopeId)
            ->exists();

        if (! $exists) {
            DB::table('model_has_scoped_roles')->insert([
                'user_id' => $user->id,
                'role' => $role,
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
                'inherit_to_children' => $inherit,
                'granted_by' => null,
                'expires_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Cache::forget("role_def_{$scopeType}_{$role}");
        Cache::forget("roles_for_type_{$scopeType}");
        Cache::forget('scope_types_active');
    }

    private function seedEngineDefinitions(): void
    {
        $now = now();
        $types = [
            ['key' => 'organization', 'label_ar' => 'المؤسسة', 'label_en' => 'Organization',
                'model_class' => 'App\\Modules\\Core\\Models\\Organization', 'supports_hierarchy' => false, 'sort_order' => 1],
            ['key' => 'project', 'label_ar' => 'مشروع', 'label_en' => 'Project',
                'model_class' => 'App\\Modules\\Projects\\Models\\Project', 'supports_hierarchy' => true, 'sort_order' => 5],
            ['key' => 'department', 'label_ar' => 'قسم', 'label_en' => 'Department',
                'model_class' => 'App\\Modules\\HR\\Models\\Department', 'supports_hierarchy' => true, 'sort_order' => 3],
            ['key' => 'program', 'label_ar' => 'برنامج', 'label_en' => 'Program',
                'model_class' => 'App\\Modules\\Strategy\\Models\\Program', 'supports_hierarchy' => true, 'sort_order' => 20],
            ['key' => 'portfolio', 'label_ar' => 'محفظة', 'label_en' => 'Portfolio',
                'model_class' => 'App\\Modules\\Strategy\\Models\\Portfolio', 'supports_hierarchy' => false, 'sort_order' => 10],
        ];

        foreach ($types as $typeData) {
            if (! DB::table('scope_types')->where('key', $typeData['key'])->exists()) {
                DB::table('scope_types')->insert(array_merge($typeData, [
                    'icon' => null, 'color' => 'primary', 'supports_expiry' => false,
                    'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
                ]));
            }
        }

        $roleDefinitions = [
            ['type_key' => 'organization', 'role_key' => 'admin', 'name_prefix' => 'organization',
                'label_ar' => 'مدير إدارة', 'label_en' => 'Admin', 'is_admin_role' => true,
                'flags' => ['can_manage_members' => true, 'can_edit' => true, 'can_delete' => true, 'can_view_all' => true, 'can_view_confidential' => false],
                'permissions' => [
                    Capability::PROJECTS_VIEW, Capability::PROJECTS_EDIT, Capability::PROJECTS_DELETE,
                    Capability::TASKS_VIEW, Capability::TASKS_EDIT, Capability::TASKS_DELETE,
                    Capability::RISKS_VIEW, Capability::RISKS_EDIT, Capability::RISKS_DELETE,
                    Capability::OVR_VIEW, Capability::OVR_VIEW_ALL, Capability::OVR_EDIT, Capability::OVR_DELETE,
                    Capability::STRATEGY_VIEW, Capability::STRATEGY_EDIT, Capability::STRATEGY_DELETE,
                    Capability::DEPARTMENTS_VIEW, Capability::DEPARTMENTS_EDIT,
                    Capability::HR_VIEW, Capability::HR_EDIT,
                    Capability::ROLES_VIEW, Capability::USERS_VIEW, Capability::SETTINGS_VIEW, Capability::SETTINGS_MANAGE,
                ], 'sort_order' => 10],
            ['type_key' => 'organization', 'role_key' => 'viewer', 'name_prefix' => 'organization',
                'label_ar' => 'مشاهد', 'label_en' => 'Viewer', 'is_admin_role' => false,
                'flags' => ['can_manage_members' => false, 'can_edit' => false, 'can_delete' => false, 'can_view_all' => false, 'can_view_confidential' => false],
                'permissions' => [
                    Capability::PROJECTS_VIEW, Capability::TASKS_VIEW, Capability::RISKS_VIEW,
                    Capability::OVR_VIEW, Capability::OVR_CREATE,
                    Capability::STRATEGY_VIEW, Capability::DEPARTMENTS_VIEW, Capability::HR_VIEW,
                ], 'sort_order' => 30],
            ['type_key' => 'project', 'role_key' => 'manager', 'name_prefix' => 'project',
                'label_ar' => 'مدير المشروع', 'label_en' => 'Project Manager', 'is_admin_role' => true,
                'flags' => ['can_manage_members' => true, 'can_edit' => true, 'can_delete' => false, 'can_view_all' => true, 'can_view_confidential' => false],
                'permissions' => [Capability::PROJECTS_VIEW, Capability::PROJECTS_EDIT, Capability::PROJECTS_MANAGE_MEMBERS],
                'sort_order' => 1],
            ['type_key' => 'project', 'role_key' => 'member', 'name_prefix' => 'project',
                'label_ar' => 'عضو', 'label_en' => 'Member', 'is_admin_role' => false,
                'flags' => ['can_manage_members' => false, 'can_edit' => false, 'can_delete' => false, 'can_view_all' => true, 'can_view_confidential' => false],
                'permissions' => [Capability::PROJECTS_VIEW], 'sort_order' => 2],
            ['type_key' => 'program', 'role_key' => 'owner', 'name_prefix' => 'program',
                'label_ar' => 'المالك', 'label_en' => 'Owner', 'is_admin_role' => true,
                'flags' => ['can_manage_members' => true, 'can_edit' => true, 'can_delete' => true, 'can_view_all' => true, 'can_view_confidential' => false],
                'permissions' => [
                    Capability::STRATEGY_VIEW, Capability::STRATEGY_EDIT, Capability::STRATEGY_DELETE,
                    Capability::STRATEGY_MANAGE_PROJECTS, Capability::STRATEGY_CHANGE_STATUS,
                ], 'sort_order' => 10],
            ['type_key' => 'program', 'role_key' => 'program_manager', 'name_prefix' => 'program',
                'label_ar' => 'مدير البرنامج', 'label_en' => 'Program Manager', 'is_admin_role' => false,
                'flags' => ['can_manage_members' => true, 'can_edit' => true, 'can_delete' => false, 'can_view_all' => true, 'can_view_confidential' => false],
                'permissions' => [
                    Capability::STRATEGY_VIEW, Capability::STRATEGY_EDIT,
                    Capability::STRATEGY_CHANGE_STATUS, Capability::STRATEGY_MANAGE_PROJECTS,
                ], 'sort_order' => 20],
            ['type_key' => 'program', 'role_key' => 'executive_sponsor', 'name_prefix' => 'program',
                'label_ar' => 'الراعي التنفيذي', 'label_en' => 'Executive Sponsor', 'is_admin_role' => false,
                'flags' => ['can_manage_members' => false, 'can_edit' => false, 'can_delete' => false, 'can_view_all' => true, 'can_view_confidential' => false],
                'permissions' => [
                    Capability::STRATEGY_VIEW, Capability::STRATEGY_MANAGE_PRIORITY, Capability::STRATEGY_CHANGE_STATUS,
                ], 'sort_order' => 30],
            ['type_key' => 'portfolio', 'role_key' => 'owner', 'name_prefix' => 'portfolio',
                'label_ar' => 'مالك المحفظة', 'label_en' => 'Portfolio Owner', 'is_admin_role' => true,
                'flags' => ['can_manage_members' => true, 'can_edit' => true, 'can_delete' => true, 'can_view_all' => true, 'can_view_confidential' => false],
                'permissions' => [
                    Capability::STRATEGY_VIEW, Capability::STRATEGY_EDIT, Capability::STRATEGY_DELETE,
                    Capability::STRATEGY_MANAGE_PROJECTS, Capability::STRATEGY_ASSIGN_OWNER,
                ], 'sort_order' => 10],
        ];

        foreach ($roleDefinitions as $def) {
            $scopeType = DB::table('scope_types')->where('key', $def['type_key'])->first();
            if (! $scopeType) {
                continue;
            }

            if (! DB::table('scoped_role_definitions')
                ->where('scope_type_id', $scopeType->id)
                ->where('role_key', $def['role_key'])
                ->exists()) {
                DB::table('scoped_role_definitions')->insert([
                    'name' => $def['name_prefix'].'.'.$def['role_key'],
                    'display_name' => $def['label_ar'],
                    'scope_type' => $def['type_key'],
                    'scope_type_id' => $scopeType->id,
                    'role_key' => $def['role_key'],
                    'label_ar' => $def['label_ar'],
                    'label_en' => $def['label_en'],
                    'description' => null,
                    'color' => 'primary',
                    'permissions' => json_encode($this->expandFlags($def['permissions'], $def['flags'])),
                    'is_admin_role' => $def['is_admin_role'],
                    'is_active' => true,
                    'sort_order' => $def['sort_order'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        Cache::flush();
    }
}
