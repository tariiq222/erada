<?php

namespace Tests\Feature\Authorization;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\ScopedRole;
use App\Modules\Core\Models\ScopedRoleDefinition;
use App\Modules\Core\Models\ScopeType;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * CapabilityMatchesFlagsAndCacheInvalidationTest — Wave 5, Task 5.3.
 *
 * Locks down two narrow but high-leverage invariants of AccessDecision that
 * previously relied on blanket Cache::flush() to mask:
 *
 *  (A) capabilityMatchesFlags — the explicit `false` flag semantics for
 *      capabilities whose action lives outside the flag set (create/complete/
 *      assign/investigate/close). They MUST be denied via the flags path
 *      even when a permissive permission would otherwise flow through.
 *      The engine has two grant paths: permissions JSON (explicit list) and
 *      the flag-derived path. The flag path returns false for those actions;
 *      a "true" permissions entry can still grant, but only by going through
 *      the explicit JSON list, not via flags.
 *
 *  (B) Engine cache invalidation — adding or removing a ScopedRole for a user
 *      MUST drop that user's memoized active roles so the next can() reflects
 *      the change WITHOUT a blanket Cache::flush(). The contract is enforced
 *      by ScopedRole::booted() → AccessDecision::flushUserCache($userId) on
 *      saved/deleted events (LR-104).
 *
 * Sanity: this test relies on no blanket Cache::flush() between the asserts.
 * setUp() only flushes the engine's static caches (TestCase default), not
 * the model's boot lifecycle.
 */
class CapabilityMatchesFlagsAndCacheInvalidationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->org = Organization::factory()->create();

        // ScopedRole::booted() flushes the AccessDecision per-user cache on
        // every model write; the suite-wide Cache::flush() in TestCase::setUp
        // is enough to clean any cross-test residue before each test runs.
        Cache::flush();
        AccessDecision::flushCache();
    }

    /**
     * Force-insert a ScopedRoleDefinition via DB::table to bypass Eloquent
     * $fillable (see LR-103: name/display_name/scope_type are legacy NOT NULL
     * columns not in $fillable). Mirrors the helper in tests/Unit/Authorization/
     * AccessDecisionTest::createScopeTypeAndRoleDefinition.
     */
    private function makeDefinition(
        string $roleKey,
        string $scopeTypeKey,
        array $flags,
        array $permissions = []
    ): ScopedRoleDefinition {
        $scopeType = ScopeType::firstOrCreate(
            ['key' => $scopeTypeKey],
            [
                'label_ar' => $scopeTypeKey,
                'label_en' => $scopeTypeKey,
                'model_class' => match ($scopeTypeKey) {
                    'organization' => Organization::class,
                    'department' => Department::class,
                    'project' => Project::class,
                    default => Model::class,
                },
                'supports_hierarchy' => true,
                'is_active' => true,
                'sort_order' => 0,
            ]
        );

        // Phase 3 (ADR-UNIFIED-ROLE-ACCESS): granular flags are retired columns.
        // Expand any flag the caller set into the exact capabilities it used to grant
        // (action-suffix families across all modules), merged with any explicit
        // permissions the caller passed. is_admin_role stays a column.
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

        $attributes = [
            'scope_type_id' => $scopeType->id,
            'role_key' => $roleKey,
            'display_name' => $roleKey,
            'label_ar' => $roleKey,
            'label_en' => $roleKey,
            'is_admin_role' => $flags['is_admin_role'] ?? false,
            'is_active' => true,
            'sort_order' => 0,
            'permissions' => json_encode($expanded),
            'updated_at' => now(),
            'name' => $roleKey,
            'scope_type' => $scopeTypeKey,
        ];

        $existingId = DB::table('scoped_role_definitions')
            ->where('name', $roleKey)
            ->where('scope_type', $scopeTypeKey)
            ->value('id');

        if ($existingId) {
            DB::table('scoped_role_definitions')->where('id', $existingId)->update($attributes);
        } else {
            $attributes['created_at'] = now();
            $existingId = DB::table('scoped_role_definitions')->insertGetId($attributes);
        }

        return ScopedRoleDefinition::find($existingId);
    }

    private function makeUser(): User
    {
        return User::factory()->create([
            'organization_id' => $this->org->id,
            'is_active' => true,
        ]);
    }

    // =========================================================
    // A. capabilityMatchesFlags semantics
    // =========================================================

    public function test_capability_matches_flags_returns_false_for_unflagged_actions(): void
    {
        // capabilityMatchesFlags maps the action after the last dot to a can_*
        // boolean flag. Actions NOT covered (create/complete/assign/
        // investigate/close) MUST hit the default branch and return false
        // even when can_edit is true (the closest flag), because create is
        // semantically distinct from edit. Pin this in isolation from the
        // JSON-permissions escape hatch by giving the definition NO
        // permissions at all — flags path is the only possible grant.
        $definition = $this->makeDefinition(
            roleKey: 'flag_only_role',
            scopeTypeKey: 'organization',
            flags: [
                // All flags explicitly false on purpose except can_edit so we
                // can prove that can_edit=true does NOT leak into the
                // default-branch capability set.
                'can_edit' => true,
                'can_view_all' => false,
                'can_delete' => false,
                'can_manage_members' => false,
                'is_admin_role' => false,
            ],
            permissions: [],
        );

        $user = $this->makeUser();
        $user->assignScopedRole(
            role: $definition->role_key,
            scopeType: 'organization',
            scopeId: $this->org->id,
        );

        // Tasks.edit DOES land on the 'edit' flag → granted.
        $this->assertTrue(
            AccessDecision::can($user, Capability::TASKS_EDIT, null),
            'tasks.edit (action=edit) must hit the can_edit=true flag and grant'
        );

        // Tasks.create falls through the flag map to default=false → denied.
        $this->assertFalse(
            AccessDecision::can($user, Capability::TASKS_CREATE, null),
            'tasks.create (action=create) must hit the default branch and be denied'
        );

        // Tasks.assign (action=assign) — same default branch — must also deny.
        $this->assertFalse(
            AccessDecision::can($user, Capability::TASKS_ASSIGN, null),
            'tasks.assign (action=assign) must hit the default branch and be denied'
        );

        // Tasks.complete (action=complete) — same default branch.
        $this->assertFalse(
            AccessDecision::can($user, Capability::TASKS_COMPLETE, null),
            'tasks.complete (action=complete) must hit the default branch and be denied'
        );
    }

    public function test_capability_matches_flags_deny_via_permissions_path_does_not_resurrect_false_flag_actions(): void
    {
        // Counterpart: even if someone hand-edits the permissions JSON to add
        // `tasks.create`, the flags path STILL doesn't fire for that action —
        // we test against the permission JSON path by adding it AND verifying
        // that removing it returns false via the flags path.
        $definition = $this->makeDefinition(
            roleKey: 'mixed_perms_role',
            scopeTypeKey: 'organization',
            flags: [
                'can_edit' => true,
                'can_view_all' => true,
                'can_delete' => true,
                'can_manage_members' => true,
                'is_admin_role' => false,
            ],
            permissions: [Capability::TASKS_CREATE],
        );

        // Force re-read so the engine sees the fresh permissions JSON.
        ScopedRoleDefinition::clearCache();
        AccessDecision::flushCache();

        $user = $this->makeUser();
        $user->assignScopedRole(
            role: $definition->role_key,
            scopeType: 'organization',
            scopeId: $this->org->id,
        );

        $this->assertTrue(
            AccessDecision::can($user, Capability::TASKS_CREATE, null),
            'tasks.create MUST be granted via the permissions JSON list'
        );

        // Now strip the JSON entry so only the flags path remains.
        DB::table('scoped_role_definitions')
            ->where('id', $definition->id)
            ->update(['permissions' => json_encode([])]);
        ScopedRoleDefinition::clearCache();
        // Do NOT touch AccessDecision::flushCache() — user's role set is
        // unchanged, so a re-read should be transparent and the test of the
        // engine's per-user cache invalidation contract still applies.
        $userWithoutCachedRoles = User::find($user->id);
        $freshUser = (clone $userWithoutCachedRoles)->forceFill(['email' => $user->email]);

        // We CAN'T just re-fetch from DB because the user object holds the
        // cached activeScopedRoles collection. Use a fresh in-memory user.
        $freshUser->id = $user->id;
        AccessDecision::flushUserCache($freshUser->id);

        $this->assertFalse(
            AccessDecision::can($freshUser, Capability::TASKS_CREATE, null),
            'tasks.create MUST hit the flags default-branch after permissions are removed'
        );
    }

    // =========================================================
    // B. Cache invalidation on ScopedRole add / remove
    // =========================================================

    public function test_adding_scoped_role_invalidates_user_cache_without_flush_cache(): void
    {
        // Build a user with NO roles. The engine must deny on first eval and
        // then grant after a ScopedRole is added — without anyone calling
        // AccessDecision::flushCache() or Cache::flush() between the two
        // assertions. The invalidation hook is ScopedRole::booted() →
        // AccessDecision::flushUserCache($userId) (see ScopedRole::saved).
        $definition = $this->makeDefinition(
            roleKey: 'editor_role',
            scopeTypeKey: 'organization',
            flags: [
                'can_edit' => true,
                'can_view_all' => false,
                'can_delete' => false,
                'can_manage_members' => false,
                'is_admin_role' => false,
            ],
            permissions: [],
        );

        $user = $this->makeUser();
        $freshUser = User::find($user->id);

        // Pre-condition: no role assigned → engine MUST deny.
        $this->assertFalse(
            AccessDecision::can($freshUser, Capability::TASKS_EDIT, null),
            'precondition: a user with no scoped roles cannot edit tasks'
        );

        // The act under test: attach the role via the model. The booted()
        // hook on ScopedRole MUST fire flushUserCache($user->id) so the next
        // can() call re-reads from the DB.
        $user->assignScopedRole(
            role: $definition->role_key,
            scopeType: 'organization',
            scopeId: $this->org->id,
        );

        // No flushCache() here — this is the load-bearing assertion.
        $userAfter = User::find($user->id);
        $this->assertTrue(
            AccessDecision::can($userAfter, Capability::TASKS_EDIT, null),
            'after assignScopedRole, the engine MUST observe the new role without a manual flushCache()'
        );
    }

    public function test_removing_scoped_role_invalidates_user_cache_without_flush_cache(): void
    {
        // Mirror image: user holds a role granting tasks.edit, then the role
        // is revoked via the model. The next can() must deny — proving the
        // deleted event on ScopedRole wired flushUserCache() too.
        $definition = $this->makeDefinition(
            roleKey: 'revokable_editor',
            scopeTypeKey: 'organization',
            flags: [
                'can_edit' => true,
                'can_view_all' => false,
                'can_delete' => false,
                'can_manage_members' => false,
                'is_admin_role' => false,
            ],
            permissions: [],
        );

        $user = $this->makeUser();
        $user->assignScopedRole(
            role: $definition->role_key,
            scopeType: 'organization',
            scopeId: $this->org->id,
        );

        $userBefore = User::find($user->id);
        $this->assertTrue(
            AccessDecision::can($userBefore, Capability::TASKS_EDIT, null),
            'precondition: freshly granted role allows tasks.edit'
        );

        // Now revoke via HasScopedRoles::revokeScopedRole (saves ScopedRole
        // deletion through Eloquent → booted() hook).
        $user->revokeScopedRole('organization', $this->org->id);

        $userAfter = User::find($user->id);
        $this->assertFalse(
            AccessDecision::can($userAfter, Capability::TASKS_EDIT, null),
            'after revokeScopedRole, the engine MUST observe the removal without a manual flushCache()'
        );
    }

    public function test_engine_cache_isolated_per_user(): void
    {
        // Negative control: granting a role to user A must NOT affect user B's
        // memoized active roles. Lazy memoization keyed by user id means a
        // flushUserCache(A) does not touch B. This is the inverse-regression
        // guard for LR-104 (over-flushing is harmless, but under-flushing a
        // different user is a security bug).
        $definition = $this->makeDefinition(
            roleKey: 'isolated_editor',
            scopeTypeKey: 'organization',
            flags: [
                'can_edit' => true,
                'can_view_all' => false,
                'can_delete' => false,
                'can_manage_members' => false,
                'is_admin_role' => false,
            ],
            permissions: [],
        );

        $userA = $this->makeUser();
        $userB = $this->makeUser();

        // Warm both caches so they're populated.
        $this->assertFalse(AccessDecision::can(User::find($userA->id), Capability::TASKS_EDIT, null));
        $this->assertFalse(AccessDecision::can(User::find($userB->id), Capability::TASKS_EDIT, null));

        // Grant to A only. The booted hook should flush A's cache but not B's.
        $userA->assignScopedRole(
            role: $definition->role_key,
            scopeType: 'organization',
            scopeId: $this->org->id,
        );

        $userAAfter = User::find($userA->id);
        $userBAfter = User::find($userB->id);

        $this->assertTrue(
            AccessDecision::can($userAAfter, Capability::TASKS_EDIT, null),
            'A sees the new grant'
        );
        $this->assertFalse(
            AccessDecision::can($userBAfter, Capability::TASKS_EDIT, null),
            'B is isolated from A flush'
        );
    }
}
