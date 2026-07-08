<?php

namespace Tests\Support;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\ScopedRoleDefinition;
use App\Modules\Core\Models\ScopeType;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Test helper trait: grants engine-readable capabilities via ScopedRole +
 * ScopedRoleDefinition (NOT flat givePermissionTo — the engine ignores those).
 *
 * Pattern proven in Wave 1's DecisionControllerEngineAuthzTest::grantViaEngine()
 * (see also tests/Unit/Authorization/AccessDecisionTest::createScopeTypeAndRoleDefinition).
 *
 * KEY GOTCHA (LR-103 + AccessDecisionTest): ScopedRoleDefinition's $fillable
 * omits the legacy NOT NULL columns (name, display_name, scope_type). Eloquent's
 * firstOrCreate() silently drops them, so Postgres rejects the insert with
 * SQLSTATE[23502]. We MUST use DB::table() with explicit force-fill.
 *
 * Also: ScopeType must be seeded before scoped_role_definitions because of
 * scope_type_id FK. Default 'organization' scope is seeded here if missing.
 */
trait GrantsEngineCapability
{
    /**
     * Grant one or more capabilities at the org scope by inserting a
     * ScopedRoleDefinition (force-filled via DB::table, NOT Eloquent) and
     * assigning a ScopedRole to the user via the model's assignScopedRole().
     *
     * Pass a single capability string for one role; pass an array of capability
     * strings when a single user needs several capabilities on the same scope
     * (the engine has single-role-per-scope semantics via assignScopedRole, so
     * a separate definition per capability would silently revoke the previous
     * one — passing an array here puts them all in the same definition's
     * permissions JSON and survives the engine's role resolution).
     *
     * @param  string|array<int, string>  $capability  capability key(s) to grant.
     * @param  array<string, mixed>  $definitionFlags  extra definition attributes. Only
     *                                                 is_admin_role survives as a column (Phase 3, ADR-UNIFIED-ROLE-ACCESS). The retired
     *                                                 granular flags (can_edit/can_delete/can_view_all/can_manage_members/can_view_confidential)
     *                                                 are accepted for backward-compatible call sites and expanded into permissions[]
     *                                                 (e.g. ['can_view_confidential' => true] adds the ovr.view_confidential capability).
     */
    protected function grantEngineCapability(
        User $user,
        string|array $capability,
        string $scopeType = 'organization',
        ?int $scopeId = null,
        ?string $roleKey = null,
        array $definitionFlags = [],
        ?array $reach = null
    ): void {
        $capabilities = is_array($capability) ? array_values(array_unique($capability)) : [$capability];
        // Expand any legacy granular flags the caller passed into explicit capabilities.
        $capabilities = array_values(array_unique(array_merge(
            $capabilities,
            $this->expandLegacyFlagsToCapabilities($definitionFlags)
        )));
        $roleKey ??= 'wave3_'.bin2hex(random_bytes(4));
        $scopeId ??= $user->organization_id;

        // Seed ScopeType if absent (FK target for scoped_role_definitions.scope_type_id).
        $scopeTypeRow = ScopeType::firstOrCreate(
            ['key' => $scopeType],
            [
                'label_ar' => $scopeType,
                'label_en' => $scopeType,
                'model_class' => Organization::class,
                'supports_hierarchy' => true,
                'is_active' => true,
                'sort_order' => 0,
            ]
        );

        // Force-fill via DB::table (bypasses $fillable). The legacy NOT NULL
        // columns (name, display_name, scope_type) MUST be present per LR-103.
        // The 'permissions' JSON column is encoded manually because we bypass
        // the model's array cast. Phase 3 (ADR-UNIFIED-ROLE-ACCESS): granular grants
        // live in permissions[]; only is_admin_role remains as a column.
        $attributes = [
            'scope_type_id' => $scopeTypeRow->id,
            'role_key' => $roleKey,
            'name' => $roleKey,
            'display_name' => $roleKey,
            'scope_type' => $scopeType,
            'label_ar' => $roleKey,
            'label_en' => $roleKey,
            'is_admin_role' => $definitionFlags['is_admin_role'] ?? false,
            'is_active' => true,
            'sort_order' => 0,
            'permissions' => json_encode($capabilities),
            'reach' => $reach !== null ? json_encode($reach) : null,
            'updated_at' => now(),
        ];

        // Apply any extra (non-flag) definition attributes the caller passed.
        $retiredFlags = ['can_edit', 'can_delete', 'can_view_all', 'can_manage_members', 'can_view_confidential'];
        foreach ($definitionFlags as $col => $val) {
            if (! array_key_exists($col, $attributes) && ! in_array($col, $retiredFlags, true)) {
                $attributes[$col] = $val;
            }
        }

        $existingId = DB::table('scoped_role_definitions')
            ->where('name', $roleKey)
            ->where('scope_type', $scopeType)
            ->value('id');

        if ($existingId) {
            DB::table('scoped_role_definitions')->where('id', $existingId)->update($attributes);
        } else {
            $attributes['created_at'] = now();
            $existingId = DB::table('scoped_role_definitions')->insertGetId($attributes);
        }

        $roleDefinition = ScopedRoleDefinition::find($existingId);

        $user->assignScopedRole(
            role: $roleDefinition->role_key,
            scopeType: $scopeType,
            scopeId: (int) $scopeId
        );

        // ScopedRoleDefinition::findByKey() caches (scope_type, role_key) -> row
        // in Redis. The trait inserted via DB::table (bypassing model events), so
        // the next AccessDecision::can() would read a stale cached null for this
        // freshly created definition. Drop both cache layers + engine memoization.
        ScopedRoleDefinition::clearCache();
        ScopeType::clearCache();
        AccessDecision::flushCache();
    }

    /**
     * Expand legacy granular flags (passed via $definitionFlags) into the explicit
     * capabilities they used to grant, so old call sites keep working after the flag
     * columns were dropped (Phase 3). is_admin_role is NOT expanded (it stays a column).
     *
     * @param  array<string, mixed>  $definitionFlags
     * @return array<int, string>
     */
    private function expandLegacyFlagsToCapabilities(array $definitionFlags): array
    {
        $byAction = fn (array $actions): array => array_values(array_filter(
            Capability::all(),
            function (string $capability) use ($actions) {
                $action = str_contains($capability, '.')
                    ? substr($capability, strrpos($capability, '.') + 1)
                    : $capability;

                return in_array($action, $actions, true);
            }
        ));

        $out = [];
        if (! empty($definitionFlags['can_edit'])) {
            $out = array_merge($out, $byAction(['edit', 'update']));
        }
        if (! empty($definitionFlags['can_delete'])) {
            $out = array_merge($out, $byAction(['delete', 'remove']));
        }
        if (! empty($definitionFlags['can_view_all'])) {
            $out = array_merge($out, $byAction(['view', 'view_all', 'view_reports']));
        }
        if (! empty($definitionFlags['can_manage_members'])) {
            $out = array_merge($out, $byAction(['manage_members', 'assign_roles']));
        }
        if (! empty($definitionFlags['can_view_confidential'])) {
            // Post Direction B (2026-07-07): the engine now reads the canonical
            // OVR_CONFIDENTIAL key. The legacy Capability::OVR_VIEW_CONFIDENTIAL
            // is kept only as a class-load shim for the already-applied backfill
            // migration (LR-004). Map the test fixture flag to the new key so
            // the resulting scoped role definition actually satisfies the
            // engine's confidential gate.
            $out[] = Capability::OVR_CONFIDENTIAL;
        }

        return $out;
    }
}
