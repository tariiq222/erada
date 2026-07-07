<?php

namespace Tests\Feature\Authorization;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\ScopedRoleDefinition;
use App\Modules\Core\Models\ScopeType;
use App\Modules\Core\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 3 (ADR-UNIFIED-ROLE-ACCESS) grant-equivalence guard.
 *
 * Proves that expressing a definition's granular grants as an explicit permissions[]
 * (the post-change model) yields BYTE-IDENTICAL AccessDecision::can() results to the
 * retired can_* flag path — for a representative matrix of definitions × capabilities.
 *
 * The "reference oracle" (referenceFlagGrant) is a standalone reimplementation of the
 * OLD engine logic (is_admin_role ⇒ all; permissions[] contains it; can_* flag matches
 * the capability's action). We build the definition via the SAME expansion the migration
 * uses (flag → permissions[]) and assert the new engine grants exactly what the oracle
 * says the old flag path granted. Any divergence means Phase 3 changed a user's grants.
 *
 * Includes the critical LR-005 case: a definition that had ONLY flags set (no
 * permissions[]) — e.g. the legacy member/project_manager viewer role — proving the
 * migration expansion preserved its grants.
 */
class GranularFlagsToPermissionsEquivalenceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->org = Organization::factory()->create();
        Cache::flush();
        AccessDecision::flushCache();
    }

    /**
     * Representative definition matrix: each entry is [label, flags, explicit permissions].
     * Covers admin-role, every single flag, flag combinations, permissions-only, and the
     * flags-only-no-permissions case.
     *
     * @return array<string, array{0: array<string,bool>, 1: array<int,string>}>
     */
    private function definitionMatrix(): array
    {
        return [
            'admin_role_grants_all' => [['is_admin_role' => true], []],
            'view_all_only_no_permissions' => [['can_view_all' => true], []],
            'edit_only' => [['can_edit' => true], []],
            'delete_only' => [['can_delete' => true], []],
            'manage_members_only' => [['can_manage_members' => true], []],
            'confidential_only' => [['can_view_confidential' => true], []],
            'edit_and_view_all' => [['can_edit' => true, 'can_view_all' => true], []],
            'all_granular_flags' => [[
                'can_edit' => true, 'can_delete' => true, 'can_view_all' => true,
                'can_manage_members' => true, 'can_view_confidential' => true,
            ], []],
            'permissions_only_no_flags' => [[], [Capability::PROJECTS_CREATE, Capability::TASKS_COMPLETE]],
            'flags_plus_permissions' => [
                ['can_view_all' => true],
                [Capability::PROJECTS_CREATE, Capability::OVR_INVESTIGATE],
            ],
            'no_grant_at_all' => [[], []],
        ];
    }

    /**
     * A cross-module capability sample spanning every action family plus actions that
     * NO flag covers (create/complete/assign/investigate/close), so the oracle's default
     * = deny is exercised too.
     *
     * @return array<int, string>
     */
    private function capabilitySample(): array
    {
        return [
            Capability::PROJECTS_VIEW, Capability::PROJECTS_EDIT, Capability::PROJECTS_DELETE,
            Capability::PROJECTS_CREATE, Capability::PROJECTS_MANAGE_MEMBERS, Capability::PROJECTS_ASSIGN_ROLES,
            Capability::PROJECTS_CHANGE_STATUS, Capability::PROJECTS_CLOSE,
            Capability::TASKS_VIEW, Capability::TASKS_EDIT, Capability::TASKS_DELETE,
            Capability::TASKS_CREATE, Capability::TASKS_COMPLETE, Capability::TASKS_ASSIGN,
            Capability::RISKS_VIEW, Capability::RISKS_VIEW_REPORTS, Capability::RISKS_REASSESS,
            Capability::OVR_VIEW, Capability::OVR_VIEW_ALL, Capability::OVR_INVESTIGATE,
            Capability::OVR_VIEW_CONFIDENTIAL, Capability::OVR_CLOSE,
            Capability::HR_EDIT, Capability::HR_DELETE, Capability::HR_VIEW,
            Capability::STRATEGY_EDIT, Capability::STRATEGY_VIEW,
            Capability::DEPARTMENTS_MANAGE_MEMBERS, Capability::DEPARTMENTS_ASSIGN_ROLES,
            Capability::KPIS_VIEW, Capability::MEETINGS_EDIT, Capability::SURVEYS_DELETE,
        ];
    }

    public function test_permissions_path_grants_exactly_what_flag_path_would_have_granted(): void
    {
        $capabilities = $this->capabilitySample();

        foreach ($this->definitionMatrix() as $label => [$flags, $permissions]) {
            $definition = $this->makeDefinitionViaMigrationExpansion($label, $flags, $permissions);

            $user = User::factory()->create(['organization_id' => $this->org->id]);
            $user->assignScopedRole(
                role: $definition->role_key,
                scopeType: 'organization',
                scopeId: $this->org->id,
            );

            ScopedRoleDefinition::clearCache();
            AccessDecision::flushCache();
            $user = User::find($user->id);

            foreach ($capabilities as $capability) {
                $expected = $this->referenceFlagGrant($flags, $permissions, $capability);
                $actual = AccessDecision::can($user, $capability, null);

                $this->assertSame(
                    $expected,
                    $actual,
                    "Grant divergence for definition [{$label}] capability [{$capability}]: ".
                    'old flag-path oracle='.($expected ? 'true' : 'false').
                    ' but new permissions-path can()='.($actual ? 'true' : 'false')
                );
            }
        }
    }

    public function test_confidential_flag_only_definition_grants_ovr_view_confidential_capability(): void
    {
        // LR-005: prove the migration preserved a confidential-only definition's grant.
        $definition = $this->makeDefinitionViaMigrationExpansion(
            'confidential_preserved',
            ['can_view_confidential' => true],
            [],
        );

        $this->assertContains(
            Capability::OVR_VIEW_CONFIDENTIAL,
            $definition->permissions,
            'A can_view_confidential=true definition must carry ovr.view_confidential in permissions[] after expansion'
        );
    }

    public function test_flags_only_viewer_role_preserves_full_view_grant(): void
    {
        // LR-005: the legacy member/project_manager role had permissions=[] + can_view_all=true.
        // After expansion its permissions[] must include EVERY view-family capability and the
        // engine must grant them all, while still denying non-view actions.
        $definition = $this->makeDefinitionViaMigrationExpansion(
            'legacy_viewer',
            ['can_view_all' => true],
            [],
        );

        $user = User::factory()->create(['organization_id' => $this->org->id]);
        $user->assignScopedRole(
            role: $definition->role_key,
            scopeType: 'organization',
            scopeId: $this->org->id,
        );
        ScopedRoleDefinition::clearCache();
        AccessDecision::flushCache();
        $user = User::find($user->id);

        // Every view-family capability granted.
        foreach ([Capability::PROJECTS_VIEW, Capability::TASKS_VIEW, Capability::OVR_VIEW_ALL, Capability::RISKS_VIEW_REPORTS] as $viewCap) {
            $this->assertTrue(AccessDecision::can($user, $viewCap, null), "viewer must be granted {$viewCap}");
        }
        // A write action must still be denied (view_all never granted edits).
        $this->assertFalse(AccessDecision::can($user, Capability::PROJECTS_EDIT, null), 'viewer must NOT be granted projects.edit');
        $this->assertFalse(AccessDecision::can($user, Capability::TASKS_CREATE, null), 'viewer must NOT be granted tasks.create');
    }

    // =========================================================
    // Reference oracle — the OLD engine flag logic, reimplemented.
    // =========================================================

    /**
     * Standalone reimplementation of the pre-Phase-3 AccessDecision grant logic:
     *   1. is_admin_role ⇒ all
     *   2. exact match in explicit permissions[]
     *   3. can_* flag matches the capability's action suffix
     *      (can_view_confidential ⇒ only ovr.view_confidential)
     *
     * @param  array<string,bool>  $flags
     * @param  array<int,string>  $permissions
     */
    private function referenceFlagGrant(array $flags, array $permissions, string $capability): bool
    {
        if (! empty($flags['is_admin_role'])) {
            return true;
        }

        if (in_array($capability, $permissions, true)) {
            return true;
        }

        if ($capability === Capability::OVR_VIEW_CONFIDENTIAL) {
            return ! empty($flags['can_view_confidential']);
        }

        $action = str_contains($capability, '.')
            ? substr($capability, strrpos($capability, '.') + 1)
            : $capability;

        return match ($action) {
            'edit', 'update' => ! empty($flags['can_edit']),
            'delete', 'remove' => ! empty($flags['can_delete']),
            'view', 'view_all', 'view_reports' => ! empty($flags['can_view_all']),
            'manage_members', 'assign_roles' => ! empty($flags['can_manage_members']),
            default => false,
        };
    }

    /**
     * Build a definition the way the backfill migration would: expand the flags into
     * permissions[] (never writing flag columns — they no longer exist), keeping only
     * is_admin_role. This mirrors 2026_07_01_100001_backfill_granular_flags_into_permissions.
     *
     * @param  array<string,bool>  $flags
     * @param  array<int,string>  $permissions
     */
    private function makeDefinitionViaMigrationExpansion(string $label, array $flags, array $permissions): ScopedRoleDefinition
    {
        $scopeType = ScopeType::firstOrCreate(
            ['key' => 'organization'],
            [
                'label_ar' => 'organization',
                'label_en' => 'organization',
                'model_class' => Organization::class,
                'supports_hierarchy' => true,
                'is_active' => true,
                'sort_order' => 0,
            ]
        );

        $byAction = fn (array $actions): array => array_values(array_filter(
            Capability::all(),
            function (string $capability) use ($actions) {
                $action = str_contains($capability, '.')
                    ? substr($capability, strrpos($capability, '.') + 1)
                    : $capability;

                return in_array($action, $actions, true);
            }
        ));

        $expanded = $permissions;
        if (! empty($flags['can_edit'])) {
            $expanded = array_merge($expanded, $byAction(['edit', 'update']));
        }
        if (! empty($flags['can_delete'])) {
            $expanded = array_merge($expanded, $byAction(['delete', 'remove']));
        }
        if (! empty($flags['can_view_all'])) {
            $expanded = array_merge($expanded, $byAction(['view', 'view_all', 'view_reports']));
        }
        if (! empty($flags['can_manage_members'])) {
            $expanded = array_merge($expanded, $byAction(['manage_members', 'assign_roles']));
        }
        if (! empty($flags['can_view_confidential'])) {
            $expanded[] = Capability::OVR_VIEW_CONFIDENTIAL;
        }
        $expanded = array_values(array_unique($expanded));

        $roleKey = 'equiv_'.$label;
        $attributes = [
            'scope_type_id' => $scopeType->id,
            'role_key' => $roleKey,
            'name' => $roleKey,
            'display_name' => $roleKey,
            'scope_type' => 'organization',
            'label_ar' => $roleKey,
            'label_en' => $roleKey,
            'is_admin_role' => $flags['is_admin_role'] ?? false,
            'is_active' => true,
            'sort_order' => 0,
            'permissions' => json_encode($expanded),
            'updated_at' => now(),
            'created_at' => now(),
        ];

        $id = DB::table('scoped_role_definitions')->insertGetId($attributes);
        ScopedRoleDefinition::clearCache();
        ScopeType::clearCache();

        return ScopedRoleDefinition::find($id);
    }
}
